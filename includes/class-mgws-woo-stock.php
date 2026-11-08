<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only place where MGWS writes a quantity to WooCommerce.
 *
 * MGWS owns the total for the movements it starts (quick load, receipt, count,
 * transfer, admin adjustment), so it applies them as deltas instead of setting
 * an absolute total: `wc_update_product_stock()` does the arithmetic on the
 * database, and two concurrent operations no longer overwrite each other.
 *
 * The total itself belongs to whoever owns the movement: WooCommerce for its
 * own orders, the point of sale, returns, cancellations and order acceptance.
 * Those movements never reach this class, because WooCommerce has already
 * written them.
 *
 * The ledger transaction is committed before this class is called. A failure
 * here leaves the ledger correct and WooCommerce behind: the reconciliation
 * that follows is expected to close the gap, so the caller must never undo a
 * movement because the WooCommerce write failed.
 */
final class MGWS_Woo_Stock {
    /**
     * How deep the current call is inside a WooCommerce write. WooCommerce
     * fires `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`
     * even when MGWS is the writer, and the listener needs to tell its own
     * write apart from someone else's.
     *
     * @var int
     */
    private static int $depth = 0;

    /**
     * True while MGWS is writing to WooCommerce.
     */
    public static function is_applying(): bool {
        return self::$depth > 0;
    }

    /**
     * Reports a failure once, at the single point every writer goes through.
     *
     * The ledger is already committed when this class is called, so a failure
     * here is never a reason to undo the movement: it is a gap for the
     * reconciliation to close. Logging it here means no caller can forget.
     *
     * @return array{ok:bool, message:string}
     */
    private static function fail(string $message): array {
        error_log('MGWS: ' . $message);
        return array('ok' => false, 'message' => $message);
    }

    /**
     * Applies a delta to the owner of the stock.
     *
     * @return array{ok:bool, message?:string, new_stock?:int, owner_id?:int, skipped?:bool}
     */
    public static function commit_delta(int $product_id, int $variation_id, int $delta): array {
        if ($delta === 0) {
            return array('ok' => true, 'skipped' => true);
        }
        if (!function_exists('wc_get_product')) {
            return self::fail(__('WooCommerce is not available, so the stock change was not applied.', 'mg-warehouse-stock'));
        }

        $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$product) {
            return self::fail(sprintf(
                /* translators: 1: product ID, 2: variation ID. */
                __('Product %1$d (variation %2$d) does not exist in WooCommerce.', 'mg-warehouse-stock'),
                $product_id,
                $variation_id
            ));
        }
        // Never force `manage_stock` on: a product that does not manage stock
        // has no number to move, and turning it on would impose a stricter
        // model than WooCommerce's own.
        if (!$product->managing_stock()) {
            return self::fail(sprintf(
                /* translators: 1: product ID, 2: variation ID. */
                __('Product %1$d (variation %2$d) does not manage stock in WooCommerce.', 'mg-warehouse-stock'),
                $product_id,
                $variation_id
            ));
        }

        $operation = $delta > 0 ? 'increase' : 'decrease';
        $amount = abs($delta);

        self::$depth++;
        try {
            // `$updating = false`: the MGWS transaction is already committed, so
            // there is nothing left to defer. With `true` WooCommerce would skip
            // `save()` and leave the product cache and stock status stale.
            $new_stock = wc_update_product_stock($product, $amount, $operation, false);
        } catch (\Throwable $error) {
            return self::fail(sprintf(
                /* translators: %s: error message from WooCommerce. */
                __('WooCommerce did not apply the stock change: %s', 'mg-warehouse-stock'),
                $error->getMessage()
            ));
        } finally {
            self::$depth--;
        }

        if ($new_stock === false || $new_stock === null) {
            return self::fail(sprintf(
                /* translators: 1: product ID, 2: variation ID. */
                __('WooCommerce did not confirm the stock change for product %1$d (variation %2$d).', 'mg-warehouse-stock'),
                $product_id,
                $variation_id
            ));
        }

        return array(
            'ok' => true,
            'new_stock' => (int) $new_stock,
            // A variation with `manage_stock = parent` reports the parent here:
            // that is the product whose total actually moved.
            'owner_id' => (int) $product->get_stock_managed_by_id(),
        );
    }

    /**
     * Total currently in WooCommerce, or null when the product does not manage
     * stock.
     */
    public static function current_total(int $product_id, int $variation_id): ?int {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$product || !$product->managing_stock()) {
            return null;
        }

        $quantity = $product->get_stock_quantity();
        return $quantity === null ? null : (int) $quantity;
    }
}
