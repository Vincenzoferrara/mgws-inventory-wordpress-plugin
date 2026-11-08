<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shipments and tracking: a shipment record linked to a reference document
 * (receipt, purchase order, return), with carrier and tracking number.
 */
class MGWS_Shipments {
    public static function list($reference_type = '', $reference_id = 0, $status = '', $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_shipments();
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
        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_key((string) $status);
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
        $table = MGWS_DB::table_shipments();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create($reference_type, $reference_id, $carrier, $tracking_number, $note, $created_by_user_id) {
        global $wpdb;
        $table = MGWS_DB::table_shipments();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'reference_type' => sanitize_key((string) $reference_type),
                'reference_id' => max(0, (int) $reference_id),
                'carrier' => sanitize_text_field((string) $carrier),
                'tracking_number' => sanitize_text_field((string) $tracking_number),
                'status' => 'pending',
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save shipment', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update_status($id, $status, $shipped_at_gmt = null, $delivered_at_gmt = null) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Shipment not found', array('status' => 404));
        }
        $allowed = array('pending', 'shipped', 'delivered', 'cancelled');
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('mgws_bad_request', 'Invalid status', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_shipments();
        $data = array(
            'status' => $status,
            'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
        );
        if ($shipped_at_gmt !== null) {
            $data['shipped_at_gmt'] = $shipped_at_gmt !== '' ? $shipped_at_gmt : null;
        }
        if ($delivered_at_gmt !== null) {
            $data['delivered_at_gmt'] = $delivered_at_gmt !== '' ? $delivered_at_gmt : null;
        }
        $wpdb->update($table, $data, array('id' => $id), array('%s', '%s'), array('%d'));
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Shipment not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_shipments();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }
}