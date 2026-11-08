<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Supplier price history: an append-only record of what a supplier charged for
 * an item, so price trends can be reviewed over time.
 */
class MGWS_Supplier_Price_History {
    public static function list($supplier_id = 0, $product_id = 0, $variation_id = 0, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_supplier_price_history();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array();
        if ((int) $supplier_id > 0) {
            $where[] = 'supplier_id = %d';
            $params[] = (int) $supplier_id;
        }
        if ((int) $product_id > 0) {
            $where[] = 'product_id = %d';
            $params[] = (int) $product_id;
        }
        if ((int) $variation_id > 0) {
            $where[] = 'variation_id = %d';
            $params[] = (int) $variation_id;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY recorded_at_gmt DESC, id DESC LIMIT %d OFFSET %d';
        $all_params = array_merge(array($table), $params, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $all_params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function create($supplier_id, $product_id, $variation_id, $price_cents, $currency, $note, $created_by_user_id) {
        $supplier_id = (int) $supplier_id;
        $product_id = (int) $product_id;
        if ($supplier_id <= 0 || $product_id <= 0) {
            return new WP_Error('mgws_bad_request', 'supplier_id and product_id are required', array('status' => 400));
        }
        $price_cents = (int) $price_cents;
        if ($price_cents < 0) {
            return new WP_Error('mgws_bad_request', 'price cannot be negative', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_supplier_price_history();
        $now = gmdate('Y-m-d H:i:s');
        $currency = strtoupper(trim((string) $currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        $inserted = $wpdb->insert(
            $table,
            array(
                'supplier_id' => $supplier_id,
                'product_id' => $product_id,
                'variation_id' => (int) $variation_id,
                'price_cents' => $price_cents,
                'currency' => $currency,
                'recorded_at_gmt' => $now,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save price history', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_supplier_price_history();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Price history not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_supplier_price_history();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }
}