<?php

require_once dirname(__DIR__) . '/includes/class-mgws-supplier-price-history.php';

function mgws_reset_supplier_price_history() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires supplier_id and product_id', function () {
    mgws_reset_supplier_price_history();
    $r = MGWS_Supplier_Price_History::create(0, 5, 0, 100, 'EUR', '', 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Supplier_Price_History::create(3, 0, 0, 100, 'EUR', '', 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('create: rejects negative price', function () {
    mgws_reset_supplier_price_history();
    $r = MGWS_Supplier_Price_History::create(3, 5, 0, -1, 'EUR', '', 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('create: stores a row and normalizes currency', function () {
    mgws_reset_supplier_price_history();
    $r = MGWS_Supplier_Price_History::create(3, 5, 2, 1234, 'usd1', 'note', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(3, (int) $r['supplier_id']);
    mgws_assert_same(5, (int) $r['product_id']);
    mgws_assert_same(2, (int) $r['variation_id']);
    mgws_assert_same(1234, (int) $r['price_cents']);
    mgws_assert_same('EUR', $r['currency']); // invalid -> EUR
});

mgws_test('create: keeps a valid 3-letter currency', function () {
    mgws_reset_supplier_price_history();
    $r = MGWS_Supplier_Price_History::create(3, 5, 0, 100, 'USD', '', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same('USD', $r['currency']);
});

mgws_test('list: filters by supplier/product/variation', function () {
    mgws_reset_supplier_price_history();
    MGWS_Supplier_Price_History::create(3, 5, 0, 100, 'EUR', '', 1);
    MGWS_Supplier_Price_History::create(3, 7, 0, 200, 'EUR', '', 1);
    MGWS_Supplier_Price_History::create(4, 5, 0, 300, 'EUR', '', 1);
    $all = MGWS_Supplier_Price_History::list();
    mgws_assert_same(3, count($all));
    $by_supplier = MGWS_Supplier_Price_History::list(3);
    mgws_assert_same(2, count($by_supplier));
    $by_product = MGWS_Supplier_Price_History::list(0, 5);
    mgws_assert_same(2, count($by_product));
});

mgws_test('get: missing => null', function () {
    mgws_reset_supplier_price_history();
    mgws_assert_null(MGWS_Supplier_Price_History::get(999));
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_supplier_price_history();
    $r = MGWS_Supplier_Price_History::create(3, 5, 0, 100, 'EUR', '', 1);
    $id = (int) $r['id'];
    mgws_assert_same(true, MGWS_Supplier_Price_History::delete($id));
    mgws_assert_null(MGWS_Supplier_Price_History::get($id));
    mgws_assert_true(MGWS_Supplier_Price_History::delete(999) instanceof WP_Error);
});