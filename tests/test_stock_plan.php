<?php

/**
 * The decisions behind a reconciliation, with no WordPress involved.
 */

mgws_test('sum_levels adds quantities and treats a missing one as zero', function () {
    mgws_assert_same(0, MGWS_Stock_Plan::sum_levels(array()));
    mgws_assert_same(7, MGWS_Stock_Plan::sum_levels(array(
        array('qty' => '3'),
        array('qty' => 4),
        array('room' => 'A'),
        array('qty' => 0),
    )));
});

mgws_test('delta is measured in WooCommerce direction', function () {
    mgws_assert_same(5, MGWS_Stock_Plan::delta(12, 7));
    mgws_assert_same(-5, MGWS_Stock_Plan::delta(7, 12));
    mgws_assert_same(0, MGWS_Stock_Plan::delta(7, 7));
});

mgws_test('delta is null when WooCommerce has no total', function () {
    mgws_assert_null(MGWS_Stock_Plan::delta(null, 7));
});

mgws_test('delta_for_target moves WooCommerce to the counted number', function () {
    // Ten on the shelf, eight in WooCommerce, twelve counted: WooCommerce has
    // to gain four, not the two that the ledger would have suggested.
    mgws_assert_same(4, MGWS_Stock_Plan::delta_for_target(12, 8));
    mgws_assert_same(-3, MGWS_Stock_Plan::delta_for_target(5, 8));
    mgws_assert_same(0, MGWS_Stock_Plan::delta_for_target(8, 8));
});

mgws_test('delta_for_target is null when there is no WooCommerce total', function () {
    mgws_assert_null(MGWS_Stock_Plan::delta_for_target(5, null));
});

mgws_test('woo_delta on a single-site shop closes the divergence', function () {
    // Store count: ten on the shelf, eight in WooCommerce, twelve counted.
    $plan = MGWS_Stock_Plan::woo_delta(12, 8, true, 2);

    mgws_assert_same(4, $plan['delta']);
    mgws_assert_same('store_total', $plan['basis']);
});

mgws_test('woo_delta on a site scope moves Woo by the site change', function () {
    // Site A holds 30 in the ledger and 25 were counted: WooCommerce must lose
    // five, not become 25 while the other sites still hold their pieces.
    $plan = MGWS_Stock_Plan::woo_delta(25, 100, false, -5);

    mgws_assert_same(-5, $plan['delta']);
    mgws_assert_same('site_change', $plan['basis']);
});

mgws_test('woo_delta has nothing to write without a WooCommerce total', function () {
    $plan = MGWS_Stock_Plan::woo_delta(12, null, true, 2);

    mgws_assert_null($plan['delta']);
    mgws_assert_same('none', $plan['basis']);
});

mgws_test('shortfall_moves takes the system warehouse first', function () {
    $levels = array(
        array('warehouse_id' => 10, 'qty' => 5),
        array('warehouse_id' => 99, 'qty' => 2),
        array('warehouse_id' => 11, 'qty' => 4),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(3, $levels, array(99));

    mgws_assert_same(3, $plan['taken']);
    mgws_assert_same(0, $plan['remaining']);
    mgws_assert_same(2, count($plan['moves']));
    mgws_assert_same(99, (int) $plan['moves'][0]['level']['warehouse_id']);
    mgws_assert_same(2, $plan['moves'][0]['take']);
    // Only after the sink is empty does the shortage touch a real shelf.
    mgws_assert_same(10, (int) $plan['moves'][1]['level']['warehouse_id']);
    mgws_assert_same(1, $plan['moves'][1]['take']);
});

mgws_test('shortfall_moves keeps the input order after the system warehouse', function () {
    $levels = array(
        array('warehouse_id' => 10, 'qty' => 5),
        array('warehouse_id' => 11, 'qty' => 4),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(6, $levels, array());

    mgws_assert_same(2, count($plan['moves']));
    mgws_assert_same(10, (int) $plan['moves'][0]['level']['warehouse_id']);
    mgws_assert_same(5, $plan['moves'][0]['take']);
    mgws_assert_same(11, (int) $plan['moves'][1]['level']['warehouse_id']);
    mgws_assert_same(1, $plan['moves'][1]['take']);
});

mgws_test('shortfall_moves clamps to what a level holds', function () {
    $levels = array(
        array('warehouse_id' => 10, 'qty' => 2),
        array('warehouse_id' => 11, 'qty' => 2),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(10, $levels, array());

    mgws_assert_same(4, $plan['taken']);
    mgws_assert_same(6, $plan['remaining']);
    mgws_assert_same(2, count($plan['moves']));
});

mgws_test('shortfall_moves reports an empty level instead of taking from it', function () {
    $levels = array(
        array('warehouse_id' => 10, 'qty' => 0),
        array('warehouse_id' => 11, 'qty' => -1),
        array('warehouse_id' => 12, 'qty' => 3),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(2, $levels, array());

    mgws_assert_same(1, count($plan['moves']));
    mgws_assert_same(12, (int) $plan['moves'][0]['level']['warehouse_id']);
    mgws_assert_same(0, $plan['remaining']);
});

mgws_test('shortfall_moves accepts system ids as strings', function () {
    $levels = array(
        array('warehouse_id' => '7', 'qty' => 1),
        array('warehouse_id' => '8', 'qty' => 1),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(1, $levels, array('8'));

    mgws_assert_same(8, (int) $plan['moves'][0]['level']['warehouse_id']);
});

mgws_test('shortfall_moves has nothing to do for a missing of zero or less', function () {
    foreach (array(0, -3) as $missing) {
        $plan = MGWS_Stock_Plan::shortfall_moves($missing, array(array('warehouse_id' => 10, 'qty' => 5)), array());
        mgws_assert_same(0, count($plan['moves']));
        mgws_assert_same(0, $plan['taken']);
    }
});

mgws_test('shortfall_moves skips a level with no warehouse', function () {
    $levels = array(
        array('qty' => 5),
        array('warehouse_id' => 0, 'qty' => 5),
        array('warehouse_id' => 10, 'qty' => 1),
    );

    $plan = MGWS_Stock_Plan::shortfall_moves(1, $levels, array());

    mgws_assert_same(1, count($plan['moves']));
    mgws_assert_same(10, (int) $plan['moves'][0]['level']['warehouse_id']);
});
