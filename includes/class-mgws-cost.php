<?php
/**
 * Declared costs, stock valuation and the one-way projection to WooCommerce COGS.
 *
 * Two histories stay separate on purpose:
 * - `mg_product_costs` is the *declared* cost: an append-only record of what the
 *   shop says an item costs. Declaring a cost does not move the weighted average.
 * - `mg_stock_moves` holds the *real* loads. Only those feed the average.
 *
 * Money is an integer number of minor units; the scale is captured once from
 * WooCommerce and then kept, so a later change of the price decimals cannot
 * silently rewrite the meaning of the values already stored.
 */
final class MGWS_Cost {
    const MINOR_UNIT_OPTION = 'mgws_cost_minor_unit';
    const BACKFILL_OPTION = 'mgws_cost_last_backfill_gmt';

    /**
     * The scale stored at installation, not the one WooCommerce reports now.
     *
     * @return int
     */
    public static function minor_unit() {
        $stored = get_option(self::MINOR_UNIT_OPTION, '');
        if ($stored !== '' && $stored !== false && $stored !== null) {
            return MGWS_Cost_Plan::clamp_minor_unit((int) $stored);
        }
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        $decimals = MGWS_Cost_Plan::clamp_minor_unit($decimals);
        update_option(self::MINOR_UNIT_OPTION, $decimals, false);
        return $decimals;
    }

    /**
     * True when WooCommerce now uses a different number of decimals than the
     * scale the stored values were written with.
     *
     * @return bool
     */
    public static function minor_unit_mismatch() {
        if (!function_exists('wc_get_price_decimals')) {
            return false;
        }
        return MGWS_Cost_Plan::clamp_minor_unit((int) wc_get_price_decimals()) !== self::minor_unit();
    }

    /**
     * @param mixed $amount
     * @return int|null
     */
    public static function to_cents($amount) {
        return MGWS_Cost_Plan::to_cents($amount, self::minor_unit());
    }

    /**
     * @param int|null $cents
     * @return string|null
     */
    public static function from_cents($cents) {
        return MGWS_Cost_Plan::from_cents($cents, self::minor_unit());
    }

