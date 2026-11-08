<?php

require_once dirname(__DIR__) . '/includes/class-mgws-stock-reconciler.php';

function mgws_reset_reconciler() {
    $GLOBALS['mgws_test_meta'] = array();
    $GLOBALS['mgws_test_posts'] = array();
    $GLOBALS['mgws_test_post_lists'] = array();
    $GLOBALS['mgws_test_warehouse_sites'] = array();
    $GLOBALS['mgws_test_stock_calls'] = array();
    $GLOBALS['mgws_test_throw_on_update'] = false;
    $GLOBALS['mgws_test_transients'] = array();
    $cache_property = new \ReflectionProperty(MGWS_Stock_Reconciler::class, 'system_cache');
    if (PHP_VERSION_ID < 80100) {
        $cache_property->setAccessible(true);
    }
    $cache_property->setValue(null, array());
}

// stubs needed by is_system_warehouse cache/transients (already have get_post_meta)
if (!function_exists('get_transient')) {
    function get_transient($key) { return $GLOBALS['mgws_test_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $val, $ttl) { $GLOBALS['mgws_test_transients'][$key] = $val; return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) { unset($GLOBALS['mgws_test_transients'][$key]); return true; }
}

mgws_test('is_system_warehouse: false for <=0', function () {
    mgws_reset_reconciler();
    mgws_assert_false(MGWS_Stock_Reconciler::is_system_warehouse(0));
    mgws_assert_false(MGWS_Stock_Reconciler::is_system_warehouse(-1));
});

mgws_test('is_system_warehouse: reads meta flag', function () {
    mgws_reset_reconciler();
    $GLOBALS['mgws_test_meta'][5] = array(MGWS_Stock_Reconciler::SYSTEM_META => '1');
    mgws_assert_true(MGWS_Stock_Reconciler::is_system_warehouse(5));
    $GLOBALS['mgws_test_meta'][6] = array(MGWS_Stock_Reconciler::SYSTEM_META => '0');
    mgws_assert_false(MGWS_Stock_Reconciler::is_system_warehouse(6));
});

mgws_test('is_system_warehouse: caches result', function () {
    mgws_reset_reconciler();
    $GLOBALS['mgws_test_meta'][7] = array(MGWS_Stock_Reconciler::SYSTEM_META => '1');
    mgws_assert_true(MGWS_Stock_Reconciler::is_system_warehouse(7));
    // change meta after cache; should still be true
    $GLOBALS['mgws_test_meta'][7] = array(MGWS_Stock_Reconciler::SYSTEM_META => '0');
    mgws_assert_true(MGWS_Stock_Reconciler::is_system_warehouse(7));
});

mgws_test('filter_system_warehouses: drops system warehouses', function () {
    mgws_reset_reconciler();
    $GLOBALS['mgws_test_meta'][1] = array(MGWS_Stock_Reconciler::SYSTEM_META => '1');
    $GLOBALS['mgws_test_meta'][2] = array(MGWS_Stock_Reconciler::SYSTEM_META => '0');
    $kept = MGWS_Stock_Reconciler::filter_system_warehouses(array(1, 2, 3));
    mgws_assert_same(array(2, 3), array_values($kept));
});

mgws_test('filter_system_warehouses: drops <=0', function () {
    mgws_reset_reconciler();
    $kept = MGWS_Stock_Reconciler::filter_system_warehouses(array(0, -1, 5));
    mgws_assert_same(array(5), array_values($kept));
});