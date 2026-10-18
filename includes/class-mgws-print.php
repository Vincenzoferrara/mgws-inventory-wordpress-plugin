<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Print library: the label, sign and report templates of the shop, the
 * categories they are grouped in and the paper formats they print on.
 *
 * Every item is shared by the whole site and stored in a site option, one per
 * collection. The app owns the content of `data_json` (a template layout, a
 * paper format, a category): the server only checks that it is a JSON object
 * of a reasonable size and cleans its strings, so the app can evolve the
 * layout without a plugin release. The content travels as a JSON string
 * because WordPress decodes request bodies into PHP arrays, where an empty
 * object `{}` would come back as an empty list `[]`.
 *
 * The app loads its default library once, the first time a manager opens the
 * print section on a site. From then on the defaults are ordinary items: they
 * can be changed or deleted and are never loaded again.
 */
class MGWS_Print {
    public const COLLECTIONS = array(
        'templates' => 'mgws_print_templates',
        'paper-formats' => 'mgws_print_paper_formats',
        'categories' => 'mgws_print_categories',
    );
    public const DEFAULTS_LOADED_OPTION = 'mgws_print_defaults_loaded';

    private const MAX_ITEMS_PER_COLLECTION = 300;
    private const MAX_DATA_BYTES = 262144;
    private const MAX_DEPTH = 12;
    private const ITEM_ID_PATTERN = '/^[a-z0-9_-]{1,64}$/';
    private const DATA_KEY_PATTERN = '/^[A-Za-z0-9_.\-\[\]]{1,64}$/';

    public static function can_manage() {
        return current_user_can('manage_options') || current_user_can('manage_woocommerce');
    }

    public static function option_names() {
        return array_merge(array_values(self::COLLECTIONS), array(self::DEFAULTS_LOADED_OPTION));
    }

    public static function library() {
        $out = array(
            'ok' => true,
            'can_manage' => self::can_manage(),
            'defaults_loaded' => (bool) get_option(self::DEFAULTS_LOADED_OPTION, false),
        );
        foreach (self::COLLECTIONS as $collection => $option) {
            $out[str_replace('-', '_', $collection)] = array_values(self::items($collection));
        }
        return $out;
    }

    public static function put($collection, $item_id, $body) {
        if (!isset(self::COLLECTIONS[$collection])) {
            return new WP_Error('mgws_print_unknown_collection', 'Unknown print collection', array('status' => 404));
        }
        $record = self::record_from_body($item_id, $body);
        if (is_wp_error($record)) {
            return $record;
        }
        $items = self::items($collection);
        if (!isset($items[$item_id]) && count($items) >= self::MAX_ITEMS_PER_COLLECTION) {
            return new WP_Error('mgws_bad_request', 'too many items in the collection', array('status' => 400));
        }
        $items[$item_id] = $record;
        update_option(self::COLLECTIONS[$collection], $items, false);
        return array('ok' => true, 'item' => $record);
    }

    public static function delete($collection, $item_id) {
        if (!isset(self::COLLECTIONS[$collection])) {
            return new WP_Error('mgws_print_unknown_collection', 'Unknown print collection', array('status' => 404));
        }
        $items = self::items($collection);
        if (!isset($items[$item_id])) {
            return new WP_Error('mgws_print_item_not_found', 'Print item not found', array('status' => 404));
        }
        unset($items[$item_id]);
        update_option(self::COLLECTIONS[$collection], $items, false);
        return array('ok' => true, 'deleted' => $item_id);
    }

