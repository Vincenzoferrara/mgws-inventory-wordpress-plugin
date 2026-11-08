<?php

require_once dirname(__DIR__) . '/includes/class-mgws-kits.php';

function mgws_reset_kits_deduction() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['mgws_test_warehouse_sites'] = array();
    $GLOBALS['mgws_test_post_lists'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('find_for_product: returns null when no kit', function () {
    mgws_reset_kits_deduction();
    mgws_assert_null(MGWS_Kits::find_for_product(10, 0));
});

mgws_test('find_for_product: resolves kit for matching product/variation', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 2, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 3);
    $found = MGWS_Kits::find_for_product(10, 2);
    mgws_assert_true(is_array($found));
    mgws_assert_same($id, (int) $found['id']);
    mgws_assert_same(1, count($found['components']));
});

mgws_test('find_for_product: ignores inactive kits', function () {
    mgws_reset_kits_deduction();
    $table = MGWS_DB::table_kits();
    $GLOBALS['mgws_test_tables'][$table] = array(
        array('id' => 1, 'name' => 'Kit A', 'product_id' => 10, 'variation_id' => 0, 'sku' => '', 'active' => 0, 'note' => null, 'created_by_user_id' => 1, 'created_at_gmt' => '', 'updated_at_gmt' => ''),
    );
    mgws_assert_null(MGWS_Kits::find_for_product(10, 0));
});

mgws_test('expand_components: empty when product is not a kit', function () {
    mgws_reset_kits_deduction();
    mgws_assert_same(array(), MGWS_Kits::expand_components(99, 0, 2));
});

mgws_test('expand_components: multiplies component qty by kit qty', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);
    MGWS_Kits::add_component($id, 7, 1, 1);
    $needs = MGWS_Kits::expand_components(10, 0, 3);
    mgws_assert_same(2, count($needs));
    mgws_assert_same(5, (int) $needs[0]['product_id']);
    mgws_assert_same(6, (int) $needs[0]['qty']); // 2 * 3
    mgws_assert_same(7, (int) $needs[1]['product_id']);
    mgws_assert_same(1, (int) $needs[1]['variation_id']);
    mgws_assert_same(3, (int) $needs[1]['qty']); // 1 * 3
});

mgws_test('site_availability: returns one entry per physical site', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);

    // Two physical sites.
    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(100, 200);

    // Component stock: site 100 has 4 (=> 2 kits), site 200 has 1 (=> 0 kits).
    $levels = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$levels] = array(
        array('id' => 1, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 4),
        array('id' => 2, 'site_id' => 200, 'warehouse_id' => 20, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 1),
    );
    $GLOBALS['mgws_test_warehouse_sites'][10] = 100;
    $GLOBALS['mgws_test_warehouse_sites'][20] = 200;

    $avail = MGWS_Kits::site_availability(10, 0, 1);
    mgws_assert_same(2, count($avail));
    // site 100: 4/2 = 2 kits, available
    mgws_assert_same(100, (int) $avail[0]['site_id']);
    mgws_assert_same(2, (int) $avail[0]['available_kits']);
    mgws_assert_same(true, $avail[0]['available']);
    // site 200: 1/2 = 0 kits, not available
    mgws_assert_same(200, (int) $avail[1]['site_id']);
    mgws_assert_same(0, (int) $avail[1]['available_kits']);
    mgws_assert_same(false, $avail[1]['available']);
});

mgws_test('site_availability: sums virtual warehouses of the same physical site', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);

    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(100);

    // Two virtual warehouses in the same site.
    $levels = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$levels] = array(
        array('id' => 1, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 2),
        array('id' => 2, 'site_id' => 100, 'warehouse_id' => 11, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 2),
    );
    $GLOBALS['mgws_test_warehouse_sites'][10] = 100;
    $GLOBALS['mgws_test_warehouse_sites'][11] = 100;

    $avail = MGWS_Kits::site_availability(10, 0, 1);
    mgws_assert_same(1, count($avail));
    // 4 total / 2 = 2 kits
    mgws_assert_same(2, (int) $avail[0]['available_kits']);
    mgws_assert_same(true, $avail[0]['available']);
});

mgws_test('site_availability: empty when product is not a kit', function () {
    mgws_reset_kits_deduction();
    $avail = MGWS_Kits::site_availability(99, 0, 1);
    mgws_assert_same(array(), $avail);
});

mgws_test('site_availability: required qty scales availability threshold', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);

    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(100);
    $levels = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$levels] = array(
        array('id' => 1, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 4),
    );
    $GLOBALS['mgws_test_warehouse_sites'][10] = 100;

    // 4 components => 2 kits buildable; asking for 3 => not available
    $avail = MGWS_Kits::site_availability(10, 0, 3);
    mgws_assert_same(2, (int) $avail[0]['available_kits']);
    mgws_assert_same(false, $avail[0]['available']);
});

mgws_test('build_component_allocations: empty when product is not a kit', function () {
    mgws_reset_kits_deduction();
    $built = MGWS_Kits::build_component_allocations(100, 99, 0, 2);
    mgws_assert_same(array(), $built['allocations']);
    mgws_assert_same(0, (int) $built['shortfall']);
});

