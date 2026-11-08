<?php

require_once dirname(__DIR__) . '/includes/class-mgws-employee-shifts.php';

mgws_test('employee shifts: interval crosses midnight, equal times are no shift', function () {
    mgws_assert_same(240, MGWS_Employee_Shifts::interval_minutes('09:00', '13:00'));
    mgws_assert_same(480, MGWS_Employee_Shifts::interval_minutes('22:00', '06:00'));
    mgws_assert_same(0, MGWS_Employee_Shifts::interval_minutes('09:00', '09:00'));
});

mgws_test('employee shifts: usual week keeps valid sorted slots and every day', function () {
    $week = MGWS_Employee_Shifts::normalize_schedule(array(
        'mon' => array(array('start' => '15:00', 'end' => '19:00'), array('start' => '09:00', 'end' => '13:00')),
        'tue' => array(array('09:00', '13:00'), array('12:00', '14:00')),
        'wed' => array(array('start' => '25:00', 'end' => '26:00')),
    ));
    mgws_assert_same(7, count($week));
    mgws_assert_same('09:00', $week['mon'][0]['start']);
    mgws_assert_same(2, count($week['mon']));
    mgws_assert_same(1, count($week['tue']), 'overlapping slot dropped');
    mgws_assert_same(array(), $week['wed']);
    mgws_assert_same(480 + 240, MGWS_Employee_Shifts::schedule_minutes_week($week));
});

mgws_test('employee shifts: validate_schedule refuses unknown days and bad slots', function () {
    mgws_assert_true(MGWS_Employee_Shifts::validate_schedule(array('lun' => array())) instanceof WP_Error);
    mgws_assert_true(MGWS_Employee_Shifts::validate_schedule(array('mon' => array(array('start' => '13:00', 'end' => '09:00')))) instanceof WP_Error);
    mgws_assert_true(MGWS_Employee_Shifts::validate_schedule('x') instanceof WP_Error);
    $ok = MGWS_Employee_Shifts::validate_schedule(array('fri' => array(array('start' => '10:00', 'end' => '18:00'))));
    mgws_assert_true(is_array($ok));
    mgws_assert_same('18:00', $ok['fri'][0]['end']);
});

mgws_test('employee shifts: decode tolerates empty and broken storage', function () {
    mgws_assert_same(array(), MGWS_Employee_Shifts::decode_schedule(null)['mon']);
    mgws_assert_same(array(), MGWS_Employee_Shifts::decode_schedule('{broken')['sun']);
});

mgws_test('employee shifts: validate_shift checks date, times and break', function () {
    mgws_assert_true(MGWS_Employee_Shifts::validate_shift(array('shift_date' => '2026-02-30', 'start_time' => '09:00', 'end_time' => '13:00')) instanceof WP_Error);
    mgws_assert_true(MGWS_Employee_Shifts::validate_shift(array('shift_date' => '2026-11-05', 'start_time' => '9:00', 'end_time' => '13:00')) instanceof WP_Error);
    mgws_assert_true(MGWS_Employee_Shifts::validate_shift(array('shift_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '09:00')) instanceof WP_Error);
    mgws_assert_true(MGWS_Employee_Shifts::validate_shift(array('shift_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '10:00', 'break_minutes' => 60)) instanceof WP_Error);
    $ok = MGWS_Employee_Shifts::validate_shift(array('break_minutes' => 30), array('shift_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '18:00'));
    mgws_assert_same(30, $ok['break_minutes']);
    mgws_assert_same(510, MGWS_Employee_Shifts::shift_minutes($ok));
});

mgws_test('employee shifts: overlap also across midnight', function () {
    $night = array('shift_date' => '2026-11-05', 'start_time' => '22:00', 'end_time' => '06:00');
    mgws_assert_true(MGWS_Employee_Shifts::overlaps($night, array('shift_date' => '2026-11-06', 'start_time' => '05:00', 'end_time' => '09:00')));
    mgws_assert_false(MGWS_Employee_Shifts::overlaps($night, array('shift_date' => '2026-11-06', 'start_time' => '06:00', 'end_time' => '09:00')));
    mgws_assert_false(MGWS_Employee_Shifts::overlaps(
        array('shift_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '13:00'),
        array('shift_date' => '2026-11-05', 'start_time' => '13:00', 'end_time' => '17:00')
    ));
});

mgws_test('employee shifts: plan copies the usual week and skips busy dates', function () {
    $week = array('mon' => array(array('start' => '09:00', 'end' => '13:00'), array('start' => '15:00', 'end' => '19:00')), 'sat' => array(array('start' => '10:00', 'end' => '14:00')));
    // 2026-11-02 is a Monday.
    $plan = MGWS_Employee_Shifts::plan_from_schedule($week, '2026-11-02', '2026-11-09', array('2026-11-09'));
    mgws_assert_same(3, count($plan));
    mgws_assert_same('2026-11-07', $plan[2]['shift_date']);
});

mgws_test('employee shifts: summary prorates the contract on cut weeks', function () {
    $shifts = array(
        array('shift_date' => '2026-11-05', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 60),
        array('shift_date' => '2026-11-09', 'start_time' => '09:00', 'end_time' => '13:00', 'break_minutes' => 0),
    );
    // Thursday to Sunday of one week, then Monday of the next.
    $s = MGWS_Employee_Shifts::summary($shifts, 40 * 60, '2026-11-05', '2026-11-09');
    mgws_assert_same(2, count($s['weeks']));
    mgws_assert_same(420, $s['weeks'][0]['planned_minutes']);
    mgws_assert_same(1371, $s['weeks'][0]['contract_minutes']);
    mgws_assert_same(343, $s['weeks'][1]['contract_minutes']);
    mgws_assert_same(660 - 1714, $s['difference_minutes']);
});
