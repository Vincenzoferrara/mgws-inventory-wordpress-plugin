<?php

require_once dirname(__DIR__) . '/includes/class-mgws-stock-lots.php';

function mgws_reset_lots() {
    $GLOBALS['mgws_test_lots'] = array();
    $GLOBALS['mgws_test_lot_levels'] = array();
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: invalid product => WP_Error', function () {
    mgws_reset_lots();
    $r = MGWS_Stock_Lots::create(0, 0, 0, 'L1', '', '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('create: stores a lot', function () {
    mgws_reset_lots();
    $r = MGWS_Stock_Lots::create(5, 0, 3, 'L1', '2026-12-31', '2026-10-01', 'note', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(5, (int) $r['product_id']);
    mgws_assert_same('L1', $r['lot_code']);
});

mgws_test('update: missing lot => WP_Error', function () {
    mgws_reset_lots();
    $r = MGWS_Stock_Lots::update(999, 0, 'X', '', '', '');
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('delete: missing lot => WP_Error; present => true', function () {
    mgws_reset_lots();
    $r = MGWS_Stock_Lots::create(5, 0, 0, 'L1', '', '', '', 1);
    $id = (int) $r['id'];
    mgws_assert_same(true, MGWS_Stock_Lots::delete($id));
    mgws_assert_true(MGWS_Stock_Lots::get($id) === null);
    $missing = MGWS_Stock_Lots::delete(999);
    mgws_assert_true($missing instanceof WP_Error);
});

mgws_test('upsert_level: invalid lot => WP_Error', function () {
    mgws_reset_lots();
    $r = MGWS_Stock_Lots::upsert_level(0, 1, 'R', 'K', 'S', 5);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('list_for_item: returns array', function () {
    mgws_reset_lots();
    MGWS_Stock_Lots::create(5, 0, 0, 'L1', '', '', '', 1);
    $list = MGWS_Stock_Lots::list_for_item(5, 0);
    mgws_assert_true(is_array($list));
    mgws_assert_same(1, count($list));
});