<?php
namespace App\Services;

/**
 * Handles unit-of-measure normalization and quantity validation per the spec:
 *   - KG, Meter/M, L/Liter  -> decimals allowed
 *   - PCS, Rolls            -> whole numbers only
 * Any unit not recognized is treated conservatively as "whole numbers only"
 * unless it's an obvious decimal unit; admins can extend the list below.
 */
class UnitService
{
    private const DECIMAL_UNITS = [
        'KG' => 'KG',
        'KGS' => 'KG',
        'G' => 'G',
        'M' => 'M',
        'METER' => 'M',
        'METERS' => 'M',
        'MTR' => 'M',
        'L' => 'L',
        'LT' => 'L',
        'LTR' => 'L',
        'LITER' => 'L',
        'LITRE' => 'L',
        'LM' => 'LM',
    ];

    private const WHOLE_UNITS = [
        'PCS' => 'PCS',
        'PCE' => 'PCS',
        'PC' => 'PCS',
        'PIECE' => 'PCS',
        'PIECES' => 'PCS',
        'EA' => 'PCS',
        'EACH' => 'PCS',
        'ST' => 'PCS',
        'STK' => 'PCS',
        'STUECK' => 'PCS',
        'ROLL' => 'ROLLS',
        'ROLLS' => 'ROLLS',
        'UN' => 'PCS',
        'UNIT' => 'PCS',
        'UNITS' => 'PCS',
        'SET' => 'PCS',
        'BOX' => 'PCS',
    ];

    public static function normalize(string $unit): string
    {
        $key = strtoupper(trim($unit));
        if ($key === '') {
            return '';
        }
        if (isset(self::DECIMAL_UNITS[$key])) {
            return self::DECIMAL_UNITS[$key];
        }
        if (isset(self::WHOLE_UNITS[$key])) {
            return self::WHOLE_UNITS[$key];
        }
        return $key;
    }

    public static function allowsDecimal(string $unit): bool
    {
        $key = strtoupper(trim($unit));
        if (isset(self::DECIMAL_UNITS[$key])) {
            return true;
        }
        return false; // unknown or whole-number unit -> integers only
    }

    /** Canonical units that accept decimals (KG, M, L, ...). */
    public static function decimalUnits(): array
    {
        return array_values(array_unique(self::DECIMAL_UNITS));
    }

    /**
     * Parses a typed/imported quantity without ever guessing. Accepts "12",
     * "2,5", "2.5", "0,250", "1.234,5", "1,234.5". Rejects "1.500" / "2,000"
     * (thousands or decimals? — a silent 1000x error in a stock count) and
     * anything that isn't a plain number. Mirrored by parseQuantityText() in
     * common.js — keep both in sync.
     * @return array{value: ?float, error: ?string}
     */
    public static function parseQuantityText(string $text): array
    {
        $v = trim($text);
        if ($v === '') {
            return ['value' => null, 'error' => 'Quantity is required.'];
        }
        if ($v[0] === '-') {
            return ['value' => null, 'error' => 'Quantity cannot be negative.'];
        }
        if (preg_match('/^\d+$/', $v)) {
            return ['value' => (float)$v, 'error' => null];
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $v) && (strpos($v, ',') !== false || substr_count($v, '.') > 1)) {
            return ['value' => (float)str_replace(['.', ','], ['', '.'], $v), 'error' => null];
        }
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $v) && (strpos($v, '.') !== false || substr_count($v, ',') > 1)) {
            return ['value' => (float)str_replace(',', '', $v), 'error' => null];
        }
        if (preg_match('/^(\d+)[.,](\d+)$/', $v, $m)) {
            if (strlen($m[2]) === 3 && preg_match('/^[1-9]\d{0,2}$/', $m[1])) {
                return ['value' => null, 'error' => "\"$v\" is ambiguous (thousands or decimals?). Type it without a thousands separator, e.g. 1500 or 1,5."];
            }
            return ['value' => (float)($m[1] . '.' . $m[2]), 'error' => null];
        }
        return ['value' => null, 'error' => "\"$v\" is not a valid quantity. Use digits only, with one decimal separator if needed (e.g. 12 or 2,5)."];
    }

    public static function parseNumericInput($rawQuantity): ?float
    {
        if (is_int($rawQuantity) || is_float($rawQuantity)) {
            return is_finite((float)$rawQuantity) ? (float)$rawQuantity : null;
        }
        if (!is_string($rawQuantity)) {
            return null;
        }
        return self::parseQuantityText($rawQuantity)['value'];
    }

    /**
     * Validates a quantity against the rules for a given unit.
     * Returns ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateQuantity($rawQuantity, string $unit): array
    {
        if ($rawQuantity === null || $rawQuantity === '') {
            return ['valid' => false, 'value' => null, 'error' => 'Quantity is required.'];
        }

        if (is_int($rawQuantity) || is_float($rawQuantity)) {
            $value = (float)$rawQuantity;
            if (!is_finite($value)) {
                return ['valid' => false, 'value' => null, 'error' => 'Quantity must be a number.'];
            }
        } elseif (is_string($rawQuantity)) {
            $parsed = self::parseQuantityText($rawQuantity);
            if ($parsed['error'] !== null) {
                return ['valid' => false, 'value' => null, 'error' => $parsed['error']];
            }
            $value = $parsed['value'];
        } else {
            return ['valid' => false, 'value' => null, 'error' => 'Quantity must be a number.'];
        }

        if ($value < 0) {
            return ['valid' => false, 'value' => null, 'error' => 'Quantity cannot be negative.'];
        }

        if (!self::allowsDecimal($unit)) {
            if (abs($value - round($value)) > 0.0000001) {
                return [
                    'valid' => false,
                    'value' => null,
                    'error' => "Quantity for unit \"$unit\" must be a whole number (no decimals).",
                ];
            }
            $value = round($value);
        } else {
            $value = round($value, 4);
        }

        return ['valid' => true, 'value' => $value, 'error' => null];
    }
}
