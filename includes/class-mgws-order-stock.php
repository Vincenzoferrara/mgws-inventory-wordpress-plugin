<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moves the ledger for the orders WooCommerce owns, without writing to them.
 *
 * The total belongs to whoever owns the movement. An online order belongs to
 * WooCommerce: when the order is paid, WooCommerce reduces its own total and
 * the shelves must follow in the same instant, or the shop keeps selling
 * pieces that are already promised to someone else. This class is the bridge:
 * it writes the ledger `out` movement when WooCommerce takes the stock, and
 * the `in` movement when the order is cancelled or refunded.
 *
 * The order is read, never written. Customer orders belong to the storefront:
 * a warehouse plugin that stores its own bookkeeping inside them ends up
 * fighting WooCommerce over order meta, and every status change becomes a
 * write on somebody else's record. The state this class needs is already in
 * `mg_stock_moves`: the movements carry `ref_order_id`, so "the ledger already
 * took these pieces" and "the ledger already gave them back" are facts that
 * can be read instead of flags that must be stored.
 *
 * That also makes the bridge naturally one-shot. `processing` followed by
 * `completed` finds the `out` movement already present and does nothing, and
 * order acceptance cannot take the pieces a third time.
 *
 * Orders the POS creates are skipped: the checkout already wrote both sides
 * in the same request, so there is nothing left to bridge.
 */
final class MGWS_Order_Stock {
    /**
     * Set while a refund is being processed, so the reconciler listener does
     * not record an extra `adjust` movement for the stock WooCommerce
     * restores on its own during `wc_restock_refunded_items`.
     */
    public static bool $refund_in_progress = false;
    /**
     * Statuses where WooCommerce has reduced its own total for the order.
     */
    private const PAID_STATUSES = array('on-hold', 'processing', 'completed');

