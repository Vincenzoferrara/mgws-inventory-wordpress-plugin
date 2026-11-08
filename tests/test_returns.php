<?php

require_once dirname(__DIR__) . '/includes/class-mgws-returns.php';

function mgws_reset_returns() {
    $GLOBALS['mgws_test_returns'] = array();
    $GLOBALS['mgws_test_return_lines'] = array();
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: returns a requested return with no lines', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', 'note', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_same(42, (int) $r['order_id']);
    mgws_assert_same('requested', $r['status']);
    mgws_assert_same(array(), $r['lines']);
});

mgws_test('add_line: only on requested; needs product and qty', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    $line = MGWS_Returns::add_line($id, 10, 0, 2);
    mgws_assert_true(is_array($line));
    $got = MGWS_Returns::get($id);
    mgws_assert_same(1, count($got['lines']));
    mgws_assert_same(2, (int) $got['lines'][0]['qty']);

    // bad input
    $bad = MGWS_Returns::add_line($id, 0, 0, 2);
    mgws_assert_true($bad instanceof WP_Error);
    $bad2 = MGWS_Returns::add_line($id, 10, 0, 0);
    mgws_assert_true($bad2 instanceof WP_Error);
});

mgws_test('add_line: refused after approval', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    MGWS_Returns::approve($id, 1);
    $res = MGWS_Returns::add_line($id, 11, 0, 1);
    mgws_assert_true($res instanceof WP_Error);
});

mgws_test('approve: requires at least one line', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    $res = MGWS_Returns::approve($id, 1);
    mgws_assert_true($res instanceof WP_Error);
});

mgws_test('transitions: requested -> approved -> received -> refunded', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    $a = MGWS_Returns::approve($id, 1);
    mgws_assert_same('approved', $a['status']);
    $rc = MGWS_Returns::receive($id, 1);
    mgws_assert_same('received', $rc['status']);
    $cl = MGWS_Returns::close($id, 1);
    mgws_assert_same('refunded', $cl['status']);
});

mgws_test('transitions: out-of-order rejected', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    // receive without approve
    $rc = MGWS_Returns::receive($id, 1);
    mgws_assert_true($rc instanceof WP_Error);
    // close without receive
    MGWS_Returns::approve($id, 1);
    $cl = MGWS_Returns::close($id, 1);
    mgws_assert_true($cl instanceof WP_Error);
});

mgws_test('reject: from requested or approved only', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    $rej = MGWS_Returns::reject($id, 1);
    mgws_assert_same('rejected', $rej['status']);
});

mgws_test('delete: only requested', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    $del = MGWS_Returns::delete($id);
    mgws_assert_same(true, $del);
    mgws_assert_null(MGWS_Returns::get($id));
});

mgws_test('delete: refused after approval', function () {
    mgws_reset_returns();
    $r = MGWS_Returns::create(42, 7, 3, 'defect', '', 1);
    $id = (int) $r['id'];
    MGWS_Returns::add_line($id, 10, 0, 2);
    MGWS_Returns::approve($id, 1);
    $del = MGWS_Returns::delete($id);
    mgws_assert_true($del instanceof WP_Error);
});

mgws_test('list: filter by status', function () {
    mgws_reset_returns();
    $a = MGWS_Returns::create(1, 1, 1, '', '', 1);
    $b = MGWS_Returns::create(2, 1, 1, '', '', 1);
    MGWS_Returns::add_line((int) $a['id'], 10, 0, 1);
    MGWS_Returns::approve((int) $a['id'], 1);
    $requested = MGWS_Returns::list('requested');
    $approved = MGWS_Returns::list('approved');
    mgws_assert_same(1, count($requested));
    mgws_assert_same(1, count($approved));
});