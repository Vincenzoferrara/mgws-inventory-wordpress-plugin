<?php

require_once dirname(__DIR__) . '/includes/class-mgws-cost.php';

function mgws_reset_cost() {
    $GLOBALS['mgws_test_options'] = array();
    $GLOBALS['mgws_test_price_decimals'] = 2;
}

mgws_test('MGWS_Cost::minor_unit: stores and reuses the scale', function () {
    mgws_reset_cost();
    mgws_assert_same(2, MGWS_Cost::minor_unit());
    mgws_assert_same(2, $GLOBALS['mgws_test_options'][MGWS_Cost::MINOR_UNIT_OPTION]);
    // second call reuses stored value
    $GLOBALS['mgws_test_price_decimals'] = 4;
    mgws_assert_same(2, MGWS_Cost::minor_unit());
});

mgws_test('MGWS_Cost::minor_unit: clamps WooCommerce decimals', function () {
    mgws_reset_cost();
    $GLOBALS['mgws_test_price_decimals'] = 9;
    mgws_assert_same(6, MGWS_Cost::minor_unit());
});

mgws_test('MGWS_Cost::minor_unit_mismatch: false when no wc function difference', function () {
    mgws_reset_cost();
    mgws_assert_false(MGWS_Cost::minor_unit_mismatch());
});

mgws_test('MGWS_Cost::to_cents / from_cents use the stored scale', function () {
    mgws_reset_cost();
    mgws_assert_same(123, MGWS_Cost::to_cents('1.23'));
    mgws_assert_same('1.23', MGWS_Cost::from_cents(123));
});

mgws_test('MGWS_Cost::cogs_enabled: false without wc_get_container', function () {
    mgws_reset_cost();
    mgws_assert_false(MGWS_Cost::cogs_enabled());
});