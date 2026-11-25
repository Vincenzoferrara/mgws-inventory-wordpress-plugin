<?php

/**
 * The decisions behind an "Aggiungi" edit, with no WordPress in them.
 *
 * Every test here names a break: a wrong module allowed to edit, a later
 * operation not blocking the edit, or a stock/value delta that leaves the
 * audited movement inconsistent with the level it changed.
 */

require_once dirname(__DIR__) . '/includes/class-mgws-movement-edit-plan.php';

function mgws_edit_row(array $overrides = array()) {
    return array_merge(array(
        'id' => 10,
        'type' => 'in',
        'source_type' => 'quick_load',
        'stock_effect' => 'load',
        'reason_code' => 'quick_load',
        'movement_id' => 7,
        'product_id' => 49,
        'variation_id' => 0,
        'qty' => 5,
        'stock_before' => 10,
        'stock_after' => 15,
        'unit_cost_cents' => 100,
        'value_delta_cents' => 500,
    ), $overrides);
}

mgws_test('movement edit: only quick_load rows can be edited', function () {
    mgws_assert_true(MGWS_Movement_Edit_Plan::can_edit(mgws_edit_row()));
    mgws_assert_false(MGWS_Movement_Edit_Plan::can_edit(mgws_edit_row(array('source_type' => 'adjust'))));
    mgws_assert_false(MGWS_Movement_Edit_Plan::can_edit(mgws_edit_row(array('source_type' => 'receipt'))));
    mgws_assert_false(MGWS_Movement_Edit_Plan::can_edit(mgws_edit_row(array('source_type' => 'inventory_count'))));
    mgws_assert_false(MGWS_Movement_Edit_Plan::can_edit(null));
});

mgws_test('movement edit: an operation with no newer row is last', function () {
    mgws_assert_true(MGWS_Movement_Edit_Plan::is_last_operation(mgws_edit_row(), array()));
});

mgws_test('movement edit: newer rows of the same operation do not block', function () {
    $newer = array(
        mgws_edit_row(array('id' => 11, 'movement_id' => 7)),
        mgws_edit_row(array('id' => 12, 'movement_id' => 7)),
    );
    mgws_assert_true(MGWS_Movement_Edit_Plan::is_last_operation(mgws_edit_row(), $newer));
});

mgws_test('movement edit: a newer row of another operation blocks', function () {
    $newer = array(
        mgws_edit_row(array('id' => 11, 'movement_id' => 7)),
        mgws_edit_row(array('id' => 12, 'movement_id' => 8)),
    );
    mgws_assert_false(MGWS_Movement_Edit_Plan::is_last_operation(mgws_edit_row(), $newer));
});

mgws_test('movement edit: an operation without a key blocks on any newer row', function () {
    $target = mgws_edit_row(array('movement_id' => 0));
    $newer = array(mgws_edit_row(array('id' => 11, 'movement_id' => 0)));
    mgws_assert_false(MGWS_Movement_Edit_Plan::is_last_operation($target, $newer));
});

mgws_test('movement edit: setting quantity to zero keeps the row and reverses audited stock', function () {
    $plan = MGWS_Movement_Edit_Plan::build(mgws_edit_row(), 0);
    mgws_assert_same(5, $plan['old_qty']);
    mgws_assert_same(0, $plan['new_qty']);
    mgws_assert_same(-5, $plan['delta']);
    mgws_assert_same(10, $plan['stock_after']);
    mgws_assert_same(0, $plan['value_delta_cents']);
});

mgws_test('movement edit: reducing quantity reports a negative delta', function () {
    $plan = MGWS_Movement_Edit_Plan::build(mgws_edit_row(), 3);
    mgws_assert_same(-2, $plan['delta']);
    mgws_assert_same(13, $plan['stock_after']);
    mgws_assert_same(300, $plan['value_delta_cents']);
});

mgws_test('movement edit: raising quantity re-bases value on the explicit cost', function () {
    $plan = MGWS_Movement_Edit_Plan::build(mgws_edit_row(), 8);
    mgws_assert_same(3, $plan['delta']);
    mgws_assert_same(18, $plan['stock_after']);
    mgws_assert_same(800, $plan['value_delta_cents']);
});

mgws_test('movement edit: an unchanged quantity is a no-op', function () {
    $plan = MGWS_Movement_Edit_Plan::build(mgws_edit_row(), 5);
    mgws_assert_same(0, $plan['delta']);
    mgws_assert_true($plan['noop']);
});

mgws_test('movement edit: missing audited stock or a negative quantity is refused', function () {
    mgws_assert_null(MGWS_Movement_Edit_Plan::build(mgws_edit_row(array('stock_after' => null)), 3));
    mgws_assert_null(MGWS_Movement_Edit_Plan::build(mgws_edit_row(), -1));
});
