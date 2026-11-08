<?php

require_once dirname(__DIR__) . '/includes/class-mgws-kits.php';

function mgws_reset_kits() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires name', function () {
    mgws_reset_kits();
    $r = MGWS_Kits::create('', 0, 0, '', '', 1);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('create: stores kit with empty components', function () {
    mgws_reset_kits();
    $k = MGWS_Kits::create('Kit A', 10, 0, 'SKU1', 'note', 1);
    mgws_assert_true(is_array($k));
    mgws_assert_same('Kit A', $k['name']);
    mgws_assert_same(array(), $k['components']);
});

mgws_test('add_component: requires existing kit, product_id, qty', function () {
    mgws_reset_kits();
    $r = MGWS_Kits::add_component(999, 5, 0, 2);
    mgws_assert_true($r instanceof WP_Error);
    $k = MGWS_Kits::create('Kit A', 0, 0, '', '', 1);
    $id = (int) $k['id'];
    $r2 = MGWS_Kits::add_component($id, 0, 0, 2);
    mgws_assert_true($r2 instanceof WP_Error);
    $r3 = MGWS_Kits::add_component($id, 5, 0, 0);
    mgws_assert_true($r3 instanceof WP_Error);
});

mgws_test('add_component: attaches component and returns kit', function () {
    mgws_reset_kits();
    $k = MGWS_Kits::create('Kit A', 0, 0, '', '', 1);
    $id = (int) $k['id'];
    $k2 = MGWS_Kits::add_component($id, 5, 2, 3);
    mgws_assert_true(is_array($k2));
    mgws_assert_same(1, count($k2['components']));
    mgws_assert_same(5, (int) $k2['components'][0]['product_id']);
    mgws_assert_same(3, (int) $k2['components'][0]['qty']);
});

mgws_test('remove_component: missing => WP_Error', function () {
    mgws_reset_kits();
    $r = MGWS_Kits::remove_component(999);
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('delete: missing => WP_Error; present => true and removes components', function () {
    mgws_reset_kits();
    $k = MGWS_Kits::create('Kit A', 0, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);
    mgws_assert_same(true, MGWS_Kits::delete($id));
    mgws_assert_null(MGWS_Kits::get($id));
    mgws_assert_true(MGWS_Kits::delete(999) instanceof WP_Error);
});

mgws_test('list: filters by active', function () {
    mgws_reset_kits();
    MGWS_Kits::create('Kit A', 0, 0, '', '', 1);
    mgws_assert_same(1, count(MGWS_Kits::list()));
    mgws_assert_same(1, count(MGWS_Kits::list(1)));
    mgws_assert_same(0, count(MGWS_Kits::list(0)));
});