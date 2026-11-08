<?php
/**
 * Cost and stock-value arithmetic, without WordPress.
 *
 * The stock value of a level is derived from its quantity and its cost basis.
 * Keeping the arithmetic here means the rules can be read (and tested) without
 * a database, a product or a transaction; the database applies the same rules in
 * one atomic UPDATE so the quantity and the value never drift apart.
 *
 * Money is always an integer number of minor units (cents). `null` means
 * "unknown", and it is not the same as zero: a product without a cost has no
 * margin, it is not free.
 */
final class MGWS_Cost_Plan {
    /** Largest supported number of decimals. WooCommerce allows up to 6. */
    const MAX_MINOR_UNIT = 6;

    /**
     * Converts a decimal amount (string or number) to minor units.
     *
     * An empty string, a null and anything non-numeric mean "not provided", not
     * zero. The caller decides whether a missing cost is acceptable.
     *
     * @param mixed $amount
     * @param int   $minor_unit
     * @return int|null
     */
    public static function to_cents($amount, $minor_unit = 2) {
        if ($amount === null) {
            return null;
        }
        if (is_string($amount)) {
            $amount = trim($amount);
            if ($amount === '') {
                return null;
            }
        }
        if (!is_numeric($amount)) {
            return null;
        }
        $factor = self::factor($minor_unit);
        return (int) round(((float) $amount) * $factor);
    }

    /**
     * Formats minor units as a decimal string, using the given scale.
     *
     * @param int|null $cents
     * @param int      $minor_unit
     * @return string|null
     */
    public static function from_cents($cents, $minor_unit = 2) {
        if ($cents === null) {
            return null;
        }
        $minor_unit = self::clamp_minor_unit($minor_unit);
        $factor = self::factor($minor_unit);
        return number_format(((int) $cents) / $factor, $minor_unit, '.', '');
    }

    /**
     * The average unit cost implied by a value and a quantity.
     *
     * Returns null when there is no value or no quantity: an average over zero
     * pieces is not zero, it is unknown.
     *
     * @param int|null $value_cents
     * @param int      $qty
     * @return int|null
     */
    public static function average_cents($value_cents, $qty) {
        if ($value_cents === null || (int) $qty <= 0) {
            return null;
        }
        return (int) round(((int) $value_cents) / (int) $qty);
    }

    /**
     * The value of a level after a signed quantity delta.
     *
     * Rules:
     * - a load with an explicit unit cost adds `delta * unit`;
     * - a load without one keeps the current average;
     * - an unload removes the current average and never goes below zero;
     * - if the previous value is unknown it stays unknown, unless the level was
     *   empty and the incoming pieces have an explicit cost.
     *
     * @param int|null $value_cents     Value before the move, null when unknown.
     * @param int      $qty_before      Quantity before the move.
     * @param int      $delta           Signed quantity change.
     * @param int|null $unit_cost_cents Cost of the incoming pieces, null when not given.
     * @return int|null
     */
    public static function value_after_delta($value_cents, $qty_before, $delta, $unit_cost_cents = null) {
        $delta = (int) $delta;
        $qty_before = (int) $qty_before;
        if ($delta === 0) {
            return $value_cents === null ? null : (int) $value_cents;
        }

        if ($value_cents === null) {
            if ($unit_cost_cents !== null && $delta > 0 && $qty_before <= 0) {
                return $delta * (int) $unit_cost_cents;
            }
            return null;
        }

        if ($unit_cost_cents !== null && $delta > 0) {
            return (int) $value_cents + ($delta * (int) $unit_cost_cents);
        }

        $average = self::average_cents($value_cents, $qty_before);
        if ($average === null) {
            return null;
        }
        return max(0, (int) $value_cents + ($average * $delta));
    }

    /**
     * The value of a level after an absolute quantity set.
     *
     * An explicit unit cost re-bases the whole level; without one the current
     * average is kept and scaled to the new quantity.
     *
     * @param int|null $value_cents
     * @param int      $qty_before
     * @param int      $qty_after
     * @param int|null $unit_cost_cents
     * @return int|null
     */
    public static function value_for_quantity($value_cents, $qty_before, $qty_after, $unit_cost_cents = null) {
        $qty_after = (int) $qty_after;
        if ($unit_cost_cents !== null) {
            return $qty_after * (int) $unit_cost_cents;
        }
        if ($qty_after <= 0) {
            return 0;
        }
        $average = self::average_cents($value_cents, $qty_before);
        if ($average === null) {
            return null;
        }
        return $qty_after * $average;
    }

    /**
     * The unit cost a value implies, for reporting.
     *
     * @param int|null $value_cents
     * @param int      $qty
     * @return int|null
     */
    public static function projected_cost($value_cents, $qty) {
        return self::average_cents($value_cents, $qty);
    }

    /**
     * @param int $minor_unit
     * @return int
     */
    public static function clamp_minor_unit($minor_unit) {
        return max(0, min(self::MAX_MINOR_UNIT, (int) $minor_unit));
    }

    /**
     * @param int $minor_unit
     * @return int
     */
    private static function factor($minor_unit) {
        return (int) pow(10, self::clamp_minor_unit($minor_unit));
    }
}