    /**
     * Whether WooCommerce core can receive a COGS projection.
     *
     * Detection is defensive: the feature lives behind a container service that
     * may not exist on older WooCommerce versions, and a shop without it must
     * keep working rather than fatal.
     *
     * @return bool
     */
    public static function cogs_enabled() {
        if (!function_exists('wc_get_container')) {
            return false;
        }
        $class = '\\Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController';
        if (!class_exists($class)) {
            return false;
        }
        try {
            $controller = wc_get_container()->get($class);
            return is_object($controller)
                && method_exists($controller, 'feature_is_enabled')
                && (bool) $controller->feature_is_enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The current declared cost of an item in minor units, parent fallback
     * included.
     *
     * @param int $product_id
     * @param int $variation_id
     * @return int|null
     */
    public static function latest_declared_cents($product_id, $variation_id = 0) {
        $row = MGWS_DB::get_latest_declared_cost((int) $product_id, (int) $variation_id);
        if (!is_array($row) || !isset($row['cost_cents']) || $row['cost_cents'] === null) {
            return null;
        }
        return (int) $row['cost_cents'];
    }

    /**
     * Appends a declared cost and projects it to WooCommerce when possible.
     *
     * @param int         $product_id
     * @param int         $variation_id
     * @param int|null    $cost_cents Null clears the declaration for that date.
     * @param string      $source     manual | backfill_po | backfill_receipt | import
     * @param string|null $note
     * @param int         $user_id
     * @param string|null $valid_from_gmt
     * @param bool        $revalue_stock Also set the value of the stock in hand to this cost.
     * @return array
     */
    public static function declare($product_id, $variation_id, $cost_cents, $source, $note, $user_id, $valid_from_gmt = null, $revalue_stock = false) {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        if ($product_id <= 0) {
            return array('ok' => false, 'message' => 'product_id is required');
        }
        $valid_from = $valid_from_gmt !== null && $valid_from_gmt !== ''
            ? (string) $valid_from_gmt
            : gmdate('Y-m-d H:i:s');

        // The plan is read before the row is written so the note can say whether
        // the stock was actually touched, and so the declaration always exists
        // before its value changes. The opposite order would leave a value with
        // no declaration to explain it.
        $plan = null;
        $revalue_skipped = null;
        if ($revalue_stock) {
            if ($cost_cents === null) {
                $revalue_skipped = 'cost_missing';
            } else {
                $plan = MGWS_DB::revalue_plan($product_id, $variation_id);
            }
        }
        $note_text = $note === null ? null : (string) $note;
        if ($plan !== null && (int) $plan['levels'] > 0) {
            $note_text = trim((string) $note_text . ' | Stock value set to this cost');
        }

        $row_id = MGWS_DB::insert_declared_cost(
            $product_id,
            $variation_id,
            $cost_cents === null ? null : (int) $cost_cents,
            $valid_from,
            (string) $source,
            $note_text,
            (int) $user_id
        );
        if ($row_id <= 0) {
            return array('ok' => false, 'message' => 'Could not store the declared cost');
        }

        $revalued = 0;
        if ($plan !== null && (int) $plan['levels'] > 0) {
            $revalued = MGWS_DB::revalue_apply($product_id, $variation_id, (int) $cost_cents);
        }
        $projection = self::project($product_id, $variation_id, $cost_cents);
        return array(
            'ok' => true,
            'id' => $row_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'cost_cents' => $cost_cents === null ? null : (int) $cost_cents,
            'cost' => self::from_cents($cost_cents),
            'valid_from_gmt' => $valid_from,
            'source' => (string) $source,
            'projected' => (bool) ($projection['projected'] ?? false),
            'cogs_enabled' => (bool) ($projection['cogs_enabled'] ?? false),
            'projection' => $projection,
            'revalued' => (int) $revalued,
            'revalue_qty' => $plan === null ? 0 : (int) $plan['qty'],
            'previous_value_cents' => $plan === null ? null : (int) $plan['value_cents'],
            'value_cents' => $plan === null ? null : (int) $plan['qty'] * (int) $cost_cents,
            'revalue_skipped' => $revalue_skipped,
        );
    }

    /**
     * Writes the declared cost to WooCommerce's product COGS field.
     *
     * Only the declared cost is projected, never the weighted average: the
     * average is a warehouse metric, the product COGS is a product attribute.
     *
     * @param int      $product_id
     * @param int      $variation_id
     * @param int|null $cost_cents
     * @return array
     */
    public static function project($product_id, $variation_id, $cost_cents) {
        $cogs_enabled = self::cogs_enabled();
        if (!$cogs_enabled) {
            return array('projected' => false, 'cogs_enabled' => false, 'reason' => 'cogs_disabled');
        }
        if ($cost_cents === null) {
            return array('projected' => false, 'cogs_enabled' => true, 'reason' => 'cost_missing');
        }
        if ((int) $cost_cents === 0) {
            // WooCommerce stores set_cogs_value(0.0) as null. Zero is a real cost,
            // so projecting it would erase it: skip and say why.
            return array('projected' => false, 'cogs_enabled' => true, 'reason' => 'zero_cost_not_projectable');
        }
        if (!function_exists('wc_get_product')) {
            return array('projected' => false, 'cogs_enabled' => true, 'reason' => 'woocommerce_unavailable');
        }
        $target_id = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
        $product = wc_get_product($target_id);
        if (!$product || !method_exists($product, 'set_cogs_value')) {
            return array('projected' => false, 'cogs_enabled' => true, 'reason' => 'product_unsupported');
        }
        $value = (float) self::from_cents((int) $cost_cents);
        try {
            $product->set_cogs_value($value);
            $product->save();
        } catch (\Throwable $e) {
            return array('projected' => false, 'cogs_enabled' => true, 'reason' => 'save_failed');
        }
        return array('projected' => true, 'cogs_enabled' => true, 'value' => $value);
    }

    /**
     * Backfill step A (declared costs from purchase documents) and step B (seed
     * the value of existing levels).
     *
     * Idempotent: an item that already has a declared cost is never touched, and
     * a level that already has a value is never reseeded.
     *
     * @param int $user_id
     * @return array
     */
    public static function backfill($user_id = 0) {
        $existing = MGWS_DB::existing_declared_items();
        $candidates = MGWS_DB::backfill_cost_candidates();
        $inserted = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            $key = (int) $candidate['product_id'] . ':' . (int) $candidate['variation_id'];
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $cents = self::to_cents($candidate['unit_cost']);
            if ($cents === null || $cents <= 0) {
                $skipped++;
                continue;
            }
            $row_id = MGWS_DB::insert_declared_cost(
                (int) $candidate['product_id'],
                (int) $candidate['variation_id'],
                $cents,
                (string) $candidate['ts'],
                (string) $candidate['source'],
                'Backfilled from an existing purchase document',
                (int) $user_id
            );
            if ($row_id > 0) {
                $existing[$key] = true;
                $inserted++;
            } else {
                $skipped++;
            }
        }

        $seeded = 0;
        $unresolved = 0;
        $cost_cache = array();
        foreach (MGWS_DB::levels_without_value() as $level) {
            $qty = (int) ($level['qty'] ?? 0);
            if ($qty <= 0) {
                MGWS_DB::set_level_value((int) $level['id'], 0);
                $seeded++;
                continue;
            }
            $key = (int) $level['product_id'] . ':' . (int) $level['variation_id'];
            if (!array_key_exists($key, $cost_cache)) {
                $cost_cache[$key] = self::latest_declared_cents((int) $level['product_id'], (int) $level['variation_id']);
            }
            $cents = $cost_cache[$key];
            if ($cents === null) {
                $unresolved++;
                continue;
            }
            MGWS_DB::set_level_value((int) $level['id'], $qty * $cents);
            $seeded++;
        }

        update_option(self::BACKFILL_OPTION, gmdate('Y-m-d H:i:s'), false);

        return array(
            'ok' => true,
            'declared_inserted' => $inserted,
            'declared_skipped' => $skipped,
            'levels_seeded' => $seeded,
            'levels_unresolved' => $unresolved,
            'minor_unit' => self::minor_unit(),
            'cogs_enabled' => self::cogs_enabled(),
            'completed_at_gmt' => get_option(self::BACKFILL_OPTION, ''),
        );
    }

    /**
     * Coverage report: how much of the stock is valued, and how much is not.
     *
     * @return array
     */
    public static function report() {
        $stats = MGWS_DB::cost_report_stats();
        $stats['declared_items'] = MGWS_DB::count_declared_items();
        $stats['minor_unit'] = self::minor_unit();
        $stats['minor_unit_mismatch'] = self::minor_unit_mismatch();
        $stats['cogs_enabled'] = self::cogs_enabled();
        $stats['last_backfill_gmt'] = (string) get_option(self::BACKFILL_OPTION, '');
        $stats['total_value'] = self::from_cents((int) ($stats['total_value_cents'] ?? 0));
        $stats['coverage_ratio'] = ((int) ($stats['qty'] ?? 0)) > 0
            ? round(((int) ($stats['valued_qty'] ?? 0)) / ((int) $stats['qty']), 4)
            : null;
        return $stats;
    }
}
