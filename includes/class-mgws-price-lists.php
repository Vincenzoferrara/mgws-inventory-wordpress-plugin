<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * B2B price lists: a named set of per-item prices, optionally scoped to a
 * customer or a supplier, with a validity window.
 *
 * A price list is not the WooCommerce retail price (`_price`): it is a separate
 * price consulted by the B2B portal and the app. It carries no stock movement
 * and no cost; the cost authority stays `mg_product_costs`.
 */
class MGWS_Price_Lists {
    public static function list($customer_id = 0, $supplier_id = 0, $active = null, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_price_lists();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $args = array();
        if ((int) $customer_id > 0) {
            $where[] = 'customer_id = %d';
            $args[] = (int) $customer_id;
        }
        if ((int) $supplier_id > 0) {
            $where[] = 'supplier_id = %d';
            $args[] = (int) $supplier_id;
        }
        if ($active !== null) {
            $where[] = 'active = %d';
            $args[] = (int) $active ? 1 : 0;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $args = array_merge(array($table), $args, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = self::with_items($row);
        }
        return $out;
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_price_lists();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return self::with_items($row);
    }

    public static function create($name, $customer_id, $supplier_id, $currency, $valid_from_gmt, $valid_to_gmt, $note, $created_by_user_id) {
        $name = trim(sanitize_text_field((string) $name));
        if ($name === '') {
            return new WP_Error('mgws_bad_request', 'Name is required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_price_lists();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'name' => $name,
                'customer_id' => (int) $customer_id,
                'supplier_id' => (int) $supplier_id,
                'currency' => self::sanitize_currency($currency),
                'valid_from_gmt' => self::normalize_datetime($valid_from_gmt),
                'valid_to_gmt' => self::normalize_datetime($valid_to_gmt),
                'active' => 1,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the price list', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update($id, $name, $customer_id, $supplier_id, $currency, $valid_from_gmt, $valid_to_gmt, $active, $note) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Price list not found', array('status' => 404));
        }
        $name = trim(sanitize_text_field((string) $name));
        if ($name === '') {
            return new WP_Error('mgws_bad_request', 'Name is required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_price_lists();
        $wpdb->update(
            $table,
            array(
                'name' => $name,
                'customer_id' => (int) $customer_id,
                'supplier_id' => (int) $supplier_id,
                'currency' => self::sanitize_currency($currency),
                'valid_from_gmt' => self::normalize_datetime($valid_from_gmt),
                'valid_to_gmt' => self::normalize_datetime($valid_to_gmt),
                'active' => (int) $active ? 1 : 0,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $id),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Price list not found', array('status' => 404));
        }
        global $wpdb;
        $items_table = MGWS_DB::table_price_list_items();
        $wpdb->delete($items_table, array('price_list_id' => $id), array('%d'));
        $table = MGWS_DB::table_price_lists();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    public static function upsert_item($price_list_id, $product_id, $variation_id, $price_cents, $min_qty) {
        $price_list_id = (int) $price_list_id;
        if (!self::get($price_list_id)) {
            return new WP_Error('mgws_not_found', 'Price list not found', array('status' => 404));
        }
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        if ($product_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product', array('status' => 400));
        }
        $price_cents = (int) $price_cents;
        if ($price_cents < 0) {
            return new WP_Error('mgws_bad_request', 'Price cannot be negative', array('status' => 400));
        }
        $min_qty = max(1, (int) $min_qty);

        global $wpdb;
        $table = MGWS_DB::table_price_list_items();
        $now = gmdate('Y-m-d H:i:s');
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE price_list_id = %d AND product_id = %d AND variation_id = %d',
                $table,
                $price_list_id,
                $product_id,
                $variation_id
            )
        );
        if ($existing !== null) {
            $wpdb->update(
                $table,
                array(
                    'price_cents' => $price_cents,
                    'min_qty' => $min_qty,
                    'updated_at_gmt' => $now,
                ),
                array('id' => (int) $existing),
                array('%d', '%d', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'price_list_id' => $price_list_id,
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'price_cents' => $price_cents,
                    'min_qty' => $min_qty,
                    'created_at_gmt' => $now,
                    'updated_at_gmt' => $now,
                ),
                array('%d', '%d', '%d', '%d', '%d', '%s', '%s')
            );
        }
        return self::get($price_list_id);
    }

    public static function remove_item($price_list_id, $item_id) {
        $price_list_id = (int) $price_list_id;
        if (!self::get($price_list_id)) {
            return new WP_Error('mgws_not_found', 'Price list not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_price_list_items();
        $wpdb->delete($table, array('id' => (int) $item_id, 'price_list_id' => $price_list_id), array('%d', '%d'));
        return self::get($price_list_id);
    }

    // ---- Internals

    private static function with_items(array $row) {
        global $wpdb;
        $items_table = MGWS_DB::table_price_list_items();
        $items = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE price_list_id = %d ORDER BY id ASC', $items_table, (int) $row['id']),
            ARRAY_A
        );
        $row['items'] = is_array($items) ? $items : array();
        return $row;
    }

    private static function sanitize_currency($currency) {
        $currency = strtoupper(trim((string) $currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'EUR';
        }
        return $currency;
    }

    private static function normalize_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $value = rtrim($value, 'Z');
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
