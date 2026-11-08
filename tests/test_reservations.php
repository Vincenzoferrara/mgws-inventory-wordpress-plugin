<?php

require_once dirname(__DIR__) . '/includes/class-mgws-reservations.php';

function mgws_reset_reservations() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires product_id and qty', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(0, 0, 1, 2, '', '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Reservations::create(5, 0, 1, 0, '', '', '', 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('create: stores active reservation', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(5, 2, 3, 4, 'order #1', '2026-12-31', 'note', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same('active', $r['status']);
    mgws_assert_same(5, (int) $r['product_id']);
    mgws_assert_same(4, (int) $r['qty']);
});

mgws_test('release: only active', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(5, 0, 1, 2, '', '', '', 1);
    $id = (int) $r['id'];
    $rel = MGWS_Reservations::release($id);
    mgws_assert_same('released', $rel['status']);
    $again = MGWS_Reservations::release($id);
    mgws_assert_true($again instanceof WP_Error);
});

mgws_test('consume: only active', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(5, 0, 1, 2, '', '', '', 1);
    $id = (int) $r['id'];
    $c = MGWS_Reservations::consume($id);
    mgws_assert_same('consumed', $c['status']);
    $again = MGWS_Reservations::consume($id);
    mgws_assert_true($again instanceof WP_Error);
});

mgws_test('release vs consume are mutually exclusive after transition', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(5, 0, 1, 2, '', '', '', 1);
    $id = (int) $r['id'];
    MGWS_Reservations::consume($id);
    $rel = MGWS_Reservations::release($id);
    mgws_assert_true($rel instanceof WP_Error);
});

mgws_test('list: filters by status/product/variation/warehouse', function () {
    mgws_reset_reservations();
    MGWS_Reservations::create(5, 0, 1, 2, '', '', '', 1);
    MGWS_Reservations::create(7, 0, 2, 3, '', '', '', 1);
    mgws_assert_same(2, count(MGWS_Reservations::list()));
    mgws_assert_same(1, count(MGWS_Reservations::list('', 5)));
    mgws_assert_same(1, count(MGWS_Reservations::list('', 0, 0, 2)));
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_reservations();
    $r = MGWS_Reservations::create(5, 0, 1, 2, '', '', '', 1);
    $id = (int) $r['id'];
    mgws_assert_same(true, MGWS_Reservations::delete($id));
    mgws_assert_null(MGWS_Reservations::get($id));
    mgws_assert_true(MGWS_Reservations::delete(999) instanceof WP_Error);
});