    /**
     * Registers the bridge on the same hooks WooCommerce uses for its total.
     *
     * Priority 20: WooCommerce runs its own reduction at 10, so the ledger
     * moves after the total is settled and the reconciliation listener sees a
     * finished operation.
     */
    public static function init(): void {
        foreach (self::PAID_STATUSES as $status) {
            add_action('woocommerce_order_status_' . $status, array(__CLASS__, 'on_order_paid'), 20, 2);
        }
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_order_released'), 20, 2);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_order_released'), 20, 2);
        // Partial refunds do not change the order status, so they need their
        // own hook. WooCommerce has already restored its own total by the time
        // this fires; the ledger follows for the refunded quantity only.
        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_order_refunded'), 20, 2);
        // Restock happens before `woocommerce_order_refunded` fires, so the flag
        // must be raised on the filter WooCommerce applies at the very start of
        // `wc_restock_refunded_items`. The handler lowers it again afterwards.
        add_filter('woocommerce_can_restock_refunded_items', array(__CLASS__, 'raise_refund_flag'), 10, 3);
    }

    /**
     * Raises the refund flag before WooCommerce restores stock on its own, so
     * the reconciler listener does not record a second movement.
     */
    public static function raise_refund_flag($can, $order, $refunded_line_items) {
        self::$refund_in_progress = true;
        return $can;
    }

    /**
     * The order is paid: take the pieces from the shelves it will be picked from.
     *
     * @param int           $order_id
     * @param WC_Order|null $order
     */
    public static function on_order_paid($order_id, $order = null): void {
        $order = self::resolve_order($order_id, $order);
        if (!$order || !self::should_bridge($order)) {
            return;
        }

        $moves = MGWS_DB::get_moves_for_order((int) $order_id);
        if (self::has_moves_of_type($moves, 'out')) {
            // The ledger already holds these pieces: either a previous status
            // change took them, or the warehouse screen accepted the order.
            return;
        }

        $shortfall = self::allocate($order, (int) $order_id);
        if ($shortfall > 0 && function_exists('error_log')) {
            error_log(sprintf(
                'MGWS order %d: %d pieces have no shelf; the reconciliation will place them in Unassigned.',
                (int) $order_id,
                $shortfall
            ));
        }
    }

    /**
     * The order is cancelled or refunded: give the pieces back to the shelves.
     *
     * @param int           $order_id
     * @param WC_Order|null $order
     */
    public static function on_order_released($order_id, $order = null): void {
        $order = self::resolve_order($order_id, $order);
        if (!$order) {
            return;
        }

        $moves = MGWS_DB::get_moves_for_order((int) $order_id);
        // Never taken from a shelf: nothing to give back.
        if (!self::has_moves_of_type($moves, 'out')) {
            return;
        }

        self::restore($order, (int) $order_id);
    }

    /**
     * A (possibly partial) refund: give back only the refunded pieces.
     *
     * WooCommerce has already restored its own total. The ledger follows for
     * the refunded quantity, distributed over the shelves that originally held
     * the order, so the per-shelf numbers stay as close to reality as the
     * original allocation allows.
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public static function on_order_refunded($order_id, $refund_id): void {
        self::$refund_in_progress = true;
        register_shutdown_function(static function () {
            MGWS_Order_Stock::$refund_in_progress = false;
        });
        $order = self::resolve_order($order_id, null);
        if (!$order) {
            error_log('MGWS on_order_refunded: order null');
            return;
        }
        $refund = function_exists('wc_get_order') ? wc_get_order((int) $refund_id) : null;
        if (!($refund instanceof WC_Abstract_Order)) {
            error_log('MGWS on_order_refunded: refund not instance: ' . gettype($refund));
            return;
        }

        // The order was never taken from a shelf: nothing to give back.
        $moves = MGWS_DB::get_moves_for_order((int) $order_id);
        if (empty($moves)) {
            error_log('MGWS on_order_refunded: no moves');
            return;
        }

        // Collect the refunded quantity per product/variation.
        $refunded = array();
        foreach ($refund->get_items('line_item') as $item) {
            $pid = (int) $item->get_product_id();
            $vid = (int) $item->get_variation_id();
            $qty = abs((int) $item->get_quantity());
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $key = $pid . ':' . $vid;
            $refunded[$key] = ($refunded[$key] ?? 0) + $qty;
        }
        if (empty($refunded)) {
            return;
        }

        foreach ($refunded as $key => $qty) {
            list($pid, $vid) = array_map('intval', explode(':', $key));
            self::restore_quantity($order_id, $pid, $vid, $qty, $moves);
        }

        self::$refund_in_progress = false;
    }

    /**
     * True when the ledger must follow this order.
     */
    private static function should_bridge(WC_Order $order): bool {
        // The POS wrote both sides inside the checkout request: a second write
        // here would take the pieces twice.
        if ($order->get_created_via() === 'mgws-pos') {
            return false;
        }
        if ($order->get_meta('_mgws_pos_checkout_v1')) {
            return false;
        }
        // Already accepted through the warehouse screen: acceptance wrote the
        // movements on the shelf the operator actually picked from.
        if (self::is_accepted($order)) {
            return false;
        }
        return true;
    }

    /**
     * Whether the warehouse screen already accepted the order.
     *
     * The flag lived in post meta before order meta was used, so both stores
     * are read: an order accepted by an older build must not be taken again.
     */
    public static function is_accepted(WC_Order $order): bool {
        if ((int) $order->get_meta('_mgws_accept_committed') === 1) {
            return true;
        }
        return (int) get_post_meta((int) $order->get_id(), '_mgws_accept_committed', true) === 1;
    }

    /**
     * Whether the order already moved the ledger.
     *
     * Public because the acceptance route needs the same answer without
     * writing a flag on the order.
     */
    public static function already_taken($order_id): bool {
        return self::has_moves_of_type(MGWS_DB::get_moves_for_order((int) $order_id), 'out');
    }

    /**
     * @param array<int,array<string,mixed>> $moves
     */
    private static function has_moves_of_type(array $moves, string $type): bool {
        foreach ($moves as $move) {
            if ((string) ($move['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the allocations for an order and applies them in one transaction.
     *
     * @return int Pieces that no shelf could cover, 0 when the ledger matched.
     */
    private static function allocate(WC_Order $order, int $order_id): int {
        $order_id = $order_id > 0 ? $order_id : (int) $order->get_id();
        $allocations = array();
        $shortfall = 0;

        foreach ($order->get_items('line_item') as $item) {
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $qty = (int) $item->get_quantity();
            if ($product_id <= 0 || $qty <= 0) {
                continue;
            }
            // Products that do not manage stock have no shelf number to move:
            // WooCommerce handles (or ignores) them on its own.
            $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
            if (!$product || !$product->managing_stock()) {
                continue;
            }

            $pick = self::allocate_item($product_id, $variation_id, $qty);
            if (is_wp_error($pick)) {
                $shortfall += $qty;
                if (function_exists('error_log')) {
                    error_log(sprintf(
                        'MGWS order %d: no shelf for product %d (variation %d): %s',
                        $order_id,
                        $product_id,
                        $variation_id,
                        $pick->get_error_message()
                    ));
                }
                continue;
            }
            $allocations = array_merge($allocations, $pick['allocations']);
            $shortfall += $pick['shortfall'];
        }

        if (empty($allocations)) {
            return $shortfall;
        }

        $result = MGWS_DB::commit_order_accept($order_id, 0, $allocations);
        if (empty($result['ok'])) {
            if (function_exists('error_log')) {
                error_log(sprintf(
                    'MGWS order %d: the ledger could not take the stock (%s); the reconciliation will close the gap.',
                    $order_id,
                    (string) ($result['message'] ?? 'unknown error')
                ));
            }
            // Every piece failed to move: report the whole order as shortfall so
            // the caller logs a gap that the sweep can close.
            return array_sum(array_column($allocations, 'use_qty'));
        }

        return $shortfall;
    }

    /**
     * Splits one order line over the shelves that hold it.
     *
     * Real shelves are used before the per-site "Unassigned" warehouse: that
     * warehouse is the sink for differences nobody assigned, not a picking
     * place, so it is the last resort rather than the first.
     *
     * @return array{allocations:array<int,array<string,mixed>>,shortfall:int}|WP_Error
     */
    private static function allocate_item(int $product_id, int $variation_id, int $qty) {
        $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, 0);
        if (empty($levels)) {
            return new WP_Error('mgws_no_levels', 'No location holds this item');
        }

        $usable = array();
        foreach ($levels as $level) {
            if ((int) ($level['qty'] ?? 0) <= 0) {
                continue;
            }
            $usable[] = $level;
        }
        if (empty($usable)) {
            return new WP_Error('mgws_no_stock', 'Every location is empty');
        }

        usort($usable, function ($a, $b) {
            $a_system = (class_exists('MGWS_Stock_Reconciler') && MGWS_Stock_Reconciler::is_system_warehouse((int) ($a['warehouse_id'] ?? 0))) ? 1 : 0;
            $b_system = (class_exists('MGWS_Stock_Reconciler') && MGWS_Stock_Reconciler::is_system_warehouse((int) ($b['warehouse_id'] ?? 0))) ? 1 : 0;
            if ($a_system !== $b_system) {
                return $a_system <=> $b_system;
            }
            $site = (int) ($a['site_id'] ?? 0) <=> (int) ($b['site_id'] ?? 0);
            if ($site !== 0) {
                return $site;
            }
            return (int) ($a['warehouse_id'] ?? 0) <=> (int) ($b['warehouse_id'] ?? 0);
        });

        $remaining = $qty;
        $allocations = array();
        foreach ($usable as $level) {
            if ($remaining <= 0) {
                break;
            }
            $available = (int) ($level['qty'] ?? 0);
            $use = min($remaining, $available);
            if ($use <= 0) {
                continue;
            }
            $allocations[] = array(
                'site_id' => (int) ($level['site_id'] ?? 0),
                'warehouse_id' => (int) ($level['warehouse_id'] ?? 0),
                'product_id' => (int) ($level['product_id'] ?? $product_id),
                'variation_id' => (int) ($level['variation_id'] ?? $variation_id),
                'room' => (string) ($level['room'] ?? ''),
                'rack' => (string) ($level['rack'] ?? ''),
                'shelf' => (string) ($level['shelf'] ?? ''),
                'use_qty' => $use,
                'priority' => 0,
            );
            $remaining -= $use;
        }

        return array('allocations' => $allocations, 'shortfall' => $remaining);
    }

    /**
     * Gives the pieces of a cancelled or refunded order back.
     */
    private static function restore(WC_Order $order, int $order_id): void {
        $order_id = $order_id > 0 ? $order_id : (int) $order->get_id();

        // The order was never taken from a shelf: nothing to give back.
        $moves = MGWS_DB::get_moves_for_order($order_id);
        if (empty($moves)) {
            return;
        }

        // Net per shelf: an `in` movement already gave some pieces back (a
        // partial refund, for example), so a later full cancellation must
        // only return what is still out.
        $net = array();
        $details = array();
        foreach ($moves as $move) {
            $wid = (int) ($move['warehouse_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $key = implode('|', array(
                (int) ($move['site_id'] ?? 0),
                $wid,
                (int) ($move['product_id'] ?? 0),
                (int) ($move['variation_id'] ?? 0),
                (string) ($move['room'] ?? ''),
                (string) ($move['rack'] ?? ''),
                (string) ($move['shelf'] ?? ''),
            ));
            $type = (string) ($move['type'] ?? '');
            if ($type === 'out') {
                $net[$key] = ($net[$key] ?? 0) + (int) ($move['qty'] ?? 0);
            } elseif ($type === 'in') {
                $net[$key] = ($net[$key] ?? 0) - (int) ($move['qty'] ?? 0);
            }
            if (!isset($details[$key])) {
                $details[$key] = array(
                    'site_id' => (int) ($move['site_id'] ?? 0),
                    'warehouse_id' => $wid,
                    'product_id' => (int) ($move['product_id'] ?? 0),
                    'variation_id' => (int) ($move['variation_id'] ?? 0),
                    'room' => (string) ($move['room'] ?? ''),
                    'rack' => (string) ($move['rack'] ?? ''),
                    'shelf' => (string) ($move['shelf'] ?? ''),
                );
            }
        }

        $allocations = array();
        foreach ($net as $key => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $d = $details[$key];
            $allocations[] = array(
                'site_id' => $d['site_id'],
                'warehouse_id' => $d['warehouse_id'],
                'product_id' => $d['product_id'],
                'variation_id' => $d['variation_id'],
                'room' => $d['room'],
                'rack' => $d['rack'],
                'shelf' => $d['shelf'],
                'use_qty' => $qty,
            );
        }

        if (empty($allocations)) {
            return;
        }

        $result = MGWS_DB::commit_order_release($order_id, 0, $allocations);
        if (empty($result['ok']) && function_exists('error_log')) {
            error_log(sprintf(
                'MGWS order %d: the ledger could not give the stock back (%s).',
                $order_id,
                (string) ($result['message'] ?? 'unknown error')
            ));
        }
    }

    /**
     * Gives back $quantity pieces of a product/variation of an order, spread
     * over the shelves that still hold a positive net for that product. Used
     * by partial refunds, where only part of the order comes back.
     */
    private static function restore_quantity(int $order_id, int $product_id, int $variation_id, int $quantity, array $moves): void {
        if ($quantity <= 0) {
            return;
        }

        // Net per shelf for this product/variation.
        $net = array();
        $details = array();
        foreach ($moves as $move) {
            if ((int) ($move['product_id'] ?? 0) !== $product_id) {
                continue;
            }
            if ((int) ($move['variation_id'] ?? 0) !== $variation_id) {
                continue;
            }
            $wid = (int) ($move['warehouse_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $key = implode('|', array(
                (int) ($move['site_id'] ?? 0),
                $wid,
                (string) ($move['room'] ?? ''),
                (string) ($move['rack'] ?? ''),
                (string) ($move['shelf'] ?? ''),
            ));
            $type = (string) ($move['type'] ?? '');
            if ($type === 'out') {
                $net[$key] = ($net[$key] ?? 0) + (int) ($move['qty'] ?? 0);
            } elseif ($type === 'in') {
                $net[$key] = ($net[$key] ?? 0) - (int) ($move['qty'] ?? 0);
            }
            if (!isset($details[$key])) {
                $details[$key] = array(
                    'site_id' => (int) ($move['site_id'] ?? 0),
                    'warehouse_id' => $wid,
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'room' => (string) ($move['room'] ?? ''),
                    'rack' => (string) ($move['rack'] ?? ''),
                    'shelf' => (string) ($move['shelf'] ?? ''),
                );
            }
        }

        // Distribute the refund over shelves with positive net, LIFO by move
        // id (most recently taken first).
        $remaining = $quantity;
        $allocations = array();
        // Sort shelves by their last out-move id descending for LIFO.
        $shelf_order = array_keys($net);
        // Reorder by max move id of out moves per shelf.
        $last_out_id = array();
        foreach ($moves as $move) {
            if ((int) ($move['product_id'] ?? 0) !== $product_id || (int) ($move['variation_id'] ?? 0) !== $variation_id) {
                continue;
            }
            if ((string) ($move['type'] ?? '') !== 'out') {
                continue;
            }
            $wid = (int) ($move['warehouse_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $key = implode('|', array(
                (int) ($move['site_id'] ?? 0),
                $wid,
                (string) ($move['room'] ?? ''),
                (string) ($move['rack'] ?? ''),
                (string) ($move['shelf'] ?? ''),
            ));
            $mid = (int) ($move['id'] ?? 0);
            if (!isset($last_out_id[$key]) || $mid > $last_out_id[$key]) {
                $last_out_id[$key] = $mid;
            }
        }
        usort($shelf_order, function ($a, $b) use ($last_out_id) {
            return ($last_out_id[$b] ?? 0) <=> ($last_out_id[$a] ?? 0);
        });

        foreach ($shelf_order as $key) {
            if ($remaining <= 0) {
                break;
            }
            $avail = (int) ($net[$key] ?? 0);
            if ($avail <= 0) {
                continue;
            }
            $use = min($remaining, $avail);
            if ($use <= 0) {
                continue;
            }
            $d = $details[$key];
            $allocations[] = array(
                'site_id' => $d['site_id'],
                'warehouse_id' => $d['warehouse_id'],
                'product_id' => $d['product_id'],
                'variation_id' => $d['variation_id'],
                'room' => $d['room'],
                'rack' => $d['rack'],
                'shelf' => $d['shelf'],
                'use_qty' => $use,
            );
            $remaining -= $use;
        }

        if (empty($allocations)) {
            return;
        }

        $result = MGWS_DB::commit_order_release($order_id, 0, $allocations);
        if (empty($result['ok']) && function_exists('error_log')) {
            error_log(sprintf(
                'MGWS order %d: partial refund could not be restored (%s).',
                $order_id,
                (string) ($result['message'] ?? 'unknown error')
            ));
        }
    }

    private static function resolve_order($order_id, $order = null): ?WC_Order {
        if ($order instanceof WC_Order) {
            return $order;
        }
        if (!function_exists('wc_get_order')) {
            return null;
        }
        $loaded = wc_get_order((int) $order_id);
        return $loaded instanceof WC_Order ? $loaded : null;
    }
}
