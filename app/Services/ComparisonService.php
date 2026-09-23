<?php
namespace App\Services;

use App\Core\Database;

/**
 * The core reconciliation engine: address-based comparison of imported SAP
 * stock (expected) vs physical counts. Line statuses:
 *   MATCH               HU in both, same PN and quantity
 *   QUANTITY_DIFFERENCE HU in both, quantity differs      -> adjust qty in SAP
 *   PN_DIFFERENCE       HU in both, part number differs    -> check material
 *   MISSING             SAP HU not found here (found_at set if it was counted elsewhere)
 *   WRONG_LOCATION      HU counted here but SAP has it at another address -> transfer
 *   HU_LABEL_MISSING    no-HU line matching an unfound SAP HU (same PN+qty) -> relabel
 *   UNEXPECTED          counted here, not in SAP at all    -> post found stock
 * Every non-MATCH line carries an `action` telling the SAP corrector what to do.
 */
class ComparisonService
{
    private const EPSILON = 0.0005;

    public static function compareAddress(int $addressId): array
    {
        $address = AddressService::findById($addressId);
        $code = $address['code'] ?? '';
        $expectedRows = StockLookupService::expectedForAddress($addressId);
        $physicalRows = self::activePhysicalForAddress($addressId);

        $expectedByHu = [];
        foreach ($expectedRows as $row) {
            $expectedByHu[(string)$row['hu']] = $row;
        }

        $physicalByHu = [];
        $noHuPhysical = [];
        foreach ($physicalRows as $row) {
            if (!empty($row['hu_not_available']) || $row['hu'] === null || $row['hu'] === '') {
                $noHuPhysical[] = $row;
            } else {
                $physicalByHu[(string)$row['hu']] = $row;
            }
        }

        $lines = [];
        $unmatchedExpected = [];

        foreach ($expectedByHu as $hu => $exp) {
            if (!isset($physicalByHu[$hu])) {
                $unmatchedExpected[$hu] = $exp;
                continue;
            }
            $phys = $physicalByHu[$hu];
            unset($physicalByHu[$hu]);
            $samePn = strcasecmp((string)$exp['part_number'], (string)$phys['part_number']) === 0;
            $sameQty = abs((float)$exp['quantity'] - (float)$phys['quantity']) < self::EPSILON;
            if (!$samePn) {
                $status = 'PN_DIFFERENCE';
                $action = "Part number differs (SAP {$exp['part_number']}, counted {$phys['part_number']}) — check the material and correct in SAP";
            } elseif (!$sameQty) {
                $status = 'QUANTITY_DIFFERENCE';
                $action = self::qtyAction($exp, $phys);
            } else {
                $status = 'MATCH';
                $action = '';
            }
            $lines[] = self::line($hu, $status, $exp, $phys, $action);
        }

        // HU label missing: pair each no-HU line with an unfound SAP HU of the
        // same part number (same quantity first), instead of reporting the same
        // stock twice as MISSING + UNEXPECTED.
        foreach ($noHuPhysical as $i => $phys) {
            $pick = null;
            foreach ($unmatchedExpected as $hu => $exp) {
                if (strcasecmp((string)$exp['part_number'], (string)$phys['part_number']) !== 0) {
                    continue;
                }
                if (abs((float)$exp['quantity'] - (float)$phys['quantity']) < self::EPSILON) {
                    $pick = $hu;
                    break;
                }
                $pick = $pick ?? $hu;
            }
            if ($pick === null) {
                continue;
            }
            $exp = $unmatchedExpected[$pick];
            unset($unmatchedExpected[$pick], $noHuPhysical[$i]);
            $sameQty = abs((float)$exp['quantity'] - (float)$phys['quantity']) < self::EPSILON;
            $line = self::line((string)$pick, $sameQty ? 'HU_LABEL_MISSING' : 'QUANTITY_DIFFERENCE', $exp, $phys,
                $sameQty ? 'Quantity matches SAP — HU label missing: reprint and attach the HU label'
                         : 'HU label missing — ' . self::qtyAction($exp, $phys) . ', then reprint the HU label');
            $line['hu_not_available'] = true;
            $lines[] = $line;
        }

        foreach ($unmatchedExpected as $hu => $exp) {
            $foundAt = self::countedElsewhere((string)$hu, $addressId);
            $line = self::line((string)$hu, 'MISSING', $exp, null, $foundAt
                ? 'Counted at ' . implode(', ', $foundAt) . ' — transfer in SAP (see WRONG_LOCATION there)'
                : 'Not found physically — search, then post the loss in SAP');
            $line['found_at'] = $foundAt ? implode(', ', $foundAt) : null;
            $lines[] = $line;
        }

        foreach ($physicalByHu as $hu => $phys) {
            $sap = StockLookupService::findByHu((string)$hu);
            if ($sap && (int)$sap['address_id'] !== $addressId) {
                $sameQty = abs((float)$sap['quantity'] - (float)$phys['quantity']) < self::EPSILON;
                $action = "Transfer HU in SAP from {$sap['address_code']} to $code";
                if (!$sameQty) {
                    $action .= '; ' . lcfirst(self::qtyAction($sap, $phys));
                }
                $line = self::line((string)$hu, 'WRONG_LOCATION', $sap, $phys, $action);
                $line['sap_address'] = $sap['address_code'];
            } else {
                $line = self::line((string)$hu, 'UNEXPECTED', null, $phys, 'HU not in SAP stock — check and post the found stock at this address in SAP');
            }
            $lines[] = $line;
        }

        foreach ($noHuPhysical as $phys) {
            $line = self::line(null, 'UNEXPECTED', null, $phys, 'Stock without HU label and not in SAP here — check, label and post in SAP');
            $line['hu_not_available'] = true;
            $lines[] = $line;
        }

        $matchCount = 0;
        foreach ($lines as $l) {
            if ($l['status'] === 'MATCH') {
                $matchCount++;
            }
        }
        $issueCount = count($lines) - $matchCount;

        usort($lines, function ($a, $b) {
            if (($a['status'] === 'MATCH') !== ($b['status'] === 'MATCH')) {
                return $a['status'] === 'MATCH' ? 1 : -1;
            }
            return strcmp((string)$a['hu'], (string)$b['hu']);
        });

        return [
            'lines' => $lines,
            'requires_control' => $issueCount > 0,
            'summary' => [
                'match' => $matchCount,
                'issues' => $issueCount,
                'total' => count($lines),
            ],
        ];
    }

