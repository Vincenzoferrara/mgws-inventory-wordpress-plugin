<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mass stock reset: sets every level of a warehouse (or a chosen subset of
 * products) to a target quantity, recording an `adjust` movement for each
 * level and mirroring the net change to WooCommerce.
 *
 * The total belongs to WooCommerce: the net delta per product is committed
 * through MGWS_Woo_Stock, the same way a single adjustment does.
 *
 * With $dry_run the plan is returned without writing anything, so the caller
 * can show what will change before asking for confirmation.
 */
class MGWS_Stock_Reset {
    public static function reset_warehouse($warehouse_id, $target_qty, $product_ids, $user_id, $dry_run = false) {
        $warehouse_id = (int) $warehouse_id;
        $target_qty = max(0, (int) $target_qty);
        $user_id = (int) $user_id;
        if ($warehouse_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid warehouse', array('status' => 400));
        }

        $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        if ($wh_site <= 0) {
            return new WP_Error('mgws_bad_request', 'Warehouse has no site', array('status' => 400));
        }
        // The Unassigned warehouse is the reconciler's: resetting it would
        // move pieces that no shelf claims, and the next sweep would put them back.
        if (class_exists('MGWS_Stock_Reconciler') && MGWS_Stock_Reconciler::is_system_warehouse($warehouse_id)) {
            return new WP_Error('mgws_bad_request', 'The system warehouse cannot be reset', array('status' => 400));
        }

        $levels = MGWS_DB::get_levels_for_warehouse($warehouse_id, 0);
        if (!is_array($levels)) {
            $levels = array();
        }

        // Filter by product_ids if provided.
        $filter = array();
        if (is_array($product_ids)) {
            foreach ($product_ids as $pid) {
                $filter[(int) $pid] = true;
            }
        }
        if (!empty($filter)) {
            $levels = array_values(array_filter($levels, function ($row) use ($filter) {
                return isset($filter[(int) ($row['product_id'] ?? 0)]);
            }));
        }

        $reset = 0;
        $failed = 0;
        $details = array();
        $woo_deltas = array();
        $woo_failed = array();
        $changed_qty = 0;

        foreach ($levels as $level) {
            $product_id = (int) ($level['product_id'] ?? 0);
            $variation_id = (int) ($level['variation_id'] ?? 0);
            $room = (string) ($level['room'] ?? '');
            $rack = (string) ($level['rack'] ?? '');
            $shelf = (string) ($level['shelf'] ?? '');
            $current = (int) ($level['qty'] ?? 0);

            $delta = $target_qty - $current;
            if ($delta === 0) {
                continue;
            }
            $changed_qty += abs($delta);

            if ($dry_run) {
                $reset++;
                $details[] = array('product_id' => $product_id, 'variation_id' => $variation_id, 'ok' => true, 'current_qty' => $current, 'delta' => $delta);
                continue;
            }

            $res = MGWS_DB::upsert_level($warehouse_id, $product_id, $variation_id, $target_qty, $room, $rack, $shelf);
            if (empty($res['ok'])) {
                $failed++;
                $details[] = array('product_id' => $product_id, 'variation_id' => $variation_id, 'ok' => false, 'message' => (string) ($res['message'] ?? 'error'));
                continue;
            }

            MGWS_DB::insert_move('adjust', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $delta, $room, $rack, $shelf, $user_id, 0, 'Mass stock reset', 0, 0, array('value_delta_cents' => $res['value_delta_cents'] ?? null));

            if (!isset($woo_deltas[$product_id])) {
                $woo_deltas[$product_id] = array();
            }
            if (!isset($woo_deltas[$product_id][$variation_id])) {
                $woo_deltas[$product_id][$variation_id] = 0;
            }
            $woo_deltas[$product_id][$variation_id] += $delta;

            $reset++;
            $details[] = array('product_id' => $product_id, 'variation_id' => $variation_id, 'ok' => true, 'current_qty' => $current, 'delta' => $delta);
        }

        // Mirror net changes to WooCommerce (best effort, after the fact).
        foreach ($woo_deltas as $product_id => $variations) {
            foreach ($variations as $variation_id => $delta) {
                if ((int) $delta === 0) {
                    continue;
                }
                $woo = MGWS_Woo_Stock::commit_delta((int) $product_id, (int) $variation_id, (int) $delta);
                if (empty($woo['ok'])) {
                    // The ledger moved, WooCommerce did not: say so, the
                    // reconciliation will close the gap from the Woo side.
                    $woo_failed[] = array('product_id' => (int) $product_id, 'variation_id' => (int) $variation_id, 'delta' => (int) $delta, 'message' => (string) ($woo['message'] ?? ''));
                }
            }
        }

        return array(
            'ok' => true,
            'reset' => $reset,
            'failed' => $failed,
            'target_qty' => $target_qty,
            'dry_run' => (bool) $dry_run,
            'changed_qty' => $changed_qty,
            'woo_failed' => $woo_failed,
            'details' => $details,
        );
    }
}