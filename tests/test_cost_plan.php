<?php

require_once dirname(__DIR__) . '/includes/class-mgws-cost-plan.php';

mgws_test('to_cents: null/empty/non-numeric become null', function () {
    mgws_assert_null(MGWS_Cost_Plan::to_cents(null));
    mgws_assert_null(MGWS_Cost_Plan::to_cents(''));
    mgws_assert_null(MGWS_Cost_Plan::to_cents('   '));
    mgws_assert_null(MGWS_Cost_Plan::to_cents('abc'));
    mgws_assert_null(MGWS_Cost_Plan::to_cents(array()));
});

mgws_test('to_cents: converts decimals to minor units', function () {
    mgws_assert_same(123, MGWS_Cost_Plan::to_cents('1.23', 2));
    mgws_assert_same(12345, MGWS_Cost_Plan::to_cents('1.2345', 4));
    mgws_assert_same(100, MGWS_Cost_Plan::to_cents(1, 2));
    mgws_assert_same(0, MGWS_Cost_Plan::to_cents(0, 2));
});

mgws_test('from_cents: null stays null', function () {
    mgws_assert_null(MGWS_Cost_Plan::from_cents(null, 2));
});

mgws_test('from_cents: formats minor units', function () {
    mgws_assert_same('1.23', MGWS_Cost_Plan::from_cents(123, 2));
    mgws_assert_same('0.00', MGWS_Cost_Plan::from_cents(0, 2));
});

mgws_test('average_cents: null or zero quantity is unknown', function () {
    mgws_assert_null(MGWS_Cost_Plan::average_cents(null, 5));
    mgws_assert_null(MGWS_Cost_Plan::average_cents(100, 0));
    mgws_assert_null(MGWS_Cost_Plan::average_cents(100, -1));
});

mgws_test('average_cents: divides value by quantity', function () {
    mgws_assert_same(10, MGWS_Cost_Plan::average_cents(100, 10));
    mgws_assert_same(33, MGWS_Cost_Plan::average_cents(100, 3));
});

mgws_test('value_after_delta: zero delta returns input', function () {
    mgws_assert_same(50, MGWS_Cost_Plan::value_after_delta(50, 5, 0));
    mgws_assert_null(MGWS_Cost_Plan::value_after_delta(null, 5, 0));
});

mgws_test('value_after_delta: load with explicit cost adds delta*unit', function () {
    mgws_assert_same(200, MGWS_Cost_Plan::value_after_delta(100, 5, 2, 50));
});

mgws_test('value_after_delta: load without cost keeps average', function () {
    // value 100 / 5 = 20 avg; +2 pieces -> 100 + 2*20 = 140
    mgws_assert_same(140, MGWS_Cost_Plan::value_after_delta(100, 5, 2));
});

mgws_test('value_after_delta: unload removes average, never below zero', function () {
    mgws_assert_same(60, MGWS_Cost_Plan::value_after_delta(100, 5, -2));
    mgws_assert_same(0, MGWS_Cost_Plan::value_after_delta(10, 5, -10));
});

mgws_test('value_after_delta: unknown value stays unknown unless empty level loaded', function () {
    mgws_assert_null(MGWS_Cost_Plan::value_after_delta(null, 5, 2));
    mgws_assert_same(100, MGWS_Cost_Plan::value_after_delta(null, 0, 2, 50));
});

mgws_test('value_for_quantity: explicit cost re-bases the whole level', function () {
    mgws_assert_same(200, MGWS_Cost_Plan::value_for_quantity(100, 5, 4, 50));
});

mgws_test('value_for_quantity: no cost scales by current average', function () {
    mgws_assert_same(80, MGWS_Cost_Plan::value_for_quantity(100, 5, 4));
});

mgws_test('value_for_quantity: zero quantity gives zero value', function () {
    mgws_assert_same(0, MGWS_Cost_Plan::value_for_quantity(100, 5, 0));
});

mgws_test('value_for_quantity: unknown average with new quantity is unknown', function () {
    mgws_assert_null(MGWS_Cost_Plan::value_for_quantity(null, 5, 4));
});

mgws_test('projected_cost: delegates to average', function () {
    mgws_assert_same(20, MGWS_Cost_Plan::projected_cost(100, 5));
    mgws_assert_null(MGWS_Cost_Plan::projected_cost(null, 5));
});

mgws_test('clamp_minor_unit: clamps to 0..6', function () {
    mgws_assert_same(0, MGWS_Cost_Plan::clamp_minor_unit(-1));
    mgws_assert_same(6, MGWS_Cost_Plan::clamp_minor_unit(9));
    mgws_assert_same(2, MGWS_Cost_Plan::clamp_minor_unit(2));
});