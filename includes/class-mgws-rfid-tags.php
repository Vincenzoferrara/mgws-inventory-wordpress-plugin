<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RFID EPC registry: normalized EPC -> product/variation.
 *
 * The table is a manual association written by the product editor. The RFID
 * scan flow only reads it.
 */
class MGWS_Rfid_Tags {
    /**
     * Normalizes a raw EPC to the key stored in the registry.
     *
     * @return string|WP_Error
     */
    public static function normalize($epc) {
        if (!is_scalar($epc)) {
            return new WP_Error('mgws_bad_request', 'EPC is required', array('status' => 400));
        }
        $epc = trim((string) $epc);
        $epc = str_ireplace(array('urn:epc:tag:sgtin-96:', 'urn:epc:raw:'), '', $epc);
        $epc = preg_replace('/[\s-]+/', '', $epc);
        $epc = strtoupper((string) $epc);
        if ($epc === '' || strlen($epc) > 128) {
            return new WP_Error('mgws_bad_request', 'EPC must be between 1 and 128 characters', array('status' => 400));
        }
        return $epc;
    }

    private static function nullable_id($value) {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    /**
     * @return array|WP_Error
     */
    public static function upsert($epc, $productId, $variationId) {
        $epc = self::normalize($epc);
        if (is_wp_error($epc)) {
            return $epc;
        }
        $productId = self::nullable_id($productId);
        $variationId = self::nullable_id($variationId);
        if (function_exists('wc_get_product')) {
            if ($productId !== null && !wc_get_product($productId)) {
                return new WP_Error('mgws_bad_request', 'Invalid product_id', array('status' => 400));
            }
            if ($variationId !== null) {
                $variation = wc_get_product($variationId);
                if (!$variation) {
                    return new WP_Error('mgws_bad_request', 'Invalid variation_id', array('status' => 400));
                }
                if ($productId !== null && method_exists($variation, 'get_parent_id')
                    && (int) $variation->get_parent_id() !== $productId) {
                    return new WP_Error('mgws_bad_request', 'variation_id does not belong to product_id', array('status' => 400));
                }
            }
        }

        global $wpdb;
        $table = MGWS_DB::table_rfid_tags();
        // NULL ids are written literally; non-null ids are prepared numbers.
        $product_sql = $productId === null ? 'NULL' : $wpdb->prepare('%d', $productId);
        $variation_sql = $variationId === null ? 'NULL' : $wpdb->prepare('%d', $variationId);
        $saved = $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (epc, product_id, variation_id) VALUES (%s, {$product_sql}, {$variation_sql})"
            . " ON DUPLICATE KEY UPDATE product_id = {$product_sql}, variation_id = {$variation_sql}",
            $table,
            $epc
        ));
        if ($saved === false) {
            return new WP_Error('mgws_db_error', 'Could not save RFID tag', array('status' => 500));
        }
        return self::get($epc);
    }

    /**
     * @return array|null|WP_Error
     */
    public static function get($epc) {
        $epc = self::normalize($epc);
        if (is_wp_error($epc)) {
            return $epc;
        }
        global $wpdb;
        $table = MGWS_DB::table_rfid_tags();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE epc = %s LIMIT 1', $table, $epc), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * One prepared IN query; keys of the returned map are normalized EPCs.
     *
     * @return array|WP_Error
     */
    public static function get_many(array $epcs) {
        $normalized = array();
        foreach ($epcs as $epc) {
            $epc = self::normalize($epc);
            if (!is_wp_error($epc)) {
                $normalized[$epc] = $epc;
            }
        }
        if (empty($normalized)) {
            return array();
        }
        global $wpdb;
        $table = MGWS_DB::table_rfid_tags();
        $placeholders = implode(', ', array_fill(0, count($normalized), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE epc IN (' . $placeholders . ')',
            array_merge(array($table), array_values($normalized))
        ), ARRAY_A);
        $map = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row) && isset($row['epc'])) {
                $map[(string) $row['epc']] = $row;
            }
        }
        return $map;
    }

    /**
     * EPCs assigned to a product; a variation id narrows the result.
     *
     * @return array
     */
    public static function list_for_product($productId, $variationId = 0) {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return array();
        }
        global $wpdb;
        $table = MGWS_DB::table_rfid_tags();
        $sql = 'SELECT * FROM %i WHERE product_id = %d';
        $params = array($table, $productId);
        $variationId = (int) $variationId;
        if ($variationId > 0) {
            $sql .= ' AND variation_id = %d';
            $params[] = $variationId;
        }
        $sql .= ' ORDER BY epc ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return true|WP_Error
     */
    public static function delete($epc) {
        $epc = self::normalize($epc);
        if (is_wp_error($epc)) {
            return $epc;
        }
        if (!is_array(self::get($epc))) {
            return new WP_Error('mgws_not_found', 'RFID tag not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_rfid_tags();
        $deleted = $wpdb->delete($table, array('epc' => $epc), array('%s'));
        if ($deleted === false) {
            return new WP_Error('mgws_db_error', 'Could not delete RFID tag', array('status' => 500));
        }
        return true;
    }
}
