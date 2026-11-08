<?php

require_once dirname(__DIR__) . '/includes/class-mgws-product-barcodes.php';

function mgws_reset_product_barcodes() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('upsert: requires product_id and barcode', function () {
    mgws_reset_product_barcodes();
    $r = MGWS_Product_Barcodes::upsert(0, 0, '123', 'ean13', 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Product_Barcodes::upsert(5, 0, '', 'ean13', 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('upsert: normalizes barcode_type', function () {
    mgws_reset_product_barcodes();
    $b = MGWS_Product_Barcodes::upsert(5, 0, '1234567890123', 'bogus', 1);
    mgws_assert_true(is_array($b));
    mgws_assert_same('ean13', $b['barcode_type']);
});

mgws_test('upsert: updates existing row', function () {
    mgws_reset_product_barcodes();
    $b1 = MGWS_Product_Barcodes::upsert(5, 0, '111', 'ean13', 1);
    mgws_assert_true(is_array($b1));
    $b2 = MGWS_Product_Barcodes::upsert(5, 0, '222', 'upca', 1);
    mgws_assert_true(is_array($b2));
    mgws_assert_same('222', $b2['barcode']);
    mgws_assert_same('upca', $b2['barcode_type']);
    // only one row
    mgws_assert_true(MGWS_Product_Barcodes::get(5, 0) !== null);
});

mgws_test('get: missing => null', function () {
    mgws_reset_product_barcodes();
    mgws_assert_null(MGWS_Product_Barcodes::get(5, 0));
});

mgws_test('delete: missing => WP_Error; present => true', function () {
    mgws_reset_product_barcodes();
    $b = MGWS_Product_Barcodes::upsert(5, 0, '123', 'ean13', 1);
    mgws_assert_true(is_array($b));
    mgws_assert_same(true, MGWS_Product_Barcodes::delete(5, 0));
    mgws_assert_null(MGWS_Product_Barcodes::get(5, 0));
    mgws_assert_true(MGWS_Product_Barcodes::delete(5, 0) instanceof WP_Error);
});
mgws_test('find_by_barcode: resolves a saved code to its item', function () {
    mgws_reset_product_barcodes();
    MGWS_Product_Barcodes::upsert(5, 3, '4006381333931', 'ean13', 1);
    $row = MGWS_Product_Barcodes::find_by_barcode('4006381333931');
    mgws_assert_true(is_array($row));
    mgws_assert_same(3, (int) $row['variation_id']);
    mgws_assert_null(MGWS_Product_Barcodes::find_by_barcode('0000000000000'));
});

mgws_test('upsert: refuses a code that belongs to another item', function () {
    mgws_reset_product_barcodes();
    MGWS_Product_Barcodes::upsert(5, 0, '111', 'ean13', 1);
    mgws_assert_true(MGWS_Product_Barcodes::upsert(6, 0, '111', 'ean13', 1) instanceof WP_Error);
    // Saving the same code again on its own item is fine.
    mgws_assert_true(is_array(MGWS_Product_Barcodes::upsert(5, 0, '111', 'upca', 1)));
});
