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

    /**
     * Validates a quantity string against the rules for a given unit.
     * Returns ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateQuantity($rawQuantity, string $unit): array
    {
        if ($rawQuantity === null) {
            return ['valid' => false, 'value' => null, 'error' => 'Quantity is required.'];
        }

        if (is_string($rawQuantity) && trim($rawQuantity) === '') {
            $rawQuantity = 0;
        }

        if (!is_numeric($rawQuantity)) {
            return ['valid' => false, 'value' => null, 'error' => 'Quantity must be a number.'];
        }
        $value = (float)$rawQuantity;

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