    private static function line(?string $hu, string $status, ?array $exp, ?array $phys, string $action): array
    {
        $expQty = $exp !== null ? (float)$exp['quantity'] : null;
        $physQty = $phys !== null ? (float)$phys['quantity'] : null;
        return [
            'hu' => $hu,
            'status' => $status,
            'expected_part_number' => $exp['part_number'] ?? null,
            'expected_unit' => $exp['unit'] ?? null,
            'expected_quantity' => $expQty,
            'physical_part_number' => $phys['part_number'] ?? null,
            'physical_unit' => $phys['unit'] ?? null,
            'physical_quantity' => $physQty,
            'physical_id' => $phys !== null ? (int)$phys['id'] : null,
            'entered_by' => $phys !== null ? (int)$phys['entered_by'] : null,
            'difference' => round(($physQty ?? 0.0) - ($expQty ?? 0.0), 4),
            'sap_address' => null,
            'found_at' => null,
            'hu_not_available' => false,
            'action' => $action,
        ];
    }

    private static function qtyAction(array $exp, array $phys): string
    {
        $diff = (float)$phys['quantity'] - (float)$exp['quantity'];
        return sprintf('Adjust SAP quantity %s → %s %s (%s%s)',
            self::fmt((float)$exp['quantity']), self::fmt((float)$phys['quantity']), $phys['unit'],
            $diff > 0 ? '+' : '', self::fmt($diff));
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    /** Other addresses where this HU is currently counted. */
    private static function countedElsewhere(string $hu, int $addressId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT a.code FROM physical_counts pc INNER JOIN addresses a ON a.id = pc.address_id
             WHERE pc.hu = :hu AND pc.is_deleted = 0 AND pc.address_id <> :aid'
        );
        $stmt->execute([':hu' => $hu, ':aid' => $addressId]);
        return array_column($stmt->fetchAll(), 'code');
    }

    private static function activePhysicalForAddress(int $addressId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM physical_counts WHERE address_id = :aid AND is_deleted = 0 ORDER BY id ASC'
        );
        $stmt->execute([':aid' => $addressId]);
        return $stmt->fetchAll();
    }

    /** One-line discrepancy label for the control queue list. */
    public static function issueLabel(array $comparison): string
    {
        $labels = [
            'QUANTITY_DIFFERENCE' => 'Quantity mismatch',
            'PN_DIFFERENCE' => 'PN mismatch',
            'MISSING' => 'Missing HU',
            'UNEXPECTED' => 'Unexpected stock',
            'WRONG_LOCATION' => 'Wrong location',
            'HU_LABEL_MISSING' => 'HU label missing',
        ];
        $parts = [];
        foreach ($comparison['lines'] as $line) {
            if (isset($labels[$line['status']])) {
                $parts[$labels[$line['status']]] = true;
            }
        }
        return $parts ? implode(' / ', array_keys($parts)) : 'OK';
    }
}
