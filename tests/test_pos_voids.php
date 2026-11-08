<?php

function mgws_reset_pos_voids() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

function mgws_pos_void_rows() {
    return $GLOBALS['mgws_test_tables'][MGWS_DB::table_moves()] ?? array();
}

mgws_test('pos voids: one ledger row per voided line, no stock effect', function () {
    mgws_reset_pos_voids();
    $ids = MGWS_DB::insert_pos_void_moves(
        array(array('product_id' => 11761, 'quantity' => 2, 'unit_price' => 10, 'name' => 'MGWS test P1')),
        array('reason' => 'line_removed', 'receipt_key' => '179', 'register_name' => 'Cassa 1', 'shift_id' => 'turno-1', 'site_id' => 205),
        124
    );
    mgws_assert_same(1, count($ids));
    $row = mgws_pos_void_rows()[0];
    mgws_assert_same('void', $row['type']);
    mgws_assert_same('none', $row['stock_effect']);
    mgws_assert_same('pos_void', $row['source_type']);
    mgws_assert_same('line_removed', $row['reason_code']);
    mgws_assert_same(2, $row['qty']);
    mgws_assert_same(205, $row['site_id']);
    mgws_assert_same(124, $row['user_id']);
    mgws_assert_true(strpos($row['note'], 'MGWS test P1 x2 a 10.00 EUR') !== false);
    mgws_assert_true(strpos($row['note'], 'scontrino 179') !== false);
});

mgws_test('pos voids: skips lines without product or quantity, unknown reason falls back', function () {
    mgws_reset_pos_voids();
    $ids = MGWS_DB::insert_pos_void_moves(
        array(
            array('product_id' => 0, 'quantity' => 1),
            array('product_id' => 5, 'quantity' => 0),
            'not a line',
            array('product_id' => 5, 'variation_id' => 7, 'quantity' => 1),
        ),
        array('reason' => 'whatever'),
        1
    );
    mgws_assert_same(1, count($ids));
    $row = mgws_pos_void_rows()[0];
    mgws_assert_same(7, $row['variation_id']);
    mgws_assert_same('line_removed', $row['reason_code']);
});

mgws_test('pos voids: cart_cleared is kept as reason', function () {
    mgws_reset_pos_voids();
    MGWS_DB::insert_pos_void_moves(array(array('product_id' => 5, 'quantity' => 3)), array('reason' => 'cart_cleared'), 1);
    mgws_assert_same('cart_cleared', mgws_pos_void_rows()[0]['reason_code']);
});
