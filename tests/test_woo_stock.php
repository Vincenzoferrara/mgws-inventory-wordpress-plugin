<?php

/**
 * The WooCommerce bridge: the one place MGWS writes a quantity to Woo.
 *
 * Every test here resets the fake world first, because the bridge talks to
 * `wc_get_product()` and the stub keeps products in a global.
 */

mgws_test('commit_delta does nothing for a zero delta', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 3);

    $result = MGWS_Woo_Stock::commit_delta(5, 0, 0);

    mgws_assert_true($result['ok']);
    mgws_assert_true($result['skipped'] ?? false);
    mgws_assert_same(0, count($GLOBALS['mgws_test_stock_calls']));
});

mgws_test('commit_delta refuses a product WooCommerce does not know', function () {
    mgws_test_reset_world();

    $result = MGWS_Woo_Stock::commit_delta(404, 0, 2);

    mgws_assert_false($result['ok']);
    mgws_assert_true(isset($result['message']));
});

mgws_test('commit_delta never forces manage_stock on', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, false, 0);

    $result = MGWS_Woo_Stock::commit_delta(5, 0, 2);

    mgws_assert_false($result['ok']);
    mgws_assert_same(0, count($GLOBALS['mgws_test_stock_calls']));
});

mgws_test('commit_delta increases and reports the new total', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 3);

    $result = MGWS_Woo_Stock::commit_delta(5, 0, 4);

    mgws_assert_true($result['ok']);
    mgws_assert_same(7, $result['new_stock']);
    mgws_assert_same(5, $result['owner_id']);
    mgws_assert_same(7, $GLOBALS['mgws_test_products'][5]->quantity);
    mgws_assert_same('increase', $GLOBALS['mgws_test_stock_calls'][0]['operation']);
    mgws_assert_same(4, $GLOBALS['mgws_test_stock_calls'][0]['amount']);
});

mgws_test('commit_delta decreases by the absolute value', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 9);

    $result = MGWS_Woo_Stock::commit_delta(5, 0, -4);

    mgws_assert_true($result['ok']);
    mgws_assert_same(5, $result['new_stock']);
    mgws_assert_same('decrease', $GLOBALS['mgws_test_stock_calls'][0]['operation']);
    mgws_assert_same(4, $GLOBALS['mgws_test_stock_calls'][0]['amount']);
});

mgws_test('commit_delta writes through the variation and reports the owner', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][77] = new MGWS_Test_Product(77, true, 2, 50);

    $result = MGWS_Woo_Stock::commit_delta(50, 77, 1);

    mgws_assert_true($result['ok']);
    mgws_assert_same(77, $GLOBALS['mgws_test_stock_calls'][0]['product_id']);
    // A variation managed by its parent reports the parent as the owner.
    mgws_assert_same(50, $result['owner_id']);
});

mgws_test('commit_delta marks the write as its own while it happens', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 3);

    mgws_assert_false(MGWS_Woo_Stock::is_applying());
    MGWS_Woo_Stock::commit_delta(5, 0, 1);
    mgws_assert_false(MGWS_Woo_Stock::is_applying());
    // The listener must be able to tell this write apart from someone else's.
    mgws_assert_true($GLOBALS['mgws_test_stock_calls'][0]['applying']);
    // `$updating = false`: the ledger is already committed, so nothing is
    // deferred and WooCommerce saves the product.
    mgws_assert_false($GLOBALS['mgws_test_stock_calls'][0]['updating']);
});

mgws_test('commit_delta restores the depth even when WooCommerce throws', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 3);
    $GLOBALS['mgws_test_throw_on_update'] = true;

    $result = MGWS_Woo_Stock::commit_delta(5, 0, 1);
    $GLOBALS['mgws_test_throw_on_update'] = false;

    mgws_assert_false($result['ok']);
    mgws_assert_false(MGWS_Woo_Stock::is_applying());
});

mgws_test('current_total reads the product WooCommerce would move', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 12);
    $GLOBALS['mgws_test_products'][77] = new MGWS_Test_Product(77, true, 3, 50);

    mgws_assert_same(12, MGWS_Woo_Stock::current_total(5, 0));
    mgws_assert_same(3, MGWS_Woo_Stock::current_total(50, 77));
});

mgws_test('current_total is null when there is no total', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, false, 0);
    $GLOBALS['mgws_test_products'][6] = new MGWS_Test_Product(6, true, null);

    mgws_assert_null(MGWS_Woo_Stock::current_total(5, 0));
    mgws_assert_null(MGWS_Woo_Stock::current_total(6, 0));
    mgws_assert_null(MGWS_Woo_Stock::current_total(404, 0));
});
