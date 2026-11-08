<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stock reservations: holds a quantity aside for a customer/order without
 * moving it. Reserved stock is not available for sale until released.
 */
class MGWS_Reservations {
    public static function list($status = '', $product_id = 0, $variation_id = 0, $warehouse_id = 0, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_reservations();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array();
        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_key((string) $status);
        }
        if ((int) $product_id > 0) {
            $where[] = 'product_id = %d';
            $params[] = (int) $product_id;
        }
        if ((int) $variation_id > 0) {
            $where[] = 'variation_id = %d';
            $params[] = (int) $variation_id;
        }
        if ((int) $warehouse_id > 0) {
            $where[] = 'warehouse_id = %d';
            $params[] = (int) $warehouse_id;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
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
        $table = MGWS_DB::table_reservations();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create($product_id, $variation_id, $warehouse_id, $qty, $reserved_for, $expires_at_gmt, $note, $created_by_user_id) {
        $product_id = (int) $product_id;
        $qty = (int) $qty;
        if ($product_id <= 0 || $qty <= 0) {
            return new WP_Error('mgws_bad_request', 'product_id and qty are required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_reservations();
        $now = gmdate('Y-m-d H:i:s');
        $expires = trim((string) $expires_at_gmt) === '' ? null : $expires_at_gmt;
        $inserted = $wpdb->insert(
            $table,
            array(
                'product_id' => $product_id,
                'variation_id' => (int) $variation_id,
                'warehouse_id' => (int) $warehouse_id,
                'qty' => $qty,
                'reserved_for' => sanitize_text_field((string) $reserved_for),
                'expires_at_gmt' => $expires,
                'status' => 'active',
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save reservation', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function release($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Reservation not found', array('status' => 404));
        }
        if ($row['status'] !== 'active') {
            return new WP_Error('mgws_conflict', 'Only an active reservation can be released', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_reservations();
        $wpdb->update(
            $table,
            array('status' => 'released', 'updated_at_gmt' => gmdate('Y-m-d H:i:s')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function consume($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Reservation not found', array('status' => 404));
        }
        if ($row['status'] !== 'active') {
            return new WP_Error('mgws_conflict', 'Only an active reservation can be consumed', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_reservations();
        $wpdb->update(
            $table,
            array('status' => 'consumed', 'updated_at_gmt' => gmdate('Y-m-d H:i:s')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Reservation not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_reservations();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }
}