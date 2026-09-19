<?php
namespace App\Services;

use App\Core\Database;

/**
 * The core reconciliation engine. Address-based comparison of imported
 * (expected) stock vs physical counts, per the spec:
 *   - primary keys: ADDRESS, HANDLING UNIT, QUANTITY
 *   - HU in both, same qty        -> MATCH
 *   - HU in both, different qty   -> QUANTITY_DIFFERENCE
 *   - HU expected, not counted    -> MISSING
 *   - HU counted, not expected    -> UNEXPECTED  (includes unknown-address stock
 *                                                  and HU-NOT-AVAILABLE records,
 *                                                  which cannot be matched by HU)
 */
class ComparisonService
{
    private const EPSILON = 0.0005;

    public static function compareAddress(int $addressId): array
    {
        $expectedRows = StockLookupService::expectedForAddress($addressId);
        $physicalRows = self::activePhysicalForAddress($addressId);

        $expectedByHu = [];
        foreach ($expectedRows as $row) {
            $expectedByHu[$row['hu']] = $row;
        }

        $physicalByHu = [];
        $unmatchedPhysical = []; // HU_NOT_AVAILABLE rows, always unmatched by HU
        foreach ($physicalRows as $row) {
            if (!empty($row['hu_not_available']) || $row['hu'] === null || $row['hu'] === '') {
                $unmatchedPhysical[] = $row;
            } else {
                // last one wins if somehow duplicated; entry UI prevents dup HU scans
                $physicalByHu[$row['hu']] = $row;
            }
        }

        $lines = [];
        $matchCount = 0;
        $issueCount = 0;

        foreach ($expectedByHu as $hu => $exp) {
            if (isset($physicalByHu[$hu])) {
                $phys = $physicalByHu[$hu];
                unset($physicalByHu[$hu]);
                $sameQty = abs((float)$exp['quantity'] - (float)$phys['quantity']) < self::EPSILON;
                $status = $sameQty ? 'MATCH' : 'QUANTITY_DIFFERENCE';
                if ($sameQty) {
                    $matchCount++;
                } else {
                    $issueCount++;
                }
                $lines[] = [
                    'hu' => $hu,
                    'status' => $status,
                    'expected_part_number' => $exp['part_number'],
                    'expected_unit' => $exp['unit'],
                    'expected_quantity' => (float)$exp['quantity'],
                    'physical_part_number' => $phys['part_number'],
                    'physical_unit' => $phys['unit'],
                    'physical_quantity' => (float)$phys['quantity'],
                    'physical_id' => (int)$phys['id'],
                ];
            } else {
                $issueCount++;
                $lines[] = [
                    'hu' => $hu,
                    'status' => 'MISSING',
                    'expected_part_number' => $exp['part_number'],
                    'expected_unit' => $exp['unit'],
                    'expected_quantity' => (float)$exp['quantity'],
                    'physical_part_number' => null,
                    'physical_unit' => null,
                    'physical_quantity' => null,
                    'physical_id' => null,
                ];
            }
        }

        // Remaining physical HUs that had no expected match = UNEXPECTED
        foreach ($physicalByHu as $hu => $phys) {
            $issueCount++;
            $lines[] = [
                'hu' => $hu,
                'status' => 'UNEXPECTED',
                'expected_part_number' => null,
                'expected_unit' => null,
                'expected_quantity' => null,
                'physical_part_number' => $phys['part_number'],
                'physical_unit' => $phys['unit'],
                'physical_quantity' => (float)$phys['quantity'],
                'physical_id' => (int)$phys['id'],
            ];
        }

        // HU-not-available records always land here (can't be HU-matched)
        foreach ($unmatchedPhysical as $phys) {
            $issueCount++;
            $lines[] = [
                'hu' => null,
                'status' => 'UNEXPECTED',
                'expected_part_number' => null,
                'expected_unit' => null,
                'expected_quantity' => null,
                'physical_part_number' => $phys['part_number'],
                'physical_unit' => $phys['unit'],
                'physical_quantity' => (float)$phys['quantity'],
                'physical_id' => (int)$phys['id'],
                'hu_not_available' => true,
            ];
        }

        // Sort: issues first (easier for controller to scan), then matches, alpha by HU
        usort($lines, function ($a, $b) {
            if ($a['status'] === $b['status']) {
                return strcmp((string)$a['hu'], (string)$b['hu']);
            }
            return $a['status'] === 'MATCH' ? 1 : -1;
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

    /** Recompute and persist the address status based on current comparison. */
    public static function recomputeAndSetStatus(int $addressId, string $whenCleanStatus, string $whenIssuesStatus): array
    {
        $result = self::compareAddress($addressId);
        $newStatus = $result['requires_control'] ? $whenIssuesStatus : $whenCleanStatus;
        AddressService::setStatus($addressId, $newStatus);
        return $result;
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
        $hasQtyDiff = false;
        $hasMissing = false;
        $hasUnexpected = false;
        foreach ($comparison['lines'] as $line) {
            if ($line['status'] === 'QUANTITY_DIFFERENCE') $hasQtyDiff = true;
            if ($line['status'] === 'MISSING') $hasMissing = true;
            if ($line['status'] === 'UNEXPECTED') $hasUnexpected = true;
        }
        $parts = [];
        if ($hasMissing || $hasUnexpected) $parts[] = 'HU mismatch';
        if ($hasQtyDiff) $parts[] = 'Quantity mismatch';
        if (!$parts) return 'OK';
        return implode(' / ', $parts);
    }
}
