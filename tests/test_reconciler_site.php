<?php

/**
 * Which site a difference belongs to.
 *
 * This is the one rule in the reconciler that decides *where* to write, so a
 * mistake here puts real stock on the wrong site. The tests pin the order of
 * the answers: the product says it, its default warehouse says it, the levels
 * say it, the shop's own shape says it, and nothing says it — in that order.
 */

function mgws_test_site(int $id): void {
    $GLOBALS['mgws_test_posts'][$id] = (object) array('ID' => $id);
}

mgws_test('resolve_site_id uses the site declared on the product', function () {
    mgws_test_reset_world();
    mgws_test_site(3);
    $GLOBALS['mgws_test_meta'][50] = array('mgws_default_site_id' => '3');

    mgws_assert_same(3, MGWS_Stock_Reconciler::resolve_site_id(50, 0));
});

mgws_test('resolve_site_id ignores a declared site that no longer exists', function () {
    mgws_test_reset_world();
    // Site 3 was deleted: the meta still points at it, but there is no post.
    $GLOBALS['mgws_test_meta'][50] = array('mgws_default_site_id' => '3');
    mgws_test_site(4);
    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(4);

    mgws_assert_same(4, MGWS_Stock_Reconciler::resolve_site_id(50, 0));
});

mgws_test('resolve_site_id prefers the variation declaration over the parent one', function () {
    mgws_test_reset_world();
    mgws_test_site(3);
    mgws_test_site(4);
    $GLOBALS['mgws_test_meta'][50] = array('mgws_default_site_id' => '3');
    $GLOBALS['mgws_test_meta'][77] = array('mgws_default_site_id' => '4');

    mgws_assert_same(4, MGWS_Stock_Reconciler::resolve_site_id(50, 77));
});

mgws_test('resolve_site_id reads the site from the default warehouse', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_meta'][50] = array('mgws_default_warehouse_id' => '9');
    $GLOBALS['mgws_test_warehouse_sites'][9] = 6;

    mgws_assert_same(6, MGWS_Stock_Reconciler::resolve_site_id(50, 0));
});

mgws_test('resolve_site_id falls back to the only site the levels know', function () {
    mgws_test_reset_world();
    $levels = array(
        array('warehouse_id' => 9, 'site_id' => 6, 'qty' => 2),
        array('warehouse_id' => 10, 'site_id' => 6, 'qty' => 1),
    );

    mgws_assert_same(6, MGWS_Stock_Reconciler::resolve_site_id(50, 0, $levels));
});

mgws_test('resolve_site_id refuses to choose between two level sites', function () {
    mgws_test_reset_world();
    $levels = array(
        array('warehouse_id' => 9, 'site_id' => 6, 'qty' => 2),
        array('warehouse_id' => 10, 'site_id' => 7, 'qty' => 1),
    );
    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(6, 7);

    mgws_assert_same(0, MGWS_Stock_Reconciler::resolve_site_id(50, 0, $levels));
});

mgws_test('resolve_site_id falls back to the only site in the shop', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_post_lists']['mg_site'] = array(8);

    mgws_assert_same(8, MGWS_Stock_Reconciler::resolve_site_id(50, 0));
});

mgws_test('resolve_site_id answers zero when nothing says which site', function () {
    mgws_test_reset_world();

    mgws_assert_same(0, MGWS_Stock_Reconciler::resolve_site_id(50, 0));
});

mgws_test('filter_system_warehouses drops the system warehouse and the zeros', function () {
    mgws_test_reset_world();
    $GLOBALS['mgws_test_meta'][99] = array('mgws_system' => '1');
    $GLOBALS['mgws_test_meta'][10] = array();

    mgws_assert_same(array(10, 11), MGWS_Stock_Reconciler::filter_system_warehouses(array('10', 0, 99, '11')));
});
