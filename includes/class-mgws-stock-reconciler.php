<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the MGWS ledger aligned with the stock total that WooCommerce owns.
 *
 * The rule is one line: the total belongs to whoever owns the movement.
 * MGWS owns the movements it starts (quick load, receipt, count, transfer,
 * admin adjustment), so for those it writes the total and WooCommerce follows
 * through `MGWS_Woo_Stock`. Everything else — online orders, the point of sale,
 * returns, cancellations, order acceptance — is written by WooCommerce first,
 * and MGWS only has to align the distribution.
 *
 * This class is the "align" half. It compares the WooCommerce total with the
 * sum of the MGWS levels and, when they differ, records the difference as a
 * movement instead of rewriting the levels in place: a difference that nobody
 * can explain is still a fact, and a fact with a movement behind it can be
 * traced, counted and reversed. Positive differences land in the per-site
 * "Unassigned" warehouse, which is exactly what they are — stock that exists in
 * WooCommerce and that no shelf claims.
 *
 * It runs in three places, on purpose:
 *  - a listener on `set_stock`, so a manual edit in WooCommerce is reflected
 *    immediately;
 *  - a nightly sweep at 03:00, which catches everything the listener could not
 *    see (direct database writes, a failed WooCommerce write, a request that
 *    died halfway);
 *  - a manual REST call, for when someone does not want to wait until 03:00.
 *
 * The listener deliberately ignores the stock changes that WooCommerce makes
 * for its own orders. Those are not a divergence: they are work in progress,
 * because the goods are still on the shelf until the operator picks the order.
 * Draining the ledger at that moment would take the pieces from a shelf nobody
 * chose and then order acceptance would take them a second time. Order
 * acceptance is what closes that gap, and it does so on the shelf the operator
 * actually picked from.
 */
final class MGWS_Stock_Reconciler {
    /**
     * Cron hook for the nightly sweep.
     */
    public const CRON_HOOK = 'mgws_reconcile_stock';

    /**
     * Post meta that marks a warehouse as the per-site sink for unexplained
     * differences. It is a normal warehouse in every other respect.
     */
    public const SYSTEM_META = 'mgws_system';

    /**
     * Local time of the nightly sweep.
     */
    private const CRON_HOUR = 3;

    /**
     * How long a full sweep holds its lock, in seconds.
     */
    private const LOCK_TTL = 900;

    /**
     * True while WooCommerce is reducing or restoring stock for an order.
     *
     * @var bool
     */
    private static int $order_flow = 0;

    /**
     * Warehouse id => whether it is the system warehouse, for the current
     * request only.
     *
     * @var array<int,bool>
     */
    private static array $system_cache = array();

