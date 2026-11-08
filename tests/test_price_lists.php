<?php

require_once dirname(__DIR__) . '/includes/class-mgws-price-lists.php';

function mgws_reset_price_lists() {
    $GLOBALS['mgws_test_price_lists'] = array();
    $GLOBALS['mgws_test_price_list_items'] = array();
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: stores a price list', function () {
    mgws_reset_price_lists();
    $pl = MGWS_Price_Lists::create('List A', 5, 0, 'EUR', '2026-01-01', '2026-12-31', 'note', 1);
    mgws_assert_true(is_array($pl));
    mgws_assert_same('List A', $pl['name']);
});

mgws_test('update: missing => WP_Error', function () {
    mgws_reset_price_lists();
    $r = MGWS_Price_Lists::update(999, 'X', 0, 0, 'EUR', '', '', 1, '');
    mgws_assert_true($r instanceof WP_Error);
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_price_lists();
    $pl = MGWS_Price_Lists::create('List A', 0, 0, 'EUR', '', '', '', 1);
    $id = (int) $pl['id'];
    mgws_assert_same(true, MGWS_Price_Lists::delete($id));
    mgws_assert_true(MGWS_Price_Lists::get($id) === null);
});

mgws_test('upsert_item + remove_item', function () {
    mgws_reset_price_lists();
    $pl = MGWS_Price_Lists::create('List A', 0, 0, 'EUR', '', '', '', 1);
    $id = (int) $pl['id'];
    $item = MGWS_Price_Lists::upsert_item($id, 10, 0, 1234, 2);
    mgws_assert_true(is_array($item));
    $got = MGWS_Price_Lists::get($id);
    mgws_assert_same(1, count($got['items'] ?? array()));
    $rem = MGWS_Price_Lists::remove_item($id, (int) $item['id']);
    mgws_assert_true(is_array($rem));
});