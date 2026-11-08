<?php

require_once dirname(__DIR__) . '/includes/class-mgws-count-schedules.php';

function mgws_reset_count_schedules() {
    $GLOBALS['mgws_test_tables'] = array();
    $GLOBALS['wpdb'] = new wpdb();
}

mgws_test('create: requires site_id and warehouse_id', function () {
    mgws_reset_count_schedules();
    $r = MGWS_Count_Schedules::create(0, 5, 7, 1);
    mgws_assert_true($r instanceof WP_Error);
    $r2 = MGWS_Count_Schedules::create(1, 0, 7, 1);
    mgws_assert_true($r2 instanceof WP_Error);
});

mgws_test('create: stores schedule with active=1', function () {
    mgws_reset_count_schedules();
    $s = MGWS_Count_Schedules::create(1, 5, 7, 1);
    mgws_assert_true(is_array($s));
    mgws_assert_same(1, (int) $s['site_id']);
    mgws_assert_same(5, (int) $s['warehouse_id']);
    mgws_assert_same(7, (int) $s['frequency_days']);
    mgws_assert_same(1, (int) $s['active']);
});

mgws_test('list/get/update/delete', function () {
    mgws_reset_count_schedules();
    $s = MGWS_Count_Schedules::create(1, 5, 7, 1);
    $id = (int) $s['id'];
    mgws_assert_same(1, count(MGWS_Count_Schedules::list()));
    mgws_assert_same($id, (int) MGWS_Count_Schedules::get($id)['id']);
    $u = MGWS_Count_Schedules::update($id, 14, false);
    mgws_assert_same(14, (int) $u['frequency_days']);
    mgws_assert_same(0, (int) $u['active']);
    mgws_assert_same(true, MGWS_Count_Schedules::delete($id));
    mgws_assert_null(MGWS_Count_Schedules::get($id));
    mgws_assert_true(MGWS_Count_Schedules::delete(999) instanceof WP_Error);
});

mgws_test('run_due: opens a session for a due schedule and advances it', function () {
    mgws_reset_count_schedules();
    $s = MGWS_Count_Schedules::create(1, 5, 7, 1);
    $id = (int) $s['id'];
    // make it due by backdating next_run_gmt
    $wpdb = $GLOBALS['wpdb'];
    $table = MGWS_DB::table_count_schedules();
    $wpdb->update($table, array('next_run_gmt' => gmdate('Y-m-d H:i:s', time() - 3600)), array('id' => $id), array('%s'), array('%d'));

    $res = MGWS_Count_Schedules::run_due(1);
    mgws_assert_same(1, $res['advanced']);
    mgws_assert_same(1, count($res['sessions']));
    mgws_assert_same(array(), $res['failed']);
    // schedule advanced
    $after = MGWS_Count_Schedules::get($id);
    mgws_assert_true(strtotime($after['next_run_gmt']) > time());
});

mgws_test('run_due: non-due schedule is skipped', function () {
    mgws_reset_count_schedules();
    MGWS_Count_Schedules::create(1, 5, 7, 1); // next_run in future
    $res = MGWS_Count_Schedules::run_due(1);
    mgws_assert_same(0, $res['advanced']);
    mgws_assert_same(0, count($res['sessions']));
});