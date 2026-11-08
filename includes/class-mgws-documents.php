<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Documents (DDT, invoices, credit notes): metadata storage. PDF generation and
 * email dispatch are handled by the caller.
 */
class MGWS_Documents {
    public static function list($document_type = '', $reference_type = '', $reference_id = 0, $status = '', $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_documents();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array();
        if ($document_type !== '') {
            $where[] = 'document_type = %s';
            $params[] = sanitize_key((string) $document_type);
        }
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
        $table = MGWS_DB::table_documents();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function create($document_type, $document_number, $reference_type, $reference_id, $note, $created_by_user_id) {
        $document_type = sanitize_key((string) $document_type);
        if ($document_type === '') {
            return new WP_Error('mgws_bad_request', 'document_type is required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_documents();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'document_type' => $document_type,
                'document_number' => sanitize_text_field((string) $document_number),
                'reference_type' => sanitize_key((string) $reference_type),
                'reference_id' => (int) $reference_id,
                'status' => 'draft',
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save document', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update_status($id, $status, $issued_at_gmt = null, $pdf_path = null) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Document not found', array('status' => 404));
        }
        $allowed = array('draft', 'issued', 'sent', 'cancelled');
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('mgws_bad_request', 'Invalid status', array('status' => 400));
        }
        // Once issued a document stays on the record: it never goes back to
        // draft (which delete() would then erase), and cancelled is final.
        $current = (string) ($row['status'] ?? 'draft');
        if ($current !== $status && ($current === 'cancelled' || ($current !== 'draft' && $status === 'draft'))) {
            return new WP_Error('mgws_conflict', 'Invalid document status transition', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_documents();
        $data = array(
            'status' => $status,
            'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
        );
        if ($issued_at_gmt !== null) {
            $data['issued_at_gmt'] = $issued_at_gmt !== '' ? $issued_at_gmt : null;
        }
        if ($pdf_path !== null) {
            $data['pdf_path'] = (string) $pdf_path;
        }
        $wpdb->update($table, $data, array('id' => $id), array('%s', '%s'), array('%d'));
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Document not found', array('status' => 404));
        }
        // An issued or sent document is part of the fiscal record: it can be
        // cancelled, never erased. Only a draft has never left the shop.
        if ((string) ($row['status'] ?? '') !== 'draft') {
            return new WP_Error('mgws_conflict', 'Only draft documents can be deleted: cancel it instead', array('status' => 409));
        }
        global $wpdb;
        $table = MGWS_DB::table_documents();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }
}