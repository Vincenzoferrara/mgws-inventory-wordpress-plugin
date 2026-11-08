<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stock lots: groups of pieces of the same item that share an origin and an
 * expiry date.
 *
 * A lot is a label, not a movement: it does not create rows in
 * `mg_stock_moves`, does not change `mg_stock_levels.qty`, and carries no cost
 * (the WAC in `mg_product_costs` stays the cost authority). The quantity on
 * `mg_stock_lots_levels` is how many pieces of that lot sit on that location;
 * it may be less than the location's total (unlabelled stock) and the plugin
 * does not correct the difference automatically.
 */
class MGWS_Stock_Lots {
    public static function list_for_item($product_id, $variation_id = 0) {
        global $wpdb;
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        if ($product_id <= 0) {
            return array();
        }
        $lots_table = MGWS_DB::table_stock_lots();
        $levels_table = MGWS_DB::table_stock_lots_levels();
        $lots = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE product_id = %d AND variation_id = %d ORDER BY expires_at_gmt IS NULL, expires_at_gmt ASC, id ASC',
                $lots_table,
                $product_id,
                $variation_id
            ),
            ARRAY_A
        );
        if (!is_array($lots)) {
            return array();
        }
        $out = array();
        foreach ($lots as $lot) {
            $lot_id = (int) $lot['id'];
            $levels = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE lot_id = %d ORDER BY warehouse_id ASC, room ASC, rack ASC, shelf ASC',
                    $levels_table,
                    $lot_id
                ),
                ARRAY_A
            );
            $lot['levels'] = is_array($levels) ? $levels : array();
            $out[] = $lot;
        }
        return $out;
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_lots();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        $levels_table = MGWS_DB::table_stock_lots_levels();
        $levels = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE lot_id = %d ORDER BY warehouse_id ASC, room ASC, rack ASC, shelf ASC', $levels_table, $id),
            ARRAY_A
        );
        $row['levels'] = is_array($levels) ? $levels : array();
        return $row;
    }

    public static function create($product_id, $variation_id, $supplier_id, $lot_code, $expires_at_gmt, $received_at_gmt, $note, $created_by_user_id) {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        if ($product_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_lots();
        $now = gmdate('Y-m-d H:i:s');
        $expires = self::normalize_datetime($expires_at_gmt);
        $received = self::normalize_datetime($received_at_gmt);
        $inserted = $wpdb->insert(
            $table,
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'supplier_id' => (int) $supplier_id,
                'lot_code' => sanitize_text_field((string) $lot_code),
                'expires_at_gmt' => $expires,
                'received_at_gmt' => $received,
                'note' => $note !== null ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the lot', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update($id, $supplier_id, $lot_code, $expires_at_gmt, $received_at_gmt, $note) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Lot not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_lots();
        $wpdb->update(
            $table,
            array(
                'supplier_id' => (int) $supplier_id,
                'lot_code' => sanitize_text_field((string) $lot_code),
                'expires_at_gmt' => self::normalize_datetime($expires_at_gmt),
                'received_at_gmt' => self::normalize_datetime($received_at_gmt),
                'note' => $note !== null ? (string) $note : null,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $id),
            array('%d', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Lot not found', array('status' => 404));
        }
        global $wpdb;
        $levels_table = MGWS_DB::table_stock_lots_levels();
        $wpdb->delete($levels_table, array('lot_id' => $id), array('%d'));
        $table = MGWS_DB::table_stock_lots();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Aggiunge o aggiorna la quantita' di un lotto su un livello (per
     * posizione). Con qty <= 0 la riga viene rimossa.
     */
    public static function upsert_level($lot_id, $warehouse_id, $room, $rack, $shelf, $qty) {
        $lot_id = (int) $lot_id;
        if ($lot_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid lot', array('status' => 400));
        }
        if (!self::get($lot_id)) {
            return new WP_Error('mgws_not_found', 'Lot not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_lots_levels();
        $qty = (int) $qty;
        if ($qty <= 0) {
            $wpdb->delete(
                $table,
                array(
                    'lot_id' => $lot_id,
                    'warehouse_id' => (int) $warehouse_id,
                    'room' => (string) $room,
                    'rack' => (string) $rack,
                    'shelf' => (string) $shelf,
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
            return true;
        }
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE lot_id = %d AND warehouse_id = %d AND room = %s AND rack = %s AND shelf = %s',
                $table,
                $lot_id,
                (int) $warehouse_id,
                (string) $room,
                (string) $rack,
                (string) $shelf
            )
        );
        $now = gmdate('Y-m-d H:i:s');
        if ($existing !== null) {
            $wpdb->update(
                $table,
                array('qty' => $qty, 'updated_at_gmt' => $now),
                array('id' => (int) $existing),
                array('%d', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'lot_id' => $lot_id,
                    'warehouse_id' => (int) $warehouse_id,
                    'room' => (string) $room,
                    'rack' => (string) $rack,
                    'shelf' => (string) $shelf,
                    'qty' => $qty,
                    'created_at_gmt' => $now,
                    'updated_at_gmt' => $now,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }
        return true;
    }

    private static function normalize_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // Accept both "YYYY-MM-DDTHH:MM:SSZ" and "YYYY-MM-DD HH:MM:SS".
        $value = str_replace('T', ' ', $value);
        $value = rtrim($value, 'Z');
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