    /**
     * Stores the default library sent by the app. Accepted only once per
     * site: afterwards the defaults are ordinary items the shop may have
     * changed or deleted on purpose.
     */
    public static function load_defaults($body) {
        if (get_option(self::DEFAULTS_LOADED_OPTION, false)) {
            return new WP_Error('mgws_print_defaults_already_loaded', 'The default print library was already loaded', array('status' => 409));
        }
        if (!is_array($body)) {
            return new WP_Error('mgws_bad_request', 'body must be an object', array('status' => 400));
        }
        $prepared = array();
        foreach (self::COLLECTIONS as $collection => $option) {
            $key = str_replace('-', '_', $collection);
            $incoming = $body[$key] ?? array();
            if (!is_array($incoming)) {
                return new WP_Error('mgws_bad_request', $key . ' must be a list', array('status' => 400));
            }
            $items = self::items($collection);
            foreach ($incoming as $entry) {
                if (!is_array($entry)) {
                    return new WP_Error('mgws_bad_request', $key . ' entries must be objects', array('status' => 400));
                }
                $item_id = (string) ($entry['id'] ?? '');
                $record = self::record_from_body($item_id, $entry);
                if (is_wp_error($record)) {
                    return $record;
                }
                // Items created before the defaults arrived win: they are the
                // shop's own work.
                if (!isset($items[$item_id])) {
                    $items[$item_id] = $record;
                }
            }
            if (count($items) > self::MAX_ITEMS_PER_COLLECTION) {
                return new WP_Error('mgws_bad_request', 'too many items in ' . $key, array('status' => 400));
            }
            $prepared[$option] = $items;
        }
        foreach ($prepared as $option => $items) {
            update_option($option, $items, false);
        }
        update_option(self::DEFAULTS_LOADED_OPTION, true, false);
        return self::library();
    }

    private static function items($collection) {
        $stored = get_option(self::COLLECTIONS[$collection], array());
        if (!is_array($stored)) {
            return array();
        }
        $out = array();
        foreach ($stored as $item_id => $record) {
            if (is_array($record) && is_string($item_id)) {
                $out[$item_id] = $record;
            }
        }
        return $out;
    }

    private static function record_from_body($item_id, $body) {
        if (!is_string($item_id) || !preg_match(self::ITEM_ID_PATTERN, $item_id)) {
            return new WP_Error('mgws_bad_request', 'id must match [a-z0-9_-]{1,64}', array('status' => 400));
        }
        if (!is_array($body)) {
            return new WP_Error('mgws_bad_request', 'body must be an object', array('status' => 400));
        }
        $name = isset($body['name']) ? trim(sanitize_text_field((string) $body['name'])) : '';
        if ($name === '') {
            return new WP_Error('mgws_bad_request', 'name is required', array('status' => 400));
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
        $raw = $body['data_json'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return new WP_Error('mgws_bad_request', 'data_json must be a JSON object string', array('status' => 400));
        }
        if (strlen($raw) > self::MAX_DATA_BYTES) {
            return new WP_Error('mgws_bad_request', 'data_json payload too large', array('status' => 400));
        }
        $decoded = json_decode($raw, false, self::MAX_DEPTH + 2);
        if (!($decoded instanceof stdClass)) {
            return new WP_Error('mgws_bad_request', 'data_json must be a JSON object string', array('status' => 400));
        }
        $clean = wp_json_encode(self::sanitize_data($decoded, 0));
        if (!is_string($clean)) {
            return new WP_Error('mgws_bad_request', 'data_json could not be stored', array('status' => 400));
        }
        return array(
            'id' => $item_id,
            'name' => $name,
            'data_json' => $clean,
            'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
        );
    }

    /**
     * Removes invalid UTF-8, control characters and markup. Leading and
     * trailing spaces are kept on purpose: a separator such as " - " is part
     * of what gets printed.
     */
    private static function clean_string($value) {
        $value = wp_check_invalid_utf8($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/<[^>]*>/', '', (string) $value);
        return (string) $value;
    }

    /**
     * Keeps the structure and the key case of the app data (the layout uses
     * keys such as `fieldName`), drops keys outside a safe charset and cleans
     * every string. The data is never printed in an HTML page.
     */
    private static function sanitize_data($value, $depth) {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if ($value instanceof stdClass) {
            $out = new stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $key = (string) $key;
                if (!preg_match(self::DATA_KEY_PATTERN, $key)) {
                    continue;
                }
                $out->{$key} = self::sanitize_data($child, $depth + 1);
            }
            return $out;
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $child) {
                $out[] = self::sanitize_data($child, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::clean_string($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        return null;
    }
}
