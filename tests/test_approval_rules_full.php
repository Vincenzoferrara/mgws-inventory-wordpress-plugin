<?php

require_once dirname(__DIR__) . '/includes/class-mgws-approval-rules.php';

function mgws_reset_approval_rules_full() {
    $GLOBALS['mgws_test_approval_rules'] = array();
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('approval: create returns a row with id', function () {
    mgws_reset_approval_rules_full();
    $r = MGWS_Approval_Rules::create('purchase_order', 100, 'mgws_purchase_approve', 'note', 1);
    mgws_assert_true(is_array($r));
    mgws_assert_true(isset($r['id']) && $r['id'] > 0);
    mgws_assert_same('purchase_order', $r['document_type']);
});

mgws_test('approval: update changes fields', function () {
    mgws_reset_approval_rules_full();
    $r = MGWS_Approval_Rules::create('purchase_order', 100, 'mgws_purchase_approve', '', 1);
    $id = (int) $r['id'];
    $u = MGWS_Approval_Rules::update($id, 'sales_order', 200, 'mgws_costs_manage', 0, 'updated');
    mgws_assert_true(is_array($u));
    mgws_assert_same('sales_order', $u['document_type']);
    mgws_assert_same(200, (int) $u['threshold_cents']);
    mgws_assert_same('mgws_costs_manage', $u['required_capability']);
});

mgws_test('approval: update missing => WP_Error', function () {
    mgws_reset_approval_rules_full();
    $r = MGWS_Approval_Rules::update(999, 'purchase_order', 100, 'mgws_purchase_approve', 1, '');
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('approval: delete missing => WP_Error; present => true', function () {
    mgws_reset_approval_rules_full();
    $r = MGWS_Approval_Rules::create('purchase_order', 100, 'mgws_purchase_approve', '', 1);
    $id = (int) $r['id'];
    mgws_assert_same(true, MGWS_Approval_Rules::delete($id));
    mgws_assert_true(MGWS_Approval_Rules::get($id) === null);
    $missing = MGWS_Approval_Rules::delete(999);
    mgws_assert_true($missing instanceof WP_Error);
});

mgws_test('approval: get missing => null', function () {
    mgws_reset_approval_rules_full();
    mgws_assert_null(MGWS_Approval_Rules::get(999));
});

mgws_test('approval: list filters by document_type', function () {
    mgws_reset_approval_rules_full();
    MGWS_Approval_Rules::create('purchase_order', 100, 'mgws_purchase_approve', '', 1);
    MGWS_Approval_Rules::create('sales_order', 50, 'mgws_costs_manage', '', 1);
    $po = MGWS_Approval_Rules::list('purchase_order');
    $all = MGWS_Approval_Rules::list();
    mgws_assert_same(1, count($po));
    mgws_assert_same(2, count($all));
});