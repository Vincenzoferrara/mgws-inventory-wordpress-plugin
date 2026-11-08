<?php

require_once dirname(__DIR__) . '/includes/class-mgws-approval-rules.php';

function mgws_reset_approval_rules() {
    $GLOBALS['mgws_test_approval_rules'] = array();
    $GLOBALS['mgws_test_tables'] = $GLOBALS['mgws_test_tables'] ?? array();
    unset($GLOBALS['mgws_test_tables'][$GLOBALS['wpdb']->prefix . 'mg_approval_rules']);
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('needs_approval: no rules => not required', function () {
    mgws_reset_approval_rules();
    $r = MGWS_Approval_Rules::needs_approval('purchase_order', 1000);
    mgws_assert_same(false, $r['required']);
    mgws_assert_null($r['capability']);
    mgws_assert_null($r['rule_id']);
});

mgws_test('needs_approval: threshold above amount => not required', function () {
    mgws_reset_approval_rules();
    MGWS_Approval_Rules::create('purchase_order', 5000, 'mgws_purchase_approve', '', 1);
    $r = MGWS_Approval_Rules::needs_approval('purchase_order', 1000);
    mgws_assert_same(false, $r['required']);
});

mgws_test('needs_approval: amount at/above threshold => required with highest threshold', function () {
    mgws_reset_approval_rules();
    MGWS_Approval_Rules::create('purchase_order', 1000, 'mgws_purchase_approve', '', 1);
    MGWS_Approval_Rules::create('purchase_order', 5000, 'mgws_costs_manage', '', 1);
    // amount 6000: both thresholds <= 6000, highest (5000) wins
    $r = MGWS_Approval_Rules::needs_approval('purchase_order', 6000);
    mgws_assert_same(true, $r['required']);
    mgws_assert_same('mgws_costs_manage', $r['capability']);
});

mgws_test('needs_approval: threshold 0 means always', function () {
    mgws_reset_approval_rules();
    MGWS_Approval_Rules::create('purchase_order', 0, 'mgws_purchase_approve', '', 1);
    $r = MGWS_Approval_Rules::needs_approval('purchase_order', 0);
    mgws_assert_same(true, $r['required']);
});

mgws_test('needs_approval: inactive rules ignored', function () {
    mgws_reset_approval_rules();
    $created = MGWS_Approval_Rules::create('purchase_order', 0, 'mgws_purchase_approve', '', 1);
    MGWS_Approval_Rules::update((int) $created['id'], 'purchase_order', 0, 'mgws_purchase_approve', 0, '');
    $r = MGWS_Approval_Rules::needs_approval('purchase_order', 1000);
    mgws_assert_same(false, $r['required']);
});

mgws_test('create: empty document_type => WP_Error', function () {
    mgws_reset_approval_rules();
    $r = MGWS_Approval_Rules::create('', 100, 'mgws_purchase_approve', '', 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('create: empty required_capability => WP_Error', function () {
    mgws_reset_approval_rules();
    $r = MGWS_Approval_Rules::create('purchase_order', 100, '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
});