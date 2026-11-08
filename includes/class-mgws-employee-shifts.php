<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Work schedule of an employee: the usual week, the planned shifts and the
 * hours they add up to compared with the contract.
 *
 * The usual week ("settimana tipo") lives on the employee record; shifts are
 * dated rows. Generating a period copies the usual week onto the dates that
 * have no shift yet, so a shift changed by hand is never overwritten unless
 * the caller asks for it.
 *
 * Times are local "HH:MM" strings. An end before the start means the shift
 * ends after midnight.
 */
class MGWS_Employee_Shifts {
    public const DAYS = array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');

    public const CONTRACT_TYPES = array('permanent', 'fixed_term', 'apprenticeship', 'internship', 'other');

    /** Time slots per day in the usual week: morning, afternoon and one spare. */
    private const MAX_SLOTS_PER_DAY = 3;

    /** Longest period listed in one call. */
    public const MAX_LIST_DAYS = 93;

    /** Longest period generated in one call. */
    public const MAX_GENERATE_DAYS = 62;

    public static function valid_date($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    public static function valid_time($value) {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function time_to_minutes($time) {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * Minutes from start to end, crossing midnight when end is earlier.
     * Equal times are not a shift.
     */
    public static function interval_minutes($start, $end) {
        $from = self::time_to_minutes($start);
        $to = self::time_to_minutes($end);
        if ($to === $from) {
            return 0;
        }
        return $to > $from ? $to - $from : $to + 1440 - $from;
    }

    /**
     * Checks a usual week sent by the client.
     *
     * Accepts {"mon": [{"start": "09:00", "end": "13:00"}, ...], ...}; days
     * left out have no work.
     *
     * @return array<string,array<int,array{start:string,end:string}>>|WP_Error
     */
    public static function validate_schedule($schedule) {
        if (!is_array($schedule)) {
            return new WP_Error('mgws_bad_request', 'weekly_schedule must be an object of days', array('status' => 400));
        }
        foreach (array_keys($schedule) as $day) {
            if (!in_array($day, self::DAYS, true)) {
                return new WP_Error('mgws_bad_request', 'weekly_schedule has an unknown day: ' . sanitize_key((string) $day), array('status' => 400));
            }
        }
        $clean = self::normalize_schedule($schedule);
        foreach (self::DAYS as $day) {
            $sent = isset($schedule[$day]) && is_array($schedule[$day]) ? count($schedule[$day]) : 0;
            if ($sent !== count($clean[$day])) {
                return new WP_Error('mgws_bad_request', 'weekly_schedule has an invalid or overlapping time slot on ' . $day, array('status' => 400));
            }
        }
        return $clean;
    }

    /**
     * Keeps the valid slots of each day, sorted, at most three, without
     * overlaps. Every day is present in the result, empty when free.
     *
     * @return array<string,array<int,array{start:string,end:string}>>
     */
    public static function normalize_schedule($schedule) {
        $schedule = is_array($schedule) ? $schedule : array();
        $out = array();
        foreach (self::DAYS as $day) {
            $slots = array();
            foreach ((array) ($schedule[$day] ?? array()) as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $start = (string) ($slot['start'] ?? ($slot[0] ?? ''));
                $end = (string) ($slot['end'] ?? ($slot[1] ?? ''));
                // Within a day the usual week does not cross midnight: a night
                // shift is planned as a dated shift instead.
                if (!self::valid_time($start) || !self::valid_time($end) || self::time_to_minutes($end) <= self::time_to_minutes($start)) {
                    continue;
                }
                $slots[] = array('start' => $start, 'end' => $end);
            }
            usort($slots, static function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
            $kept = array();
            foreach ($slots as $slot) {
                $last = end($kept);
                if ($last !== false && strcmp($slot['start'], $last['end']) < 0) {
                    continue;
                }
                $kept[] = $slot;
                if (count($kept) === self::MAX_SLOTS_PER_DAY) {
                    break;
                }
            }
            $out[$day] = $kept;
        }
        return $out;
    }

    /**
     * Reads the stored usual week; an employee without one has every day free.
     */
    public static function decode_schedule($stored) {
        $decoded = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
        return self::normalize_schedule(is_array($decoded) ? $decoded : array());
    }

    public static function schedule_minutes_week($schedule) {
        $total = 0;
        foreach (self::normalize_schedule($schedule) as $slots) {
            foreach ($slots as $slot) {
                $total += self::interval_minutes($slot['start'], $slot['end']);
            }
        }
        return $total;
    }

    public static function shift_minutes(array $shift) {
        $minutes = self::interval_minutes((string) $shift['start_time'], (string) $shift['end_time']);
        return max(0, $minutes - (int) ($shift['break_minutes'] ?? 0));
    }

    /**
     * Checks one shift. With $partial only the fields present are checked,
     * merged over $existing.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_shift(array $data, array $existing = array()) {
        $merged = array_merge($existing, array_intersect_key($data, array_flip(array('shift_date', 'start_time', 'end_time', 'break_minutes', 'note'))));
        if (!self::valid_date((string) ($merged['shift_date'] ?? ''))) {
            return new WP_Error('mgws_bad_request', 'shift_date must be YYYY-MM-DD', array('status' => 400));
        }
        if (!self::valid_time((string) ($merged['start_time'] ?? '')) || !self::valid_time((string) ($merged['end_time'] ?? ''))) {
            return new WP_Error('mgws_bad_request', 'start_time and end_time must be HH:MM', array('status' => 400));
        }
        $length = self::interval_minutes($merged['start_time'], $merged['end_time']);
        if ($length === 0) {
            return new WP_Error('mgws_bad_request', 'A shift must end after it starts', array('status' => 400));
        }
        $break = (int) ($merged['break_minutes'] ?? 0);
        if ($break < 0 || $break >= $length) {
            return new WP_Error('mgws_bad_request', 'break_minutes must be shorter than the shift', array('status' => 400));
        }
        return array(
            'shift_date' => (string) $merged['shift_date'],
            'start_time' => (string) $merged['start_time'],
            'end_time' => (string) $merged['end_time'],
            'break_minutes' => $break,
            'note' => sanitize_text_field(mb_substr((string) ($merged['note'] ?? ''), 0, 255)),
        );
    }

    /**
     * Two shifts of the same person overlap when their time ranges meet,
     * also across midnight into the next day.
     */
    public static function overlaps(array $a, array $b) {
        $range = static function (array $s) {
            $day = (int) floor(strtotime($s['shift_date'] . ' 00:00:00 UTC') / 86400);
            $start = $day * 1440 + self::time_to_minutes($s['start_time']);
            return array($start, $start + self::interval_minutes($s['start_time'], $s['end_time']));
        };
        list($a1, $a2) = $range($a);
        list($b1, $b2) = $range($b);
        return $a1 < $b2 && $b1 < $a2;
    }

    /**
     * Shifts the usual week gives for a period, skipping the dates in $skip.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function plan_from_schedule($schedule, $from, $to, array $skip = array()) {
        $schedule = self::normalize_schedule($schedule);
        $skip = array_flip($skip);
        $out = array();
        $date = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        while ($date <= $last) {
            $key = $date->format('Y-m-d');
            $day = self::DAYS[((int) $date->format('N')) - 1];
            if (!isset($skip[$key])) {
                foreach ($schedule[$day] as $slot) {
                    $out[] = array(
                        'shift_date' => $key,
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                        'break_minutes' => 0,
                        'note' => '',
                    );
                }
            }
            $date = $date->modify('+1 day');
        }
        return $out;
    }

    /**
     * Planned hours of a period against the contract, week by week.
     *
     * A week cut by the period counts the contract only for its days in it,
     * so a month that starts on a Thursday does not show four missing days.
     *
     * @return array<string,mixed>
     */
    public static function summary(array $shifts, $contract_minutes_week, $from, $to) {
        $weeks = array();
        $date = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        while ($date <= $last) {
            $week = $date->modify('monday this week')->format('Y-m-d');
            if (!isset($weeks[$week])) {
                $weeks[$week] = array('week_start' => $week, 'days' => 0, 'planned_minutes' => 0);
            }
            $weeks[$week]['days']++;
            $date = $date->modify('+1 day');
        }
        foreach ($shifts as $shift) {
            $week = (new DateTimeImmutable($shift['shift_date'] . ' 00:00:00', new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
            if (isset($weeks[$week])) {
                $weeks[$week]['planned_minutes'] += self::shift_minutes($shift);
            }
        }
        $planned = 0;
        $contract = 0;
        foreach ($weeks as $key => $week) {
            $due = (int) round(((int) $contract_minutes_week) * $week['days'] / 7);
            $weeks[$key]['contract_minutes'] = $due;
            $weeks[$key]['difference_minutes'] = $week['planned_minutes'] - $due;
            unset($weeks[$key]['days']);
            $planned += $week['planned_minutes'];
            $contract += $due;
        }
        return array(
            'planned_minutes' => $planned,
            'contract_minutes' => $contract,
            'difference_minutes' => $planned - $contract,
            'weeks' => array_values($weeks),
        );
    }

    public static function list_for($employee_id, $from, $to) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE employee_id = %d AND shift_date BETWEEN %s AND %s ORDER BY shift_date ASC, start_time ASC, id ASC',
            MGWS_DB::table_employee_shifts(),
            (int) $employee_id,
            (string) $from,
            (string) $to
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get($shift_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d LIMIT 1',
            MGWS_DB::table_employee_shifts(),
            (int) $shift_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The shifts of the day before, the day and the day after: the only ones
     * a new shift can overlap, even across midnight.
     */
    private static function neighbours($employee_id, $date, $exclude_id) {
        $day = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        return array_filter(
            self::list_for($employee_id, $day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')),
            static function ($row) use ($exclude_id) {
                return (int) $row['id'] !== (int) $exclude_id;
            }
        );
    }

    private static function overlap_error(array $shift, $employee_id, $exclude_id) {
        foreach (self::neighbours($employee_id, $shift['shift_date'], $exclude_id) as $other) {
            if (self::overlaps($shift, $other)) {
                return new WP_Error('mgws_shift_overlap', 'The shift overlaps another shift of the same employee', array('status' => 409, 'shift_id' => (int) $other['id']));
            }
        }
        return null;
    }

    public static function create($employee_id, array $data, $user_id) {
        $shift = self::validate_shift($data);
        if (is_wp_error($shift)) {
            return $shift;
        }
        $overlap = self::overlap_error($shift, $employee_id, 0);
        if ($overlap) {
            return $overlap;
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(MGWS_DB::table_employee_shifts(), array_merge($shift, array(
            'employee_id' => (int) $employee_id,
            'created_by' => (int) $user_id,
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        )));
        if (!$ok) {
            return new WP_Error('mgws_shift_error', 'Shift could not be saved', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update(array $existing, array $data) {
        $shift = self::validate_shift($data, $existing);
        if (is_wp_error($shift)) {
            return $shift;
        }
        $overlap = self::overlap_error($shift, (int) $existing['employee_id'], (int) $existing['id']);
        if ($overlap) {
            return $overlap;
        }
        global $wpdb;
        $shift['updated_at_gmt'] = current_time('mysql', true);
        if ($wpdb->update(MGWS_DB::table_employee_shifts(), $shift, array('id' => (int) $existing['id'])) === false) {
            return new WP_Error('mgws_shift_error', 'Shift could not be saved', array('status' => 500));
        }
        return self::get((int) $existing['id']);
    }

    public static function delete($shift_id) {
        global $wpdb;
        return (bool) $wpdb->delete(MGWS_DB::table_employee_shifts(), array('id' => (int) $shift_id));
    }

    /**
     * Copies the usual week onto a period. Dates that already have a shift
     * are left alone, unless $replace: then their shifts are removed first.
     *
     * @return array{created:int,skipped_dates:int}|WP_Error
     */
    public static function generate($employee_id, $schedule, $from, $to, $replace, $user_id) {
        global $wpdb;
        $table = MGWS_DB::table_employee_shifts();
        if ($replace) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE employee_id = %d AND shift_date BETWEEN %s AND %s',
                $table,
                (int) $employee_id,
                (string) $from,
                (string) $to
            ));
        }
        $busy = array_values(array_unique(array_column(self::list_for($employee_id, $from, $to), 'shift_date')));
        $created = 0;
        foreach (self::plan_from_schedule($schedule, $from, $to, $busy) as $shift) {
            $row = self::create($employee_id, $shift, $user_id);
            if (is_array($row)) {
                $created++;
            }
        }
        return array('created' => $created, 'skipped_dates' => count($busy));
    }

    public static function response(array $row) {
        return array(
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'shift_date' => (string) $row['shift_date'],
            'start_time' => (string) $row['start_time'],
            'end_time' => (string) $row['end_time'],
            'break_minutes' => (int) $row['break_minutes'],
            'minutes' => self::shift_minutes($row),
            'note' => (string) ($row['note'] ?? ''),
        );
    }
}
