<?php

/**
 * Contract of the movement-edit endpoint.
 *
 * The edit of an "Aggiungi" row is a PATCH on the ledger row: it must be
 * declared as a mutating route, it must accept an absolute quantity (zero
 * included) and it must point at a real callback. A route declared without a
 * callback would answer 501 and leave the app unable to reopen a movement.
 */

require_once dirname(__DIR__) . '/includes/class-mgws-rest-api.php';

mgws_test('movement edit: PATCH is declared with mutate permission', function () {
    $contract = MGWS_REST_API::inventory_restock_route_contract();
    $route = '/inventory/movements/(?P<movement_id>\\d+)';
    mgws_assert_true(isset($contract[$route]), 'movement detail route missing');

    $methods = array_column($contract[$route], 'methods');
    mgws_assert_true(in_array('GET', $methods, true), 'GET missing');
    mgws_assert_true(in_array('PATCH', $methods, true), 'PATCH missing');

    foreach ($contract[$route] as $operation) {
        if ($operation['methods'] === 'PATCH') {
            mgws_assert_same('mutate', $operation['permission']);
        }
    }
});

mgws_test('movement edit: PATCH declares absolute non-negative quantity and optional fields', function () {
    $class = new ReflectionClass(MGWS_REST_API::class);
    $api = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('inventory_count_route_args');
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $args = $method->invoke($api, '/inventory/movements/(?P<movement_id>\\d+)', 'PATCH');

    mgws_assert_true(isset($args['movement_id']), 'movement_id missing');
    mgws_assert_true(isset($args['quantity']), 'quantity missing');
    mgws_assert_true($args['quantity']['required'], 'quantity must be required');
    mgws_assert_same('integer', $args['quantity']['type']);
    mgws_assert_true($args['quantity']['validate_callback'][1] === 'validate_inventory_non_negative_int', 'quantity must allow zero');
    mgws_assert_true(isset($args['note']), 'note missing');
    mgws_assert_false($args['note']['required'], 'note must be optional');
    mgws_assert_true(isset($args['unit_cost']), 'unit_cost missing');
    mgws_assert_false($args['unit_cost']['required'], 'unit_cost must be optional');

    $api = $class->newInstanceWithoutConstructor();
    mgws_assert_true($api->validate_inventory_money(null));
    mgws_assert_true($api->validate_inventory_money('12.50'));
    mgws_assert_false($api->validate_inventory_money('-1'));
    mgws_assert_false($api->validate_inventory_money('abc'));

    $get_args = $method->invoke($api, '/inventory/movements/(?P<movement_id>\\d+)', 'GET');
    mgws_assert_same(array('movement_id'), array_keys($get_args), 'GET must not expose edit fields');
});

mgws_test('movement edit: PATCH callback exists', function () {
    mgws_assert_true(method_exists(MGWS_REST_API::class, 'route_inventory_movement_patch'));
});
