<?php

require_once dirname(__DIR__) . '/includes/class-mgws-stock-reset.php';

function mgws_reset_stock_reset() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['mgws_test_warehouse_sites'] = array();
    $GLOBALS['mgws_test_products'] = array();
    $GLOBALS['mgws_test_stock_calls'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('reset_warehouse: invalid warehouse => WP_Error', function () {
    mgws_reset_stock_reset();
    $r = MGWS_Stock_Reset::reset_warehouse(0, 5, array(), 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('reset_warehouse: warehouse without site => WP_Error', function () {
    mgws_reset_stock_reset();
    $r = MGWS_Stock_Reset::reset_warehouse(9, 5, array(), 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('reset_warehouse: empty levels => ok with 0 reset', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $r = MGWS_Stock_Reset::reset_warehouse(5, 3, array(), 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(true, $r['ok']);
    mgws_assert_same(0, $r['reset']);
    mgws_assert_same(0, $r['failed']);
});

mgws_test('reset_warehouse: skips levels already at target', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $table = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'warehouse_id' => 5, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 3, 'site_id' => 1, 'stock_value_cents' => null),
    );
    $r = MGWS_Stock_Reset::reset_warehouse(5, 3, array(), 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(0, $r['reset']);
});

mgws_test('reset_warehouse: resets a level and records delta', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $table = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'warehouse_id' => 5, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 1, 'site_id' => 1, 'stock_value_cents' => null),
    );
    $GLOBALS['mgws_test_products'][10] = new MGWS_Test_Product(10, true, 5);
    $r = MGWS_Stock_Reset::reset_warehouse(5, 4, array(), 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(1, $r['reset']);
    mgws_assert_same(0, $r['failed']);
    mgws_assert_same(4, $r['target_qty']);
    // level updated
    $levels = $GLOBALS['mgws_test_tables'][$table];
    mgws_assert_same(4, (int) $levels[0]['qty']);
    // woo delta mirrored
    mgws_assert_same(1, count($GLOBALS['mgws_test_stock_calls']));
});

mgws_test('reset_warehouse: filters by product_ids', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $table = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'warehouse_id' => 5, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 1, 'site_id' => 1, 'stock_value_cents' => null),
        array('id' => 2, 'warehouse_id' => 5, 'product_id' => 20, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 1, 'site_id' => 1, 'stock_value_cents' => null),
    );
    $GLOBALS['mgws_test_products'][10] = new MGWS_Test_Product(10, true, 5);
    $r = MGWS_Stock_Reset::reset_warehouse(5, 4, array(10), 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(1, $r['reset']);
});

mgws_test('reset_warehouse: dry run plans the change and writes nothing', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $table = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'warehouse_id' => 5, 'product_id' => 10, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 5, 'site_id' => 1, 'stock_value_cents' => null),
    );
    $GLOBALS['mgws_test_products'][10] = new MGWS_Test_Product(10, true, 5);
    $r = MGWS_Stock_Reset::reset_warehouse(5, 2, array(), 1, true);
    mgws_assert_true($r['dry_run']);
    mgws_assert_same(1, $r['reset']);
    mgws_assert_same(3, $r['changed_qty']);
    mgws_assert_same(-3, $r['details'][0]['delta']);
    mgws_assert_same(5, (int) $GLOBALS['mgws_test_tables'][$table][0]['qty']);
    mgws_assert_same(0, count($GLOBALS['mgws_test_stock_calls']));
});

mgws_test('reset_warehouse: a WooCommerce failure is reported, not hidden', function () {
    mgws_reset_stock_reset();
    $GLOBALS['mgws_test_warehouse_sites'][5] = 1;
    $table = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'warehouse_id' => 5, 'product_id' => 404, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 1, 'site_id' => 1, 'stock_value_cents' => null),
    );
    $r = MGWS_Stock_Reset::reset_warehouse(5, 3, array(), 1);
    mgws_assert_same(1, $r['reset']);
    mgws_assert_same(1, count($r['woo_failed']));
});
