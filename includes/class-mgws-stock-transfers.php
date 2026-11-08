<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stateful stock transfers: draft -> sent -> received (or cancelled).
 *
 * A transfer is a header (`mg_stock_transfers`) plus lines
 * (`mg_stock_transfer_lines`). The stock movement happens in two steps:
 * `sent` takes the quantity out of the source warehouse, `received` puts it into
 * the destination. `cancelled` from `sent` compensates the source.
 *
 * The movement ledger (`mg_stock_moves`) stays the source of truth: every
 * transition writes its own rows with `source_type: stock_transfer` and
 * `source_id` = transfer id. The instant transfer route is untouched and
 * remains available for one-shot corrections.
 */
class MGWS_Stock_Transfers {
    private const ALLOWED_STATUSES = array('draft', 'sent', 'received', 'cancelled');

    public static function list($status = '', $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_stock_transfers();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $status = trim((string) $status);
        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = '';
        }
        if ($status !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    $status,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }
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
        $table = MGWS_DB::table_stock_transfers();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return self::with_lines($row);
    }

    public static function create($from_warehouse_id, $to_warehouse_id, $note, $created_by_user_id) {
        $from_warehouse_id = (int) $from_warehouse_id;
        $to_warehouse_id = (int) $to_warehouse_id;
        if ($from_warehouse_id <= 0 || $to_warehouse_id <= 0 || $from_warehouse_id === $to_warehouse_id) {
            return new WP_Error('mgws_bad_request', 'Source and destination warehouses must be different', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_transfers();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'from_warehouse_id' => $from_warehouse_id,
                'to_warehouse_id' => $to_warehouse_id,
                'status' => 'draft',
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the transfer', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function add_line($transfer_id, $product_id, $variation_id, $qty) {
        $transfer_id = (int) $transfer_id;
        $transfer = self::get($transfer_id);
        if (!$transfer) {
            return new WP_Error('mgws_not_found', 'Transfer not found', array('status' => 404));
        }
        if ($transfer['status'] !== 'draft') {
            return new WP_Error('mgws_conflict', 'Lines can only be added to a draft transfer', array('status' => 409));
        }
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $qty = (int) $qty;
        if ($product_id <= 0 || $qty <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product or quantity', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_stock_transfer_lines();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'transfer_id' => $transfer_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'qty' => $qty,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the transfer line', array('status' => 500));
        }
        return self::get($transfer_id);
    }

    public static function remove_line($line_id) {
        $line_id = (int) $line_id;
        global $wpdb;
        $lines_table = MGWS_DB::table_stock_transfer_lines();
        $line = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $lines_table, $line_id),
            ARRAY_A
        );
        if (!is_array($line)) {
            return new WP_Error('mgws_not_found', 'Transfer line not found', array('status' => 404));
        }
        $transfer = self::get((int) $line['transfer_id']);
        if (!$transfer || $transfer['status'] !== 'draft') {
            return new WP_Error('mgws_conflict', 'Lines can only be removed from a draft transfer', array('status' => 409));
        }
        $wpdb->delete($lines_table, array('id' => $line_id), array('%d'));
        return self::get((int) $line['transfer_id']);
    }

    /**
     * draft -> sent: takes the quantity out of the source warehouse.
     *
     * @return array|WP_Error
     */
    public static function send($transfer_id, $user_id) {
        $transfer = self::get($transfer_id);
        if (!$transfer) {
            return new WP_Error('mgws_not_found', 'Transfer not found', array('status' => 404));
        }
        if ($transfer['status'] !== 'draft') {
            return new WP_Error('mgws_conflict', 'Only a draft transfer can be sent', array('status' => 409));
        }
        $lines = $transfer['lines'];
        if (empty($lines)) {
            return new WP_Error('mgws_bad_request', 'A transfer needs at least one line', array('status' => 400));
        }

        global $wpdb;
        $from_warehouse_id = (int) $transfer['from_warehouse_id'];
        $to_warehouse_id = (int) $transfer['to_warehouse_id'];
        $from_site_id = (int) MGWS_DB::get_site_id_for_warehouse($from_warehouse_id);

        $wpdb->query('START TRANSACTION');

        $line_results = array();
        foreach ($lines as $line) {
            $product_id = (int) $line['product_id'];
            $variation_id = (int) $line['variation_id'];
            $qty = (int) $line['qty'];

            $level = self::resolve_level($from_warehouse_id, $product_id, $variation_id);
            $room = $level['room'];
            $rack = $level['rack'];
            $shelf = $level['shelf'];
            $before = MGWS_DB::get_level_row($from_warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
            $from_before = $before ? (int) $before['qty'] : 0;

            $res = MGWS_DB::apply_delta_level($from_warehouse_id, $product_id, $variation_id, -$qty, $room, $rack, $shelf);
            if (empty($res['ok'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', (string) ($res['message'] ?? __('Not enough stock in the source warehouse', 'mg-warehouse-stock')), array('status' => 409));
            }
            $unit_cost = $res['value_delta_cents'] === null
                ? null
                : MGWS_Cost_Plan::average_cents(abs((int) $res['value_delta_cents']), $qty);

            $movement_id = MGWS_DB::ensure_movement(
                'transfer-' . $transfer_id,
                'move',
                (int) $user_id,
                $from_site_id,
                'move',
                $transfer['note']
            );

            $out_row = MGWS_DB::insert_move(
                'out',
                $from_site_id,
                $from_warehouse_id,
                $product_id,
                $variation_id,
                $qty,
                $room,
                $rack,
                $shelf,
                (int) $user_id,
                0,
                'Stock transfer out',
                $from_warehouse_id,
                $to_warehouse_id,
                array(
                    'movement_id' => (int) $movement_id,
                    'stock_effect' => 'move',
                    'source_type' => 'stock_transfer',
                    'source_id' => $transfer_id,
                    'stock_before' => $from_before,
                    'stock_after' => $from_before - $qty,
                    'unit_cost_cents' => $unit_cost,
                    'value_delta_cents' => $res['value_delta_cents'],
                )
            );
            if (!$out_row) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_error', 'Unable to record transfer movement', array('status' => 500));
            }

            // Store the unit cost on the line for the receive step.
            $lines_table = MGWS_DB::table_stock_transfer_lines();
            $wpdb->update(
                $lines_table,
                array('unit_cost_cents' => $unit_cost, 'updated_at_gmt' => gmdate('Y-m-d H:i:s')),
                array('id' => (int) $line['id']),
                array('%d', '%s'),
                array('%d')
            );

            $line_results[] = array(
                'line_id' => (int) $line['id'],
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'qty' => $qty,
                'unit_cost_cents' => $unit_cost,
                'from_before' => $from_before,
                'from_after' => $from_before - $qty,
                'woo_delta' => -$qty,
                'warehouse_id' => $from_warehouse_id,
            );
        }

        // Mark sent.
        $transfers_table = MGWS_DB::table_stock_transfers();
        $wpdb->update(
            $transfers_table,
            array(
                'status' => 'sent',
                'sent_by_user_id' => (int) $user_id,
                'sent_at_gmt' => gmdate('Y-m-d H:i:s'),
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $transfer_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        $wpdb->query('COMMIT');

        // Apply Woo deltas after commit, best effort.
        foreach ($line_results as $lr) {
            MGWS_Woo_Stock::commit_delta((int) $lr['product_id'], (int) $lr['variation_id'], (int) $lr['woo_delta']);
        }

        return self::get($transfer_id);
    }

    /**
     * sent -> received: puts the quantity into the destination warehouse.
     */
    public static function receive($transfer_id, $user_id) {
        $transfer = self::get($transfer_id);
        if (!$transfer) {
            return new WP_Error('mgws_not_found', 'Transfer not found', array('status' => 404));
        }
        if ($transfer['status'] !== 'sent') {
            return new WP_Error('mgws_conflict', 'Only a sent transfer can be received', array('status' => 409));
        }
        $lines = $transfer['lines'];
        if (empty($lines)) {
            return new WP_Error('mgws_bad_request', 'A transfer needs at least one line', array('status' => 400));
        }

        global $wpdb;
        $to_warehouse_id = (int) $transfer['to_warehouse_id'];
        $to_site_id = (int) MGWS_DB::get_site_id_for_warehouse($to_warehouse_id);

        $wpdb->query('START TRANSACTION');

        foreach ($lines as $line) {
            $product_id = (int) $line['product_id'];
            $variation_id = (int) $line['variation_id'];
            $qty = (int) $line['qty'];
            $unit_cost = $line['unit_cost_cents'] === null ? null : (int) $line['unit_cost_cents'];

            $level = self::resolve_level($to_warehouse_id, $product_id, $variation_id);
            $room = $level['room'];
            $rack = $level['rack'];
            $shelf = $level['shelf'];
            $before = MGWS_DB::get_level_row($to_warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
            $to_before = $before ? (int) $before['qty'] : 0;

            $res = MGWS_DB::apply_delta_level($to_warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $unit_cost);
            if (empty($res['ok'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', (string) ($res['message'] ?? __('Could not record the entry into the destination warehouse', 'mg-warehouse-stock')), array('status' => 409));
            }

            $movement_id = MGWS_DB::ensure_movement(
                'transfer-' . $transfer_id,
                'move',
                (int) $user_id,
                $to_site_id,
                'move',
                $transfer['note']
            );

            $in_row = MGWS_DB::insert_move(
                'in',
                $to_site_id,
                $to_warehouse_id,
                $product_id,
                $variation_id,
                $qty,
                $room,
                $rack,
                $shelf,
                (int) $user_id,
                0,
                'Stock transfer in',
                (int) $transfer['from_warehouse_id'],
                $to_warehouse_id,
                array(
                    'movement_id' => (int) $movement_id,
                    'stock_effect' => 'move',
                    'source_type' => 'stock_transfer',
                    'source_id' => $transfer_id,
                    'stock_before' => $to_before,
                    'stock_after' => $to_before + $qty,
                    'unit_cost_cents' => $unit_cost,
                    'value_delta_cents' => $res['value_delta_cents'],
                )
            );
            if (!$in_row) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_error', 'Unable to record transfer movement', array('status' => 500));
            }

            MGWS_Woo_Stock::commit_delta($product_id, $variation_id, $qty);
        }

        $transfers_table = MGWS_DB::table_stock_transfers();
        $wpdb->update(
            $transfers_table,
            array(
                'status' => 'received',
                'received_by_user_id' => (int) $user_id,
                'received_at_gmt' => gmdate('Y-m-d H:i:s'),
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $transfer_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        $wpdb->query('COMMIT');

        return self::get($transfer_id);
    }

    /**
     * Cancel a draft or sent transfer. A sent transfer is compensated on the
     * source warehouse.
     */
    public static function cancel($transfer_id, $user_id) {
        $transfer = self::get($transfer_id);
        if (!$transfer) {
            return new WP_Error('mgws_not_found', 'Transfer not found', array('status' => 404));
        }
        if (!in_array($transfer['status'], array('draft', 'sent'), true)) {
            return new WP_Error('mgws_conflict', 'Only a draft or sent transfer can be cancelled', array('status' => 409));
        }

        global $wpdb;

        if ($transfer['status'] === 'sent') {
            $from_warehouse_id = (int) $transfer['from_warehouse_id'];
            $to_warehouse_id = (int) $transfer['to_warehouse_id'];
            $from_site_id = (int) MGWS_DB::get_site_id_for_warehouse($from_warehouse_id);

            $wpdb->query('START TRANSACTION');

            foreach ($transfer['lines'] as $line) {
                $product_id = (int) $line['product_id'];
                $variation_id = (int) $line['variation_id'];
                $qty = (int) $line['qty'];
                $unit_cost = $line['unit_cost_cents'] === null ? null : (int) $line['unit_cost_cents'];

                $level = self::resolve_level($from_warehouse_id, $product_id, $variation_id);
                $room = $level['room'];
                $rack = $level['rack'];
                $shelf = $level['shelf'];
                $before = MGWS_DB::get_level_row($from_warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
                $from_before = $before ? (int) $before['qty'] : 0;

                $res = MGWS_DB::apply_delta_level($from_warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $unit_cost);
                if (empty($res['ok'])) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('mgws_conflict', (string) ($res['message'] ?? __('Could not compensate the source warehouse', 'mg-warehouse-stock')), array('status' => 409));
                }

                $movement_id = MGWS_DB::ensure_movement(
                    'transfer-' . $transfer_id,
                    'move',
                    (int) $user_id,
                    $from_site_id,
                    'move',
                    $transfer['note']
                );

                $comp_row = MGWS_DB::insert_move(
                    'in',
                    $from_site_id,
                    $from_warehouse_id,
                    $product_id,
                    $variation_id,
                    $qty,
                    $room,
                    $rack,
                    $shelf,
                    (int) $user_id,
                    0,
                    'Stock transfer cancelled (compensation)',
                    $from_warehouse_id,
                    $to_warehouse_id,
                    array(
                        'movement_id' => (int) $movement_id,
                        'stock_effect' => 'move',
                        'source_type' => 'stock_transfer',
                        'source_id' => $transfer_id,
                        'stock_before' => $from_before,
                        'stock_after' => $from_before + $qty,
                        'unit_cost_cents' => $unit_cost,
                        'value_delta_cents' => $res['value_delta_cents'],
                    )
                );
                if (!$comp_row) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('mgws_error', 'Unable to record compensation movement', array('status' => 500));
                }

                MGWS_Woo_Stock::commit_delta($product_id, $variation_id, $qty);
            }

            $wpdb->query('COMMIT');
        }

        $transfers_table = MGWS_DB::table_stock_transfers();
        global $wpdb;
        $wpdb->update(
            $transfers_table,
            array(
                'status' => 'cancelled',
                'cancelled_at_gmt' => gmdate('Y-m-d H:i:s'),
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $transfer_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return self::get($transfer_id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $transfer = self::get($id);
        if (!$transfer) {
            return new WP_Error('mgws_not_found', 'Transfer not found', array('status' => 404));
        }
        if ($transfer['status'] !== 'draft') {
            return new WP_Error('mgws_conflict', 'Only a draft transfer can be deleted', array('status' => 409));
        }
        global $wpdb;
        $lines_table = MGWS_DB::table_stock_transfer_lines();
        $wpdb->delete($lines_table, array('transfer_id' => $id), array('%d'));
        $transfers_table = MGWS_DB::table_stock_transfers();
        $wpdb->delete($transfers_table, array('id' => $id), array('%d'));
        return true;
    }

    // ---- Internals

    private static function with_lines(array $row) {
        global $wpdb;
        $lines_table = MGWS_DB::table_stock_transfer_lines();
        $lines = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE transfer_id = %d ORDER BY id ASC', $lines_table, (int) $row['id']),
            ARRAY_A
        );
        $row['lines'] = is_array($lines) ? $lines : array();
        return $row;
    }

    /**
     * Resolve the location to move on: the first existing level for the item in
     * the warehouse, or an empty location (which apply_delta_level will create).
     */
    private static function resolve_level($warehouse_id, $product_id, $variation_id) {
        global $wpdb;
        $levels = MGWS_DB::table_levels();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT room, rack, shelf FROM %i WHERE warehouse_id = %d AND product_id = %d AND variation_id = %d ORDER BY id ASC LIMIT 1',
                $levels,
                (int) $warehouse_id,
                (int) $product_id,
                (int) $variation_id
            ),
            ARRAY_A
        );
        if (is_array($row)) {
            return array(
                'room' => (string) ($row['room'] ?? ''),
                'rack' => (string) ($row['rack'] ?? ''),
                'shelf' => (string) ($row['shelf'] ?? ''),
            );
        }
        return array('room' => '', 'rack' => '', 'shelf' => '');
    }
}
