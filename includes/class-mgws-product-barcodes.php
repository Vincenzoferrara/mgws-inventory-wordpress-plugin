<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product barcodes (EAN/UPC): stores a barcode per product/variation.
 */
class MGWS_Product_Barcodes {
    public static function get($product_id, $variation_id = 0) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_product_barcodes();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE product_id = %d AND variation_id = %d', $table, $product_id, (int) $variation_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The row that owns a scanned code, or null.
     */
    public static function find_by_barcode($barcode) {
        $barcode = (string) $barcode;
        if ($barcode === '') {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_product_barcodes();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE barcode = %s LIMIT 1', $table, $barcode), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function upsert($product_id, $variation_id, $barcode, $barcode_type, $created_by_user_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return new WP_Error('mgws_bad_request', 'product_id is required', array('status' => 400));
        }
        $barcode = sanitize_text_field((string) $barcode);
        if ($barcode === '') {
            return new WP_Error('mgws_bad_request', 'barcode is required', array('status' => 400));
        }
        $barcode_type = sanitize_key((string) $barcode_type);
        if (!in_array($barcode_type, array('ean13', 'upca', 'ean8', 'code128'), true)) {
            $barcode_type = 'ean13';
        }
        // One code, one item: a scan must resolve to a single product.
        $owner = self::find_by_barcode($barcode);
        if (is_array($owner) && ((int) $owner['product_id'] !== $product_id || (int) $owner['variation_id'] !== (int) $variation_id)) {
            return new WP_Error('mgws_conflict', 'Barcode already assigned to another product', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_product_barcodes();
        $now = gmdate('Y-m-d H:i:s');
        $existing = self::get($product_id, (int) $variation_id);
        if (is_array($existing)) {
            $wpdb->update(
                $table,
                array(
                    'barcode' => $barcode,
                    'barcode_type' => $barcode_type,
                    'updated_at_gmt' => $now,
                ),
                array('id' => (int) $existing['id']),
                array('%s', '%s', '%s'),
                array('%d')
            );
            return self::get($product_id, (int) $variation_id);
        }
        $inserted = $wpdb->insert(
            $table,
            array(
                'product_id' => $product_id,
                'variation_id' => (int) $variation_id,
                'barcode' => $barcode,
                'barcode_type' => $barcode_type,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save barcode', array('status' => 500));
        }
        return self::get($product_id, (int) $variation_id);
    }

    public static function delete($product_id, $variation_id = 0) {
        $product_id = (int) $product_id;
        $row = self::get($product_id, (int) $variation_id);
        if (!is_array($row)) {
            return new WP_Error('mgws_not_found', 'Barcode not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_product_barcodes();
        $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
        return true;
    }
}