    /**
     * Registers the listener, the cron entry point and the order-flow guard.
     */
    public static function init(): void {
        add_action('woocommerce_product_set_stock', array(__CLASS__, 'on_set_stock'), 10, 1);
        add_action('woocommerce_variation_set_stock', array(__CLASS__, 'on_set_stock'), 10, 1);

        // WooCommerce applies these two filters right before it touches stock
        // for an order, and fires the matching action right after. The pair is
        // the only reliable way to tell "WooCommerce sold something" apart from
        // "someone edited the number", and the difference decides whether the
        // ledger must follow now or wait for the picking.
        add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'enter_order_flow'), 10, 1);
        add_filter('woocommerce_can_restore_order_stock', array(__CLASS__, 'enter_order_flow'), 10, 1);
        add_action('woocommerce_reduce_order_stock', array(__CLASS__, 'leave_order_flow'), 10, 1);
        add_action('woocommerce_restore_order_stock', array(__CLASS__, 'leave_order_flow'), 10, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'reset_order_flow'), PHP_INT_MAX, 4);

        add_action(self::CRON_HOOK, array(__CLASS__, 'on_cron'));
        add_action('init', array(__CLASS__, 'schedule'), 20);
    }

    /**
     * Marks the start of an order stock change and leaves the filter's answer
     * untouched.
     *
     * @param mixed $can_reduce
     * @return mixed
     */
    public static function enter_order_flow($can_reduce) {
        self::$order_flow++;
        return $can_reduce;
    }

    /**
     * Marks the end of an order stock change.
     *
     * @param mixed $order Unused; the action passes the order, the flag does not
     *                     depend on it.
     */
    public static function leave_order_flow($order = null): void {
        if (self::$order_flow > 0) {
            self::$order_flow--;
        }
    }

    /**
     * Safety net: after a status transition the order flow is finished, even
     * when a third-party filter blocked the reduction and the matching
     * `woocommerce_reduce_order_stock` / `woocommerce_restore_order_stock`
     * action never fired (which would otherwise leave the counter up and
     * disable the listener for the rest of the request).
     */
    public static function reset_order_flow($order = null): void {
        self::$order_flow = 0;
    }

    /**
     * Listener for `woocommerce_product_set_stock` and
     * `woocommerce_variation_set_stock`.
     *
     * WooCommerce can fire this twice for one write (once from
     * `wc_update_product_stock()`, once from the `save()` it triggers), so the
     * listener never trusts the event: it recomputes the difference from the
     * current numbers and the second pass finds nothing to do.
     *
     * @param mixed $product Product or variation whose stock was written.
     */
    public static function on_set_stock($product = null): void {
        // MGWS itself is the writer: the ledger is already right, and reacting
        // here would reconcile a number that has just been committed.
        if (MGWS_Woo_Stock::is_applying()) {
            return;
        }
        // A refund is in progress: the bridge owns the ledger change for it,
        // and WooCommerce is restoring its own total on the side. Reacting here
        // would record a second, untracked movement.
        if (class_exists('MGWS_Order_Stock') && MGWS_Order_Stock::$refund_in_progress) {
            return;
        }
        // WooCommerce is selling or restoring for an order: the pieces are
        // still on the shelf until the operator picks them.
        if (self::$order_flow) {
            return;
        }
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $id = (int) $product->get_id();
        if ($id <= 0) {
            return;
        }
        $variation_id = method_exists($product, 'is_type') && $product->is_type('variation') ? $id : 0;
        $product_id = $variation_id > 0 && method_exists($product, 'get_parent_id')
            ? (int) $product->get_parent_id()
            : $id;

        try {
            self::reconcile_item($product_id, $variation_id, 'listener');
        } catch (\Throwable $error) {
            // A listener must never take the request down with it: the nightly
            // sweep exists exactly for the cases this one misses.
            error_log('MGWS reconciliation listener failed: ' . $error->getMessage());
        }
    }

    /**
     * Aligns the MGWS ledger with the WooCommerce total for one item.
     *
     * @param int    $product_id
     * @param int    $variation_id 0 for a simple product or a parent-managed one.
     * @param string $source       Where the call comes from, recorded on the move.
     * @return array{ok:bool,reason?:string,skipped?:string,delta?:int,changed?:bool,woo_total?:int,mgws_total?:int,new_total?:int,warehouse_id?:int,movements?:int}
     */
    public static function reconcile_item(int $product_id, int $variation_id, string $source = 'listener'): array {
        if (!function_exists('wc_get_product')) {
            return array('ok' => false, 'reason' => 'woocommerce_missing');
        }
        if ($product_id <= 0) {
            return array('ok' => false, 'reason' => 'invalid_item');
        }

        $self_id = $variation_id > 0 ? $variation_id : $product_id;
        $product = wc_get_product($self_id);
        if (!$product) {
            // The ledger still holds levels for an item WooCommerce no longer
            // knows. That is a real orphan, but it is not a difference between
            // two totals: there is no total to compare against, so the sweep
            // must not count it as a failure and must not try to write it. It
            // stays visible in the ledger until someone removes it by hand.
            error_log(sprintf(
                'MGWS reconciliation: product %d (variation %d) no longer exists in WooCommerce; levels left untouched.',
                $product_id,
                $variation_id
            ));
            return array('ok' => true, 'skipped' => 'product_not_found');
        }
        if (!$product->managing_stock()) {
            // A product that does not manage stock has no total to compare: the
            // ledger is the only place its quantity lives, so there is nothing
            // to align.
            return array('ok' => true, 'skipped' => 'not_managed');
        }

        $woo_total = $product->get_stock_quantity();
        if ($woo_total === null) {
            return array('ok' => true, 'skipped' => 'no_total');
        }
        $woo_total = (int) $woo_total;

        // A variation can be managed by its parent. When it is, the number in
        // WooCommerce is the product total, and comparing it with the levels of
        // one variation would compare a whole with a part. The unit of
        // reconciliation becomes the parent, and the ledger side becomes every
        // level that belongs to the product family.
        //
        // The same is true from the other side: a variable product that manages
        // its own stock keeps the total, while its variations inherit it, and
        // the pieces are recorded on the variations. Reconciling the parent
        // alone would see those levels as stock that no shelf claims and move
        // them to "Unassigned", so a variable product is always reconciled as a
        // family.
        $owner_id = (int) $product->get_stock_managed_by_id();
        $is_variable_parent = method_exists($product, 'is_type') && $product->is_type('variable');
        $family = ($owner_id > 0 && $owner_id !== $self_id) || $is_variable_parent;
        $item_product_id = $family && $owner_id > 0 ? $owner_id : $product_id;
        $item_variation_id = $family ? 0 : $variation_id;

        $levels = $family
            ? self::levels_for_family($item_product_id)
            : MGWS_DB::get_levels_for_item($item_product_id, $item_variation_id);

        $mgws_total = MGWS_Stock_Plan::sum_levels($levels);

        // `$woo_total` is already known to be a number here, so the delta is one
        // too: the nullable return only matters to callers that read the total
        // themselves.
        $delta = (int) MGWS_Stock_Plan::delta($woo_total, $mgws_total);
        if ($delta === 0) {
            return array(
                'ok' => true,
                'changed' => false,
                'delta' => 0,
                'woo_total' => $woo_total,
                'mgws_total' => $mgws_total,
            );
        }

        $applied = $delta > 0
            ? self::apply_surplus($item_product_id, $item_variation_id, $delta, $levels, $mgws_total, $woo_total, $source)
            : self::apply_shortfall($item_product_id, $item_variation_id, -$delta, $levels, $source);

        if (empty($applied['ok'])) {
            return $applied;
        }

        return array_merge($applied, array(
            'delta' => $delta,
            'changed' => true,
            'woo_total' => $woo_total,
            'mgws_total' => $mgws_total,
        ));
    }

    /**
     * Records stock that exists in WooCommerce and that no shelf claims.
     *
     * It goes to the site's "Unassigned" warehouse: putting it on a real shelf
     * would invent a location, and dropping it would lose the difference.
     *
     * @param array<int,array<string,mixed>> $levels
     * @return array{ok:bool,reason?:string,warehouse_id?:int,movements?:int,new_total?:int}
     */
    private static function apply_surplus(int $product_id, int $variation_id, int $delta, array $levels, int $mgws_total, int $woo_total, string $source): array {
        $site_id = self::resolve_site_id($product_id, $variation_id, $levels);
        if ($site_id <= 0) {
            // Never guess a site: on a shop with several sites, choosing one
            // would move real stock into a place nobody asked for.
            error_log(sprintf(
                'MGWS reconciliation: cannot resolve a site for product %d (variation %d); %d pieces left unaligned.',
                $product_id,
                $variation_id,
                $delta
            ));
            return array('ok' => false, 'reason' => 'site_unresolved');
        }

        $warehouse_id = self::system_warehouse_id($site_id);
        if ($warehouse_id <= 0) {
            error_log(sprintf(
                'MGWS reconciliation: cannot create the unassigned warehouse for site %d.',
                $site_id
            ));
            return array('ok' => false, 'reason' => 'system_warehouse_unavailable');
        }

        $updated = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, $delta, '', '', '');
        if (empty($updated['ok'])) {
            error_log(sprintf(
                'MGWS reconciliation: could not add %d pieces for product %d (variation %d): %s',
                $delta,
                $product_id,
                $variation_id,
                (string) ($updated['message'] ?? 'unknown error')
            ));
            return array('ok' => false, 'reason' => 'level_write_failed');
        }

        $written = MGWS_DB::insert_move(
            'adjust',
            $site_id,
            $warehouse_id,
            $product_id,
            $variation_id,
            $delta,
            '',
            '',
            '',
            0,
            0,
            self::note($mgws_total, $woo_total, $delta, $source),
            0,
            0,
            array(
                'stock_before' => $mgws_total,
                'stock_after' => $mgws_total + $delta,
                'stock_effect' => 'adjust',
                'reason_code' => 'reconciliation',
                'source_type' => 'reconciliation',
                'unit_cost_cents' => $updated['unit_cost_cents'] ?? null,
                'value_delta_cents' => $updated['value_delta_cents'] ?? null,
            )
        );
        if (!$written) {
            // The level is already written: undoing it would leave the ledger
            // further from WooCommerce than it is now. The nightly sweep will
            // find the difference again and record the missing move.
            error_log(sprintf(
                'MGWS reconciliation: level updated but movement not recorded for product %d (variation %d).',
                $product_id,
                $variation_id
            ));
            return array('ok' => false, 'reason' => 'movement_write_failed', 'warehouse_id' => $warehouse_id);
        }

        return array(
            'ok' => true,
            'warehouse_id' => $warehouse_id,
            'movements' => 1,
            'new_total' => $mgws_total + $delta,
        );
    }

    /**
     * Removes stock that the ledger holds and WooCommerce does not.
     *
     * The pieces leave from real shelves, because that is where they are
     * recorded. The system warehouse goes first: it is the sink for differences
     * that were never assigned, so it is the honest place to take an
     * unexplained shortage from before touching stock that has a location.
     *
     * @param array<int,array<string,mixed>> $levels
     * @return array{ok:bool,reason?:string,warehouse_id?:int,movements?:int,new_total?:int}
     */
    private static function apply_shortfall(int $product_id, int $variation_id, int $missing, array $levels, string $source): array {
        $plan = MGWS_Stock_Plan::shortfall_moves($missing, $levels, self::system_warehouse_ids($levels));
        $running = MGWS_Stock_Plan::sum_levels($levels);
        $movements = 0;
        $taken = 0;
        $last_warehouse = 0;

        foreach ($plan['moves'] as $move) {
            $level = $move['level'];
            $take = (int) $move['take'];
            if ($take <= 0) {
                continue;
            }
            $warehouse_id = (int) ($level['warehouse_id'] ?? 0);
            $site_id = (int) ($level['site_id'] ?? MGWS_DB::get_site_id_for_warehouse($warehouse_id));
            if ($warehouse_id <= 0 || $site_id <= 0) {
                // The plan already leaves out levels with no warehouse. What can
                // still land here is a warehouse with no site, and a movement
                // written there would point nowhere.
                continue;
            }
            $room = (string) ($level['room'] ?? '');
            $rack = (string) ($level['rack'] ?? '');
            $shelf = (string) ($level['shelf'] ?? '');
            // A family reconciliation hands over the levels of the parent and of
            // every variation, so the pieces must leave from the item that
            // actually holds them and not from the family head: writing the
            // shortage on the parent would empty a level that is not the one
            // being reduced.
            $level_product_id = (int) ($level['product_id'] ?? $product_id);
            $level_variation_id = (int) ($level['variation_id'] ?? $variation_id);

            $updated = MGWS_DB::apply_delta_level($warehouse_id, $level_product_id, $level_variation_id, -$take, $room, $rack, $shelf);
            if (empty($updated['ok'])) {
                error_log(sprintf(
                    'MGWS reconciliation: could not remove %d pieces from warehouse %d for product %d (variation %d): %s',
                    $take,
                    $warehouse_id,
                    $level_product_id,
                    $level_variation_id,
                    (string) ($updated['message'] ?? 'unknown error')
                ));
                return array('ok' => false, 'reason' => 'level_write_failed', 'movements' => $movements);
            }

            $written = MGWS_DB::insert_move(
                'adjust',
                $site_id,
                $warehouse_id,
                $level_product_id,
                $level_variation_id,
                -$take,
                $room,
                $rack,
                $shelf,
                0,
                0,
                self::note($running, $running - $take, -$take, $source),
                0,
                0,
                array(
                    'stock_before' => $running,
                    'stock_after' => $running - $take,
                    'stock_effect' => 'adjust',
                    'reason_code' => 'reconciliation',
                    'source_type' => 'reconciliation',
                    'unit_cost_cents' => $updated['unit_cost_cents'] ?? null,
                    'value_delta_cents' => $updated['value_delta_cents'] ?? null,
                )
            );
            if (!$written) {
                error_log(sprintf(
                    'MGWS reconciliation: level updated but movement not recorded for product %d (variation %d).',
                    $product_id,
                    $variation_id
                ));
                return array('ok' => false, 'reason' => 'movement_write_failed', 'movements' => $movements);
            }

            $running -= $take;
            $taken += $take;
            $movements++;
            $last_warehouse = $warehouse_id;
        }

        if ($taken < $missing) {
            // Not `$plan['remaining']`: a level the plan wanted to use can still
            // be unwritable (no warehouse, no site), and in that case the plan
            // thinks it covered the shortage while the ledger did not move.
            error_log(sprintf(
                'MGWS reconciliation: product %d (variation %d) is %d pieces above WooCommerce but the ledger holds less; %d left unaligned.',
                $product_id,
                $variation_id,
                $missing,
                $missing - $taken
            ));
            return array('ok' => false, 'reason' => 'insufficient_levels', 'movements' => $movements);
        }

        return array(
            'ok' => true,
            'warehouse_id' => $last_warehouse,
            'movements' => $movements,
            'new_total' => $running,
        );
    }

    /**
     * The ids in a level list that belong to a system warehouse.
     *
     * The planner takes ids instead of asking WordPress, which keeps it pure;
     * this is the one place that has to know the answer, and it asks once per
     * distinct warehouse rather than once per level.
     *
     * @param array<int,array<string,mixed>> $levels
     * @return array<int,int>
     */
    private static function system_warehouse_ids(array $levels): array {
        $ids = array();
        foreach ($levels as $level) {
            $warehouse_id = (int) ($level['warehouse_id'] ?? 0);
            if ($warehouse_id > 0) {
                $ids[$warehouse_id] = $warehouse_id;
            }
        }

        $system = array();
        foreach ($ids as $warehouse_id) {
            if (self::is_system_warehouse($warehouse_id)) {
                $system[] = $warehouse_id;
            }
        }
        return $system;
    }

    /**
     * Every level that belongs to a product family: the parent itself and each
     * of its variations.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function levels_for_family(int $product_id): array {
        $levels = MGWS_DB::get_levels_for_item($product_id, 0);
        $variations = get_posts(array(
            'post_type' => 'product_variation',
            'post_parent' => $product_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        foreach (is_array($variations) ? $variations : array() as $variation_id) {
            $levels = array_merge($levels, MGWS_DB::get_levels_for_item($product_id, (int) $variation_id));
        }
        return $levels;
    }

    /**
     * The site a difference belongs to.
     *
     * The product's default site is the answer when it has one, because that is
     * the only place where someone said which site this stock lives in. Below
     * that, the shop's own shape answers: a single site or a single existing
     * level site is not a guess, it is the only possibility. Several candidates
     * with no declaration is a question, and this method returns 0 rather than
     * answering it for the operator.
     *
     * @param array<int,array<string,mixed>> $levels
     */
    public static function resolve_site_id(int $product_id, int $variation_id, array $levels = array()): int {
        $candidates = $variation_id > 0 ? array($variation_id, $product_id) : array($product_id);

        foreach ($candidates as $candidate_id) {
            $site_id = (int) get_post_meta($candidate_id, 'mgws_default_site_id', true);
            if ($site_id > 0 && get_post($site_id)) {
                return $site_id;
            }
        }
        foreach ($candidates as $candidate_id) {
            $warehouse_id = (int) get_post_meta($candidate_id, 'mgws_default_warehouse_id', true);
            if ($warehouse_id > 0) {
                $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
                if ($site_id > 0) {
                    return $site_id;
                }
            }
        }

        $level_sites = array();
        foreach ($levels as $level) {
            $site_id = (int) ($level['site_id'] ?? 0);
            if ($site_id > 0) {
                $level_sites[$site_id] = true;
            }
        }
        if (count($level_sites) === 1) {
            return (int) array_key_first($level_sites);
        }

        $sites = get_posts(array(
            'post_type' => 'mg_site',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish',
        ));
        if (is_array($sites) && count($sites) === 1) {
            return (int) $sites[0];
        }

        return 0;
    }

    /**
     * Id of the site's "Unassigned" warehouse, created on first use.
     */
    public static function system_warehouse_id(int $site_id): int {
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return 0;
        }

        $existing = get_posts(array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                array('key' => 'mg_site_id', 'value' => $site_id, 'compare' => '='),
                array('key' => self::SYSTEM_META, 'value' => '1', 'compare' => '='),
            ),
        ));
        if (!empty($existing)) {
            return (int) $existing[0];
        }

        // Two concurrent reconciliations can both find nothing and both create
        // one. A short lock keeps that window small; the ordered query above
        // makes the loser of a race pick the same warehouse as the winner.
        $lock = 'mgws_system_warehouse_' . $site_id;
        if (get_transient($lock)) {
            return 0;
        }
        set_transient($lock, 1, 30);

        $warehouse_id = wp_insert_post(array(
            'post_type' => 'mg_warehouse',
            'post_title' => __('Unassigned', 'mg-warehouse-stock'),
            'post_status' => 'publish',
            'post_author' => 0,
        ));
        if (is_wp_error($warehouse_id) || (int) $warehouse_id <= 0) {
            delete_transient($lock);
            return 0;
        }

        update_post_meta((int) $warehouse_id, 'mg_site_id', $site_id);
        update_post_meta((int) $warehouse_id, self::SYSTEM_META, 1);
        delete_transient($lock);

        return (int) $warehouse_id;
    }

    /**
     * Whether a warehouse is the sink for unexplained differences.
     */
    public static function is_system_warehouse(int $warehouse_id): bool {
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return false;
        }
        if (array_key_exists($warehouse_id, self::$system_cache)) {
            return self::$system_cache[$warehouse_id];
        }
        $is_system = (string) get_post_meta($warehouse_id, self::SYSTEM_META, true) === '1';
        self::$system_cache[$warehouse_id] = $is_system;
        return $is_system;
    }

    /**
     * Drops a warehouse from a list of ids when it is the system one.
     *
     * @param array<int,int|string> $warehouse_ids
     * @return array<int,int>
     */
    public static function filter_system_warehouses(array $warehouse_ids): array {
        $kept = array();
        foreach ($warehouse_ids as $warehouse_id) {
            $warehouse_id = (int) $warehouse_id;
            if ($warehouse_id <= 0 || self::is_system_warehouse($warehouse_id)) {
                continue;
            }
            $kept[] = $warehouse_id;
        }
        return $kept;
    }

    /**
     * Aligns every item that either WooCommerce or the ledger knows about.
     *
     * @return array{ok:bool,reason?:string,checked:int,changed:int,failed:int,changed_items:array<int,array<string,mixed>>,failures:array<int,array<string,mixed>>}
     */
    public static function reconcile_all(string $source = 'cron'): array {
        $lock = 'mgws_reconcile_all';
        if (get_transient($lock)) {
            return array(
                'ok' => false,
                'reason' => 'busy',
                'checked' => 0,
                'changed' => 0,
                'failed' => 0,
                'changed_items' => array(),
                'failures' => array(),
            );
        }
        set_transient($lock, 1, self::LOCK_TTL);

        $checked = 0;
        $changed = 0;
        $failed = 0;
        $changed_items = array();
        $failures = array();

        try {
            foreach (self::items_to_reconcile() as $item) {
                $checked++;
                $result = self::reconcile_item((int) $item[0], (int) $item[1], $source);
                if (!empty($result['changed'])) {
                    $changed++;
                    $changed_items[] = array(
                        'product_id' => (int) $item[0],
                        'variation_id' => (int) $item[1],
                        'delta' => (int) ($result['delta'] ?? 0),
                        'woo_total' => (int) ($result['woo_total'] ?? 0),
                        'mgws_total' => (int) ($result['mgws_total'] ?? 0),
                        'warehouse_id' => (int) ($result['warehouse_id'] ?? 0),
                    );
                    continue;
                }
                if (empty($result['ok'])) {
                    $failed++;
                    $failures[] = array(
                        'product_id' => (int) $item[0],
                        'variation_id' => (int) $item[1],
                        'reason' => (string) ($result['reason'] ?? 'unknown'),
                    );
                }
            }
        } finally {
            delete_transient($lock);
        }

        return array(
            'ok' => true,
            'checked' => $checked,
            'changed' => $changed,
            'failed' => $failed,
            'changed_items' => $changed_items,
            'failures' => $failures,
        );
    }

    /**
     * The differences WooCommerce and the ledger hold right now, without
     * touching anything: who is not aligned, and by how much.
     *
     * It reads through the same code the reconciliation sweep uses, so the
     * owner rule, the family rule and the skip reasons can never drift between
     * a reading and a writing. It writes nothing and fixes nothing; the
     * operator decides what to do with the answer.
     *
     * @param int $limit
     * @param int $offset
     * @return array{items?:array<int,array<string,mixed>>,total?:int}
     */
    public static function divergences(int $limit = 100, int $offset = 0) {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        if (!function_exists('wc_get_product')) {
            return array('items' => array(), 'total' => 0);
        }

        $found = array();
        foreach (self::items_to_reconcile() as $pair) {
            list($product_id, $variation_id) = $pair;
            $row = self::divergence_row($product_id, $variation_id);
            if ($row !== null) {
                $found[] = $row;
            }
        }

        return array(
            'items' => array_slice($found, $offset, $limit),
            'total' => count($found),
        );
    }

    /**
     * One item of the WooCommerce ↔ ledger difference, or null when there is
     * nothing to report for it.
     *
     * @return array<string,mixed>|null
     */
    private static function divergence_row(int $product_id, int $variation_id): ?array {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        if ($product_id <= 0) {
            return null;
        }

        $self_id = $variation_id > 0 ? $variation_id : $product_id;
        $product = wc_get_product($self_id);
        if (!$product) {
            return null;
        }
        if (!$product->managing_stock()) {
            return null;
        }
        $woo_total = $product->get_stock_quantity();
        if ($woo_total === null) {
            return null;
        }
        $woo_total = (int) $woo_total;

        // The same family rule the sweep applies: a variation managed by its
        // parent, and a variable product whose stock the variations own, are
        // compared on the parent unit.
        $owner_id = (int) $product->get_stock_managed_by_id();
        $is_variable_parent = method_exists($product, 'is_type') && $product->is_type('variable');
        $family = ($owner_id > 0 && $owner_id !== $self_id) || $is_variable_parent;
        $item_product_id = $family && $owner_id > 0 ? $owner_id : $product_id;
        $item_variation_id = $family ? 0 : $variation_id;

        $levels = $family
            ? self::levels_for_family($item_product_id)
            : MGWS_DB::get_levels_for_item($item_product_id, $item_variation_id);
        $mgws_total = MGWS_Stock_Plan::sum_levels($levels);
        $delta = (int) MGWS_Stock_Plan::delta($woo_total, $mgws_total);
        if ($delta === 0) {
            return null;
        }

        $parent_product = wc_get_product($item_product_id);
        $site_id = self::resolve_site_id($item_product_id, $item_variation_id, $levels);

        return array(
            'product_id' => $item_product_id,
            'variation_id' => $item_variation_id,
            'name' => $parent_product ? $parent_product->get_name() : '',
            'sku' => $parent_product ? (string) $parent_product->get_sku() : '',
            'woo_stock' => $woo_total,
            'mgws_stock' => $mgws_total,
            'difference' => $delta,
            'site_id' => $site_id > 0 ? $site_id : null,
        );
    }

    /**
     * Every item the sweep has to look at: the owners of a WooCommerce stock
     * total, plus anything the ledger holds even if WooCommerce no longer knows
     * it.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private static function items_to_reconcile(): array {
        global $wpdb;

        $items = array();
        $seen = array();

        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        // `stock_quantity` is not null exactly for the posts that own a `_stock`
        // value (WooCommerce writes it only when `_manage_stock` is `yes`), so
        // this is the owner list, not a list of every product.
        // The posts table cannot be passed through a placeholder here because it
        // is joined by name; every value in the statement is still bound.
        $owners_sql = "SELECT l.product_id, p.post_type, p.post_parent
             FROM %i l
             INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
             WHERE l.stock_quantity IS NOT NULL
               AND p.post_type IN ('product', 'product_variation')";
        $owners = $wpdb->get_results($wpdb->prepare($owners_sql, $lookup), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        foreach (is_array($owners) ? $owners : array() as $row) {
            $id = (int) $row['product_id'];
            if ($id <= 0) {
                continue;
            }
            if ((string) $row['post_type'] === 'product_variation') {
                $product_id = (int) $row['post_parent'];
                $variation_id = $id;
            } else {
                $product_id = $id;
                $variation_id = 0;
            }
            if ($product_id <= 0) {
                continue;
            }
            $key = $product_id . ':' . $variation_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = array($product_id, $variation_id);
        }

        $level_items = $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT product_id, variation_id FROM %i',
            MGWS_DB::table_levels()
        ), ARRAY_A);
        foreach (is_array($level_items) ? $level_items : array() as $row) {
            $product_id = (int) $row['product_id'];
            $variation_id = (int) $row['variation_id'];
            if ($product_id <= 0) {
                continue;
            }
            $key = $product_id . ':' . $variation_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = array($product_id, $variation_id);
        }

        return $items;
    }

    /**
     * Human-readable reason stored on the movement.
     */
    private static function note(int $before, int $after, int $delta, string $source): string {
        return sprintf(
            'Reconciliation (%1$s): total %2$d -> %3$d (delta %4$d)',
            $source,
            $before,
            $after,
            $delta
        );
    }

    /**
     * Nightly entry point.
     *
     * The next run is scheduled before the sweep starts: if this one dies
     * halfway, tomorrow's still happens, and a broken item is not allowed to
     * stop the schedule forever.
     */
    public static function on_cron(): void {
        self::schedule_next();
        self::reconcile_all('cron');
    }

    /**
     * Makes sure exactly one sweep is scheduled.
     */
    public static function schedule(): void {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }
        self::schedule_next();
    }

    /**
     * Schedules the next 03:00 in the site's timezone.
     *
     * A single event recomputed every day instead of a recurring one: a
     * recurring event keeps its original UTC timestamp and drifts by an hour
     * when the clock changes, and the point of "03:00" is that it is 03:00.
     */
    public static function schedule_next(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);

        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->setTime(self::CRON_HOUR, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        wp_schedule_single_event($next->getTimestamp(), self::CRON_HOOK);
    }

    /**
     * Removes the scheduled sweep.
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