mgws_test('build_component_allocations: builds allocations and reports shortfall', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2); // needs 4 for 2 kits
    MGWS_Kits::add_component($id, 7, 0, 1); // needs 2 for 2 kits

    $levels = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$levels] = array(
        array('id' => 1, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 3),
        array('id' => 2, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 7, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 5),
    );
    $GLOBALS['mgws_test_warehouse_sites'][10] = 100;

    $built = MGWS_Kits::build_component_allocations(100, 10, 0, 2);
    // product 5: needs 4, has 3 => allocates 3, shortfall 1; product 7: needs 2, has 5 => allocates 2
    mgws_assert_same(1, (int) $built['shortfall']);
    mgws_assert_same(2, count($built['allocations']));
    $total5 = 0; $total7 = 0;
    foreach ($built['allocations'] as $a) {
        if ((int) $a['product_id'] === 5) $total5 += (int) $a['use_qty'];
        if ((int) $a['product_id'] === 7) $total7 += (int) $a['use_qty'];
        mgws_assert_same(100, (int) $a['site_id']);
        mgws_assert_same(10, (int) $a['warehouse_id']);
    }
    mgws_assert_same(3, $total5);
    mgws_assert_same(2, $total7);
});

mgws_test('build_component_allocations: splits across warehouses of the same site', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 5);

    $levels = MGWS_DB::table_levels();
    $GLOBALS['mgws_test_tables'][$levels] = array(
        array('id' => 1, 'site_id' => 100, 'warehouse_id' => 10, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 2),
        array('id' => 2, 'site_id' => 100, 'warehouse_id' => 11, 'product_id' => 5, 'variation_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '', 'qty' => 4),
    );
    $GLOBALS['mgws_test_warehouse_sites'][10] = 100;
    $GLOBALS['mgws_test_warehouse_sites'][11] = 100;

    $built = MGWS_Kits::build_component_allocations(100, 10, 0, 1);
    mgws_assert_same(0, (int) $built['shortfall']);
    mgws_assert_same(2, count($built['allocations']));
    $total = 0;
    foreach ($built['allocations'] as $a) { $total += (int) $a['use_qty']; }
    mgws_assert_same(5, $total);
});

mgws_test('deduct_from_site: returns failure when product is not a kit / no stock', function () {
    mgws_reset_kits_deduction();
    $res = MGWS_Kits::deduct_from_site(100, 99, 0, 1, 0, 0);
    mgws_assert_same(false, $res['ok']);
});

mgws_test('deduct_from_site: returns failure with shortfall when allocations are empty', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    $id = (int) $k['id'];
    MGWS_Kits::add_component($id, 5, 0, 2);
    // No levels at all for component in site 100.
    $GLOBALS['mgws_test_tables'][MGWS_DB::table_levels()] = array();

    $res = MGWS_Kits::deduct_from_site(100, 10, 0, 1, 0, 0);
    mgws_assert_same(false, $res['ok']);
    mgws_assert_same(2, (int) $res['shortfall']);
    mgws_assert_true(strpos((string) ($res['message'] ?? ''), 'No component stock') !== false);
});

mgws_test('restore_to_site: returns ok when product is not a kit', function () {
    mgws_reset_kits_deduction();
    $res = MGWS_Kits::restore_to_site(100, 99, 0, 1, 0, 0);
    mgws_assert_same(true, $res['ok']);
});
mgws_test('commit_components_to_woo: sums per item and moves WooCommerce by the sign', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_products'][5] = new MGWS_Test_Product(5, true, 10);
    $GLOBALS['mgws_test_products'][7] = new MGWS_Test_Product(7, true, 4);
    $allocations = array(
        array('product_id' => 5, 'variation_id' => 0, 'use_qty' => 2),
        array('product_id' => 5, 'variation_id' => 0, 'use_qty' => 1),
        array('product_id' => 7, 'variation_id' => 0, 'use_qty' => 3),
    );
    $errors = MGWS_Kits::commit_components_to_woo($allocations, -1);
    mgws_assert_same(array(), $errors);
    mgws_assert_same(7, $GLOBALS['mgws_test_products'][5]->quantity);
    mgws_assert_same(1, $GLOBALS['mgws_test_products'][7]->quantity);
    // One WooCommerce write per item, not per allocation row.
    mgws_assert_same(2, count($GLOBALS['mgws_test_stock_calls']));

    MGWS_Kits::commit_components_to_woo($allocations, 1);
    mgws_assert_same(10, $GLOBALS['mgws_test_products'][5]->quantity);
});

mgws_test('commit_components_to_woo: reports items WooCommerce cannot move', function () {
    mgws_test_reset_world();
    $errors = MGWS_Kits::commit_components_to_woo(array(array('product_id' => 404, 'variation_id' => 0, 'use_qty' => 1)), -1);
    mgws_assert_same(1, count($errors));
});

mgws_test('restore_to_site: refuses a kit restore without the order that took it', function () {
    mgws_reset_kits_deduction();
    $k = MGWS_Kits::create('Kit A', 10, 0, '', '', 1);
    MGWS_Kits::add_component((int) $k['id'], 5, 0, 2);
    $res = MGWS_Kits::restore_to_site(100, 10, 0, 1, 0, 0);
    mgws_assert_same(false, $res['ok']);
});

mgws_test('net_taken_for_order: nothing is taken for order 0', function () {
    mgws_reset_kits_deduction();
    mgws_assert_same(0, MGWS_Kits::net_taken_for_order(0, 5, 0));
});
