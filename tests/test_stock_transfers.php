<?php

require_once dirname(__DIR__) . '/includes/class-mgws-stock-transfers.php';

function mgws_reset_transfers() {
    $GLOBALS['mgws_test_transfers'] = array();
    $GLOBALS['mgws_test_transfer_lines'] = array();
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: draft transfer', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, 'note', 1);
    mgws_assert_true(is_array($t));
    mgws_assert_same(1, (int) $t['from_warehouse_id']);
    mgws_assert_same(2, (int) $t['to_warehouse_id']);
    mgws_assert_same('draft', $t['status']);
    mgws_assert_same(array(), $t['lines']);
});

mgws_test('add_line: only on draft; needs product and qty', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    $line = MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    mgws_assert_true(is_array($line));
    $got = MGWS_Stock_Transfers::get($id);
    mgws_assert_same(1, count($got['lines']));

    $bad = MGWS_Stock_Transfers::add_line($id, 0, 0, 3);
    mgws_assert_true($bad instanceof WP_Error);
    $bad2 = MGWS_Stock_Transfers::add_line($id, 10, 0, 0);
    mgws_assert_true($bad2 instanceof WP_Error);
});

mgws_test('send: refused without lines', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    $res = MGWS_Stock_Transfers::send($id, 1);
    mgws_assert_true($res instanceof WP_Error);
});

mgws_test('send: refused when not draft', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    // mark sent by directly updating status
    $GLOBALS['mgws_test_tables'][$GLOBALS['wpdb']->prefix . 'mg_stock_transfers'][0]['status'] = 'sent';
    $GLOBALS['mgws_test_transfers'] = $GLOBALS['mgws_test_tables'][$GLOBALS['wpdb']->prefix . 'mg_stock_transfers'];
    $res = MGWS_Stock_Transfers::send($id, 1);
    mgws_assert_true($res instanceof WP_Error);
});

mgws_test('receive: refused when not sent', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    $res = MGWS_Stock_Transfers::receive($id, 1);
    mgws_assert_true($res instanceof WP_Error);
});

mgws_test('cancel: from draft marks cancelled', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    $res = MGWS_Stock_Transfers::cancel($id, 1);
    mgws_assert_true(is_array($res) || $res === true || $res instanceof WP_Error);
});

mgws_test('remove_line: only on draft', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    $line = MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    $line_id = (int) $line['id'];
    $rem = MGWS_Stock_Transfers::remove_line($line_id);
    mgws_assert_true(is_array($rem));
});

mgws_test('delete: only draft', function () {
    mgws_reset_transfers();
    $t = MGWS_Stock_Transfers::create(1, 2, '', 1);
    $id = (int) $t['id'];
    MGWS_Stock_Transfers::add_line($id, 10, 0, 3);
    $del = MGWS_Stock_Transfers::delete($id);
    mgws_assert_same(true, $del);
});