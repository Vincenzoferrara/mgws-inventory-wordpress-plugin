<?php

require_once dirname(__DIR__) . '/includes/class-mgws-shipments.php';

function mgws_reset_shipments() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: defaults to pending', function () {
    mgws_reset_shipments();
    $s = MGWS_Shipments::create('receipt', 42, 'DHL', 'TRK1', 'note', 1);
    mgws_assert_true(is_array($s));
    mgws_assert_same('pending', $s['status']);
    mgws_assert_same('receipt', $s['reference_type']);
    mgws_assert_same(42, (int) $s['reference_id']);
});

mgws_test('list: filters by reference_type/id/status', function () {
    mgws_reset_shipments();
    MGWS_Shipments::create('receipt', 42, '', '', '', 1);
    MGWS_Shipments::create('purchase_order', 7, '', '', '', 1);
    $all = MGWS_Shipments::list();
    mgws_assert_same(2, count($all));
    $by_ref = MGWS_Shipments::list('receipt', 42);
    mgws_assert_same(1, count($by_ref));
    $by_type = MGWS_Shipments::list('purchase_order');
    mgws_assert_same(1, count($by_type));
});

mgws_test('update_status: validates allowed values', function () {
    mgws_reset_shipments();
    $s = MGWS_Shipments::create('receipt', 42, '', '', '', 1);
    $id = (int) $s['id'];
    $bad = MGWS_Shipments::update_status($id, 'bogus');
    mgws_assert_true($bad instanceof WP_Error);
    $ok = MGWS_Shipments::update_status($id, 'shipped', '2026-01-01 10:00:00');
    mgws_assert_same('shipped', $ok['status']);
});

mgws_test('update_status: missing => WP_Error', function () {
    mgws_reset_shipments();
    $r = MGWS_Shipments::update_status(999, 'shipped');
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_shipments();
    $s = MGWS_Shipments::create('receipt', 42, '', '', '', 1);
    $id = (int) $s['id'];
    mgws_assert_same(true, MGWS_Shipments::delete($id));
    mgws_assert_null(MGWS_Shipments::get($id));
    mgws_assert_true(MGWS_Shipments::delete(999) instanceof WP_Error);
});