<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Partial payments and credit aging: records payments against a reference
 * document (receipt, invoice, purchase order) and computes aging buckets.
 */
class MGWS_Payments {
    public static function list($reference_type = '', $reference_id = 0, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_payments();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array();
        if ($reference_type !== '') {
            $where[] = 'reference_type = %s';
            $params[] = sanitize_key((string) $reference_type);
        }
        if ((int) $reference_id > 0) {
            $where[] = 'reference_id = %d';
            $params[] = (int) $reference_id;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY paid_at_gmt DESC, id DESC LIMIT %d OFFSET %d';
        $all = array_merge(array($table), $params, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $all), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_payments();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create($reference_type, $reference_id, $amount_cents, $currency, $paid_at_gmt, $method, $note, $created_by_user_id) {
        $reference_type = sanitize_key((string) $reference_type);
        if ($reference_type === '' || (int) $reference_id <= 0) {
            return new WP_Error('mgws_bad_request', 'reference_type and reference_id are required', array('status' => 400));
        }
        $amount_cents = (int) $amount_cents;
        if ($amount_cents <= 0) {
            return new WP_Error('mgws_bad_request', 'amount must be > 0', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_payments();
        $now = gmdate('Y-m-d H:i:s');
        $paid_at = trim((string) $paid_at_gmt) === '' ? $now : $paid_at_gmt;
        $currency = strtoupper(trim((string) $currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        $inserted = $wpdb->insert(
            $table,
            array(
                'reference_type' => $reference_type,
                'reference_id' => (int) $reference_id,
                'amount_cents' => $amount_cents,
                'currency' => $currency,
                'paid_at_gmt' => $paid_at,
                'method' => sanitize_key((string) $method),
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save payment', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Payment not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_payments();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Aging buckets for a set of payments grouped by reference.
     * Returns ['buckets' => ['0-30','31-60','61-90','90+'], 'totals' => [...]].
     */
    public static function aging(array $references, $now_gmt = null) {
        $now = $now_gmt !== null ? strtotime((string) $now_gmt) : time();
        $buckets = array('0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0);
        foreach ($references as $ref) {
            $paid_at = strtotime((string) ($ref['paid_at_gmt'] ?? ''));
            if ($paid_at === false) {
                continue;
            }
            $days = (int) floor(($now - $paid_at) / 86400);
            $amount = (int) ($ref['amount_cents'] ?? 0);
            if ($days <= 30) {
                $buckets['0-30'] += $amount;
            } elseif ($days <= 60) {
                $buckets['31-60'] += $amount;
            } elseif ($days <= 90) {
                $buckets['61-90'] += $amount;
            } else {
                $buckets['90+'] += $amount;
            }
        }
        return array('buckets' => $buckets);
    }
}