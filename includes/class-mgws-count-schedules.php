<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scheduled cycle counts: a warehouse and a frequency.
 *
 * The schedule only says *when* a count is due; opening the count session is
 * the caller's job. The daily cron below is that caller: it turns every due
 * schedule into a draft count session and moves the schedule forward.
 */
class MGWS_Count_Schedules {
    /**
     * Cron hook that opens the count sessions for the due schedules.
     */
    public const CRON_HOOK = 'mgws_run_count_schedules';

    /**
     * Local time of the daily run. 04:00, an hour after the stock sweep, so the
     * count sees the levels the reconciler has just settled.
     */
    private const CRON_HOUR = 4;

    /**
     * Registers the daily run.
     */
    public static function init(): void {
        add_action(self::CRON_HOOK, array(__CLASS__, 'on_cron'));
        add_action('init', array(__CLASS__, 'schedule'), 20);
    }

    /**
     * Makes sure exactly one run is scheduled.
     */
    public static function schedule(): void {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }
        self::schedule_next();
    }

    /**
     * Schedules the next 04:00 in the site's timezone.
     *
     * A single event recomputed every day, for the same reason as the stock
     * sweep: a recurring event keeps its original UTC timestamp and drifts by
     * an hour when the clock changes.
     */
    public static function schedule_next(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);

        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->setTime(self::CRON_HOUR, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        wp_schedule_single_event($next->getTimestamp(), self::CRON_HOOK);
    }

    /**
     * Removes the scheduled run.
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Cron callback: opens the sessions and re-arms tomorrow's run.
     */
    public static function on_cron(): void {
        self::run_due(0);
        self::schedule_next();
    }

    public static function list($active = null, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array($table);
        if ($active !== null) {
            $where[] = 'active = %d';
            $params[] = (int) $active ? 1 : 0;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY next_run_gmt ASC, id ASC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create($site_id, $warehouse_id, $frequency_days, $created_by_user_id) {
        $site_id = (int) $site_id;
        $warehouse_id = (int) $warehouse_id;
        $frequency_days = max(1, (int) $frequency_days);
        if ($site_id <= 0 || $warehouse_id <= 0) {
            return new WP_Error('mgws_bad_request', 'site_id and warehouse_id are required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $now = gmdate('Y-m-d H:i:s');
        $next = gmdate('Y-m-d H:i:s', time() + ($frequency_days * DAY_IN_SECONDS));
        $inserted = $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'warehouse_id' => $warehouse_id,
                'frequency_days' => $frequency_days,
                'next_run_gmt' => $next,
                'active' => 1,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save schedule', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update($id, $frequency_days, $active) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Schedule not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $frequency_days = max(1, (int) $frequency_days);
        $wpdb->update(
            $table,
            array(
                'frequency_days' => $frequency_days,
                'active' => (int) $active ? 1 : 0,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $id),
            array('%d', '%d', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Schedule not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Opens a draft count session for every due schedule and moves the schedule
     * forward.
     *
     * A session is only created when the write succeeded; a failed write leaves
     * the schedule due so the next run retries it. The schedule decides *when*,
     * the caller decides *as whom*: `$created_by_user_id` is the operator for a
     * manual run and 0 for the cron.
     *
     * @param int $created_by_user_id 0 for the cron, the current user otherwise.
     * @return array{advanced:int,sessions:int[],failed:int[]}
     */
    public static function run_due($created_by_user_id = 0) {
        $result = array('advanced' => 0, 'sessions' => array(), 'failed' => array());

        foreach (self::due_schedules() as $schedule) {
            $schedule_id = (int) ($schedule['id'] ?? 0);
            $session = MGWS_DB::create_inventory_count_session(array(
                'site_id' => (int) ($schedule['site_id'] ?? 0),
                'warehouse_id' => (int) ($schedule['warehouse_id'] ?? 0),
                'document_number' => self::session_document_number($schedule_id),
                'started_by_user_id' => (int) $created_by_user_id,
                'notes' => 'Scheduled cycle count (schedule #' . $schedule_id . ')',
            ));
            if (!is_array($session)) {
                // No session, so the schedule stays due: the next run retries.
                $result['failed'][] = $schedule_id;
                continue;
            }

            // The session exists from here on. If the advance fails the schedule
            // stays due and the next run opens a second session; that is the
            // harmless failure mode (an empty draft can be deleted), while the
            // opposite order would silently skip a count.
            $result['sessions'][] = (int) ($session['id'] ?? 0);
            if (!self::advance($schedule)) {
                $result['failed'][] = $schedule_id;
                continue;
            }
            $result['advanced']++;
        }

        return $result;
    }

    /**
     * Active schedules whose next run is now or in the past.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function due_schedules() {
        $now = gmdate('Y-m-d H:i:s');
        $due = array();
        foreach (self::list(true, 500, 0) as $schedule) {
            if ((int) ($schedule['active'] ?? 0) !== 1) {
                continue;
            }
            if ((string) ($schedule['next_run_gmt'] ?? '') > $now) {
                continue;
            }
            $due[] = $schedule;
        }
        return $due;
    }

    /**
     * Moves one schedule to its next run.
     */
    private static function advance($schedule) {
        global $wpdb;
        $table = MGWS_DB::table_count_schedules();
        $next = gmdate('Y-m-d H:i:s', time() + ((int) $schedule['frequency_days'] * DAY_IN_SECONDS));
        $updated = $wpdb->update(
            $table,
            array('next_run_gmt' => $next, 'updated_at_gmt' => gmdate('Y-m-d H:i:s')),
            array('id' => (int) $schedule['id']),
            array('%s', '%s'),
            array('%d')
        );
        return $updated !== false;
    }

    /**
     * Unique document number for a session opened by a schedule.
     *
     * The table has a UNIQUE (site_id, document_number) key, so the timestamp is
     * part of the number: the same schedule cannot legitimately run twice in the
     * same second, and a collision would otherwise silently drop the session.
     */
    private static function session_document_number($schedule_id) {
        return 'CC-' . (int) $schedule_id . '-' . gmdate('YmdHis');
    }
}