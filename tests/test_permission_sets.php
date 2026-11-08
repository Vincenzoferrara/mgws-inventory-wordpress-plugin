<?php

/**
 * Permission sets: pure-logic checks on the catalog.
 *
 * The catalog is the only allow-list for what a set may contain; manage_options
 * must never appear in it. These tests need no database and no WordPress.
 */

require_once dirname(__DIR__) . '/includes/class-mgws-permission-sets.php';

mgws_test('catalog is non-empty and returns capability => meta', function () {
    $catalog = MGWS_Permission_Sets::catalog();

    mgws_assert_true(is_array($catalog));
    mgws_assert_true(count($catalog) > 0);
    foreach ($catalog as $capability => $meta) {
        mgws_assert_true(is_string($capability) && $capability !== '');
        mgws_assert_true(is_array($meta));
        mgws_assert_true(array_key_exists('label', $meta));
        mgws_assert_true(array_key_exists('area', $meta));
        mgws_assert_true(is_string($meta['label']) && $meta['label'] !== '');
        mgws_assert_true(is_string($meta['area']) && $meta['area'] !== '');
    }
});

mgws_test('catalog does not contain manage_options', function () {
    $catalog = MGWS_Permission_Sets::catalog();
    mgws_assert_false(array_key_exists('manage_options', $catalog));
});

mgws_test('catalog contains the 30 expected capabilities', function () {
    $catalog = MGWS_Permission_Sets::catalog();
    $expected = array(
        'read', 'edit_products', 'edit_shop_orders', 'mgws_stock_read',
        'mgws_stock_move', 'mgws_order_accept', 'mgws_supplier_manage',
        'mgws_purchase_approve', 'mgws_manage_employees',
        'mgws_manage_user_permissions', 'mgws_manage_credentials',
        'mgws_reports_read', 'mgws_costs_read', 'mgws_costs_manage',
        'mgws_roles_read', 'mgws_roles_manage', 'mgws_lots_manage',
        'mgws_transfers_manage', 'mgws_price_lists_manage',
        'mgws_approval_rules_manage', 'mgws_returns_manage',
        'mgws_stock_reset_manage', 'mgws_count_schedules_manage',
        'mgws_kits_manage', 'mgws_reservations_manage',
        'mgws_shipments_manage', 'mgws_product_barcodes_manage',
        'mgws_supplier_prices_manage', 'mgws_documents_manage',
        'mgws_payments_manage',
    );
    foreach ($expected as $cap) {
        mgws_assert_true(array_key_exists($cap, $catalog), 'missing ' . $cap);
    }
    mgws_assert_same(30, count($catalog));
});

mgws_test('catalog_list mirrors catalog in order', function () {
    $catalog = MGWS_Permission_Sets::catalog();
    $list = MGWS_Permission_Sets::catalog_list();

    mgws_assert_same(count($catalog), count($list));
    $i = 0;
    foreach ($catalog as $capability => $meta) {
        mgws_assert_same($capability, $list[$i]['capability']);
        mgws_assert_same((string) $meta['label'], $list[$i]['label']);
        mgws_assert_same((string) $meta['area'], $list[$i]['area']);
        $i++;
    }
});

mgws_test('role_key prefixes the slug', function () {
    mgws_assert_same('mgws_operator', MGWS_Permission_Sets::role_key('operator'));
    mgws_assert_same('mgws_', MGWS_Permission_Sets::role_key(''));
});