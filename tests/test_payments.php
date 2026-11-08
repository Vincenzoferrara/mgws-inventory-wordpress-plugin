<?php

require_once dirname(__DIR__) . '/includes/class-mgws-payments.php';

function mgws_reset_payments() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires reference_type and reference_id', function () {
    mgws_reset_payments();
    $r = MGWS_Payments::create('', 5, 100, 'EUR', '', '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Payments::create('invoice', 0, 100, 'EUR', '', '', '', 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('create: requires amount > 0', function () {
    mgws_reset_payments();
    $r = MGWS_Payments::create('invoice', 5, 0, 'EUR', '', '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Payments::create('invoice', 5, -10, 'EUR', '', '', '', 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('create: stores payment and normalizes currency', function () {
    mgws_reset_payments();
    $p = MGWS_Payments::create('invoice', 5, 1234, 'usd1', '2026-01-01 10:00:00', 'card', 'note', 1);
    mgws_assert_true(is_array($p));
    mgws_assert_same(1234, (int) $p['amount_cents']);
    mgws_assert_same('EUR', $p['currency']);
});

mgws_test('create: keeps valid currency', function () {
    mgws_reset_payments();
    $p = MGWS_Payments::create('invoice', 5, 100, 'USD', '', '', '', 1);
    mgws_assert_true(is_array($p));
    mgws_assert_same('USD', $p['currency']);
});

mgws_test('list: filters by reference_type/id', function () {
    mgws_reset_payments();
    MGWS_Payments::create('invoice', 5, 100, 'EUR', '', '', '', 1);
    MGWS_Payments::create('receipt', 7, 200, 'EUR', '', '', '', 1);
    mgws_assert_same(2, count(MGWS_Payments::list()));
    mgws_assert_same(1, count(MGWS_Payments::list('invoice', 5)));
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_payments();
    $p = MGWS_Payments::create('invoice', 5, 100, 'EUR', '', '', '', 1);
    $id = (int) $p['id'];
    mgws_assert_same(true, MGWS_Payments::delete($id));
    mgws_assert_null(MGWS_Payments::get($id));
    mgws_assert_true(MGWS_Payments::delete(999) instanceof WP_Error);
});

mgws_test('aging: buckets by days', function () {
    mgws_reset_payments();
    $now = strtotime('2026-06-01 12:00:00');
    $refs = array(
        array('amount_cents' => 10, 'paid_at_gmt' => gmdate('Y-m-d H:i:s', $now - 10 * 86400)),
        array('amount_cents' => 20, 'paid_at_gmt' => gmdate('Y-m-d H:i:s', $now - 45 * 86400)),
        array('amount_cents' => 30, 'paid_at_gmt' => gmdate('Y-m-d H:i:s', $now - 75 * 86400)),
        array('amount_cents' => 40, 'paid_at_gmt' => gmdate('Y-m-d H:i:s', $now - 120 * 86400)),
    );
    $res = MGWS_Payments::aging($refs, gmdate('Y-m-d H:i:s', $now));
    mgws_assert_same(10, $res['buckets']['0-30']);
    mgws_assert_same(20, $res['buckets']['31-60']);
    mgws_assert_same(30, $res['buckets']['61-90']);
    mgws_assert_same(40, $res['buckets']['90+']);
});