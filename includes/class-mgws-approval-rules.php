<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Approval rules: a threshold above which a document type requires a capability.
 *
 * The check is stateless and creates no record: it only answers "does this
 * document need approval, and which capability?". The approval workflow (who,
 * when, with notification) is a separate concern.
 */
class MGWS_Approval_Rules {
    public static function list($document_type = '', $active = null, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_approval_rules();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $args = array();
        $document_type = trim((string) $document_type);
        if ($document_type !== '') {
            $where[] = 'document_type = %s';
            $args[] = sanitize_key($document_type);
        }
        if ($active !== null) {
            $where[] = 'active = %d';
            $args[] = (int) $active ? 1 : 0;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY document_type ASC, threshold_cents ASC, id ASC LIMIT %d OFFSET %d';
        $args = array_merge(array($table), $args, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_approval_rules();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public static function create($document_type, $threshold_cents, $required_capability, $note, $created_by_user_id) {
        $document_type = sanitize_key((string) $document_type);
        if ($document_type === '') {
            return new WP_Error('mgws_bad_request', 'document_type is required', array('status' => 400));
        }
        $required_capability = sanitize_key((string) $required_capability);
        if ($required_capability === '') {
            return new WP_Error('mgws_bad_request', 'required_capability is required', array('status' => 400));
        }
        $threshold_cents = max(0, (int) $threshold_cents);

        global $wpdb;
        $table = MGWS_DB::table_approval_rules();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'document_type' => $document_type,
                'threshold_cents' => $threshold_cents,
                'required_capability' => $required_capability,
                'active' => 1,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the approval rule', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function update($id, $document_type, $threshold_cents, $required_capability, $active, $note) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Approval rule not found', array('status' => 404));
        }
        $document_type = sanitize_key((string) $document_type);
        if ($document_type === '') {
            return new WP_Error('mgws_bad_request', 'document_type is required', array('status' => 400));
        }
        $required_capability = sanitize_key((string) $required_capability);
        if ($required_capability === '') {
            return new WP_Error('mgws_bad_request', 'required_capability is required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_approval_rules();
        $wpdb->update(
            $table,
            array(
                'document_type' => $document_type,
                'threshold_cents' => max(0, (int) $threshold_cents),
                'required_capability' => $required_capability,
                'active' => (int) $active ? 1 : 0,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $id),
            array('%s', '%d', '%s', '%d', '%s', '%s'),
            array('%d')
        );
        return self::get($id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Approval rule not found', array('status' => 404));
        }
        global $wpdb;
        $table = MGWS_DB::table_approval_rules();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Stateless check: does a document of this type and amount require approval?
     *
     * Returns ['required' => bool, 'capability' => string|null, 'rule_id' => int|null].
     * Among the active rules for the type whose threshold <= amount, the one with
     * the highest threshold wins (the most restrictive that still applies). If
     * none applies, no approval is required.
     */
    public static function needs_approval($document_type, $amount_cents) {
        $document_type = sanitize_key((string) $document_type);
        $amount_cents = (int) $amount_cents;
        $rules = self::list($document_type, true, 500, 0);
        $winner = null;
        foreach ($rules as $rule) {
            $threshold = (int) $rule['threshold_cents'];
            if ($threshold <= $amount_cents) {
                if ($winner === null || (int) $rule['threshold_cents'] > (int) $winner['threshold_cents']) {
                    $winner = $rule;
                }
            }
        }
        if ($winner === null) {
            return array('required' => false, 'capability' => null, 'rule_id' => null);
        }
        return array(
            'required' => true,
            'capability' => (string) $winner['required_capability'],
            'rule_id' => (int) $winner['id'],
        );
    }
}
