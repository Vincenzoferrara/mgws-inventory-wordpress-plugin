<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return merchandise authorisation: requested -> approved -> received ->
 * refunded, or rejected from requested/approved.
 *
 * A return is a header (`mg_returns`) plus lines (`mg_return_lines`). It is a
 * trail, not a stock writer: the stock total of a return belongs to WooCommerce
 * (the refund/order restock), so MGWS never adds the returned quantity again.
 * `receive` only marks the goods as physically back and leaves the stock to the
 * WooCommerce flow; the existing reconciler then mirrors the new total into the
 * levels. Writing an `in` movement here would double count the goods.
 *
 * `close` does not refund either: the refund stays a manual WooCommerce action.
 */
class MGWS_Returns {
    private const ALLOWED_STATUSES = array('requested', 'approved', 'received', 'refunded', 'rejected');

    public static function list($status = '', $order_id = 0, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $status = trim((string) $status);
        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = '';
        }
        $order_id = (int) $order_id;

        $where = array();
        $params = array($table);
        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($order_id > 0) {
            $where[] = 'order_id = %d';
            $params[] = $order_id;
        }
        $sql = 'SELECT * FROM %i';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = self::with_lines($row);
        }
        return $out;
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return self::with_lines($row);
    }

    public static function create($order_id, $customer_id, $warehouse_id, $reason, $note, $created_by_user_id) {
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id' => max(0, (int) $order_id),
                'customer_id' => max(0, (int) $customer_id),
                'warehouse_id' => max(0, (int) $warehouse_id),
                'status' => 'requested',
                'reason' => $reason !== null && $reason !== '' ? (string) $reason : null,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the return', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function add_line($return_id, $product_id, $variation_id, $qty) {
        $return_id = (int) $return_id;
        $return = self::get($return_id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if ($return['status'] !== 'requested') {
            return new WP_Error('mgws_conflict', 'Lines can only be added to a requested return', array('status' => 409));
        }
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $qty = (int) $qty;
        if ($product_id <= 0 || $qty <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product or quantity', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_return_lines();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'return_id' => $return_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'qty' => $qty,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the return line', array('status' => 500));
        }
        return self::get($return_id);
    }

    public static function remove_line($line_id) {
        $line_id = (int) $line_id;
        global $wpdb;
        $lines_table = MGWS_DB::table_return_lines();
        $line = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $lines_table, $line_id),
            ARRAY_A
        );
        if (!is_array($line)) {
            return new WP_Error('mgws_not_found', 'Return line not found', array('status' => 404));
        }
        $return = self::get((int) $line['return_id']);
        if (!$return || $return['status'] !== 'requested') {
            return new WP_Error('mgws_conflict', 'Lines can only be removed from a requested return', array('status' => 409));
        }
        $wpdb->delete($lines_table, array('id' => $line_id), array('%d'));
        return self::get((int) $line['return_id']);
    }

    /**
     * requested -> approved.
     */
    public static function approve($return_id, $user_id) {
        $return = self::get($return_id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if ($return['status'] !== 'requested') {
            return new WP_Error('mgws_conflict', 'Only a requested return can be approved', array('status' => 409));
        }
        if (empty($return['lines'])) {
            return new WP_Error('mgws_bad_request', 'A return needs at least one line', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            $table,
            array(
                'status' => 'approved',
                'approved_by_user_id' => (int) $user_id,
                'approved_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('id' => (int) $return_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
        return self::get($return_id);
    }

    /**
     * approved -> received: the goods are physically back.
     *
     * Stock-neutral on purpose: the total is owned by WooCommerce (refund/order
     * restock) and MGWS only mirrors the distribution through the reconciler.
     */
    public static function receive($return_id, $user_id) {
        $return = self::get($return_id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if ($return['status'] !== 'approved') {
            return new WP_Error('mgws_conflict', 'Only an approved return can be received', array('status' => 409));
        }
        if (empty($return['lines'])) {
            return new WP_Error('mgws_bad_request', 'A return needs at least one line', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            $table,
            array(
                'status' => 'received',
                'received_by_user_id' => (int) $user_id,
                'received_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('id' => (int) $return_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
        return self::get($return_id);
    }

    /**
     * received -> refunded (terminal). The refund itself is manual.
     */
    public static function close($return_id, $user_id) {
        $return = self::get($return_id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if ($return['status'] !== 'received') {
            return new WP_Error('mgws_conflict', 'Only a received return can be closed', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $wpdb->update(
            $table,
            array(
                'status' => 'refunded',
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => (int) $return_id),
            array('%s', '%s'),
            array('%d')
        );
        return self::get($return_id);
    }

    /**
     * requested/approved -> rejected. No stock movement.
     */
    public static function reject($return_id, $user_id) {
        $return = self::get($return_id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if (!in_array($return['status'], array('requested', 'approved'), true)) {
            return new WP_Error('mgws_conflict', 'Only a requested or approved return can be rejected', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_returns();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            $table,
            array(
                'status' => 'rejected',
                'rejected_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('id' => (int) $return_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        return self::get($return_id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $return = self::get($id);
        if (!$return) {
            return new WP_Error('mgws_not_found', 'Return not found', array('status' => 404));
        }
        if ($return['status'] !== 'requested') {
            return new WP_Error('mgws_conflict', 'Only a requested return can be deleted', array('status' => 409));
        }
        global $wpdb;
        $lines_table = MGWS_DB::table_return_lines();
        $wpdb->delete($lines_table, array('return_id' => $id), array('%d'));
        $table = MGWS_DB::table_returns();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    // ---- Internals

    private static function with_lines(array $row) {
        global $wpdb;
        $lines_table = MGWS_DB::table_return_lines();
        $lines = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE return_id = %d ORDER BY id ASC', $lines_table, (int) $row['id']),
            ARRAY_A
        );
        $row['lines'] = is_array($lines) ? $lines : array();
        return $row;
    }
}
