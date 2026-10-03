<?php

if (!defined('ABSPATH')) {
    exit;
}

class MGWS_DB {

    public static function get_site_location_tree($site_id) {
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return array('rooms' => array());
        }
        $tree = get_post_meta($site_id, 'mgws_site_loc_tree', true);
        return self::normalize_location_tree($tree);
    }

    public static function save_site_location_tree($site_id, $tree) {
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return;
        }
        $tree = self::normalize_location_tree($tree);
        update_post_meta($site_id, 'mgws_site_loc_tree', $tree);
    }

    public static function site_tree_to_lists($tree) {
        $tree = self::normalize_location_tree($tree);
        $rooms = array();
        $racks = array();
        $shelves = array();
        foreach (($tree['rooms'] ?? array()) as $room_name => $room_data) {
            $room_name = self::sanitize_loc((string) $room_name);
            if ($room_name === '') {
                continue;
            }
            $rooms[] = $room_name;
            foreach (($room_data['racks'] ?? array()) as $rack_name => $rack_data) {
                $rack_name = self::sanitize_loc((string) $rack_name);
                if ($rack_name !== '') {
                    $racks[] = $rack_name;
                }
                foreach (($rack_data['shelves'] ?? array()) as $sh) {
                    $sh = self::sanitize_loc((string) $sh);
                    if ($sh !== '') {
                        $shelves[] = $sh;
                    }
                }
            }
        }
        $rooms = array_values(array_unique(array_filter(array_map('strval', $rooms))));
        $racks = array_values(array_unique(array_filter(array_map('strval', $racks))));
        $shelves = array_values(array_unique(array_filter(array_map('strval', $shelves))));
        sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
        sort($racks, SORT_NATURAL | SORT_FLAG_CASE);
        sort($shelves, SORT_NATURAL | SORT_FLAG_CASE);
        return array('rooms' => $rooms, 'racks' => $racks, 'shelves' => $shelves);
    }

    public static function delete_from_site_tree($site_id, $field, $value, $parent_room = '', $parent_rack = '') {
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return array('ok' => false, 'message' => __('Invalid site', 'mg-warehouse-stock'));
        }
        $field = sanitize_key((string) $field);
        $value = self::sanitize_loc((string) $value);
        $parent_room = self::sanitize_loc((string) $parent_room);
        $parent_rack = self::sanitize_loc((string) $parent_rack);
        if ($value === '') {
            return array('ok' => false, 'message' => __('Missing value', 'mg-warehouse-stock'));
        }

        $tree = self::get_site_location_tree($site_id);
        if (!isset($tree['rooms']) || !is_array($tree['rooms'])) {
            $tree['rooms'] = array();
        }

        if ($field === 'room') {
            if (!isset($tree['rooms'][$value])) {
                return array('ok' => false, 'message' => __('Room not found', 'mg-warehouse-stock'));
            }
            unset($tree['rooms'][$value]);
        } elseif ($field === 'rack') {
            if ($parent_room === '' || !isset($tree['rooms'][$parent_room])) {
                return array('ok' => false, 'message' => __('Invalid room', 'mg-warehouse-stock'));
            }
            if (!isset($tree['rooms'][$parent_room]['racks'][$value])) {
                return array('ok' => false, 'message' => __('Rack not found', 'mg-warehouse-stock'));
            }
            unset($tree['rooms'][$parent_room]['racks'][$value]);
        } elseif ($field === 'shelf') {
            if ($parent_room === '' || $parent_rack === '') {
                return array('ok' => false, 'message' => __('Missing context', 'mg-warehouse-stock'));
            }
            if (!isset($tree['rooms'][$parent_room]['racks'][$parent_rack]['shelves'])) {
                return array('ok' => false, 'message' => __('Invalid rack', 'mg-warehouse-stock'));
            }
            $arr = $tree['rooms'][$parent_room]['racks'][$parent_rack]['shelves'];
            if (!is_array($arr)) {
                $arr = array();
            }
            $arr = array_values(array_filter($arr, function ($v) use ($value) {
                return (string) $v !== (string) $value;
            }));
            $tree['rooms'][$parent_room]['racks'][$parent_rack]['shelves'] = $arr;
        } else {
            return array('ok' => false, 'message' => __('Invalid field', 'mg-warehouse-stock'));
        }

        $tree = self::normalize_location_tree($tree);
        self::save_site_location_tree($site_id, $tree);

        $lists = self::site_tree_to_lists($tree);
        update_post_meta($site_id, 'mgws_site_rooms', $lists['rooms']);
        update_post_meta($site_id, 'mgws_site_racks', $lists['racks']);
        update_post_meta($site_id, 'mgws_site_shelves', $lists['shelves']);

        return array('ok' => true, 'tree' => $tree);
    }

    public static function count_levels_using_location($site_id, $field, $value, $parent_room = '', $parent_rack = '') {
        global $wpdb;
        $levels = self::table_levels();
        $site_id = (int) $site_id;
        $field = sanitize_key((string) $field);
        $value = self::sanitize_loc((string) $value);
        $parent_room = self::sanitize_loc((string) $parent_room);
        $parent_rack = self::sanitize_loc((string) $parent_rack);

        if ($site_id <= 0 || $value === '') {
            return 0;
        }

        if ($field === 'room') {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$levels} WHERE site_id=%d AND room=%s",
                $site_id,
                $value
            ));
        }
        if ($field === 'rack') {
            if ($parent_room === '') {
                return 0;
            }
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$levels} WHERE site_id=%d AND room=%s AND rack=%s",
                $site_id,
                $parent_room,
                $value
            ));
        }
        if ($field === 'shelf') {
            if ($parent_room === '' || $parent_rack === '') {
                return 0;
            }
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$levels} WHERE site_id=%d AND room=%s AND rack=%s AND shelf=%s",
                $site_id,
                $parent_room,
                $parent_rack,
                $value
            ));
        }
        return 0;
    }

    public static function count_warehouse_links_using_location($site_id, $field, $value, $parent_room = '', $parent_rack = '') {
        $site_id = (int) $site_id;
        $field = sanitize_key((string) $field);
        $value = self::sanitize_loc((string) $value);
        $parent_room = self::sanitize_loc((string) $parent_room);
        $parent_rack = self::sanitize_loc((string) $parent_rack);
        if ($site_id <= 0 || $value === '') {
            return 0;
        }

        $wh_ids = get_posts(array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => 'mg_site_id',
                    'value' => $site_id,
                    'compare' => '=',
                ),
            ),
        ));

        if (!is_array($wh_ids) || empty($wh_ids)) {
            return 0;
        }

        $count = 0;
        foreach ($wh_ids as $wid) {
            $wid = (int) $wid;
            if ($wid <= 0) {
                continue;
            }
            $tree = self::get_warehouse_location_tree($wid);
            $rooms = $tree['rooms'] ?? array();
            if (!is_array($rooms) || empty($rooms)) {
                continue;
            }

            if ($field === 'room') {
                if (isset($rooms[$value])) {
                    $count += 1;
                }
                continue;
            }

            if ($field === 'rack') {
                if ($parent_room === '' || !isset($rooms[$parent_room])) {
                    continue;
                }
                $racks = $rooms[$parent_room]['racks'] ?? array();
                if (is_array($racks) && isset($racks[$value])) {
                    $count += 1;
                }
                continue;
            }

            if ($field === 'shelf') {
                if ($parent_room === '' || $parent_rack === '' || !isset($rooms[$parent_room])) {
                    continue;
                }
                $racks = $rooms[$parent_room]['racks'] ?? array();
                if (!is_array($racks) || !isset($racks[$parent_rack])) {
                    continue;
                }
                $shelves = $racks[$parent_rack]['shelves'] ?? array();
                if (is_array($shelves) && in_array($value, $shelves, true)) {
                    $count += 1;
                }
                continue;
            }
        }

        return $count;
    }

    private static function merge_tree(&$base, $other) {
        $other = self::normalize_location_tree($other);
        foreach (($other['rooms'] ?? array()) as $room_name => $room_data) {
            self::tree_ensure_room($base, $room_name);
            foreach (($room_data['racks'] ?? array()) as $rack_name => $rack_data) {
                self::tree_ensure_rack($base, $room_name, $rack_name);
                foreach (($rack_data['shelves'] ?? array()) as $sh) {
                    self::tree_add_combo($base, $room_name, $rack_name, $sh);
                }
            }
        }
    }

    public static function normalize_warehouse_loc_tree($tree) {
        $out = array('rooms' => array());
        if (!is_array($tree)) {
            return $out;
        }
        $rooms = $tree['rooms'] ?? array();
        if (!is_array($rooms)) {
            return $out;
        }
        foreach ($rooms as $room_name => $room_data) {
            $room_name = self::sanitize_loc((string) $room_name);
            if ($room_name === '') {
                continue;
            }
            $room_all = is_array($room_data) ? (!empty($room_data['all'])) : false;
            $out['rooms'][$room_name] = array(
                'all' => $room_all ? 1 : 0,
                'racks' => array(),
            );
            $racks = is_array($room_data) ? ($room_data['racks'] ?? array()) : array();
            if (!is_array($racks)) {
                $racks = array();
            }
            foreach ($racks as $rack_name => $rack_data) {
                $rack_name = self::sanitize_loc((string) $rack_name);
                if ($rack_name === '') {
                    continue;
                }
                $rack_all = is_array($rack_data) ? (!empty($rack_data['all'])) : false;
                $shelves = is_array($rack_data) ? ($rack_data['shelves'] ?? array()) : array();
                if (!is_array($shelves)) {
                    $shelves = array();
                }
                $clean_shelves = array();
                foreach ($shelves as $sh) {
                    $sh = self::sanitize_loc((string) $sh);
                    if ($sh !== '') {
                        $clean_shelves[] = $sh;
                    }
                }
                $clean_shelves = array_values(array_unique(array_filter(array_map('strval', $clean_shelves))));
                sort($clean_shelves, SORT_NATURAL | SORT_FLAG_CASE);
                $out['rooms'][$room_name]['racks'][$rack_name] = array(
                    'all' => $rack_all ? 1 : 0,
                    'shelves' => $clean_shelves,
                );
            }
            uksort($out['rooms'][$room_name]['racks'], 'strnatcasecmp');
        }
        uksort($out['rooms'], 'strnatcasecmp');
        return $out;
    }

    public static function get_warehouse_location_tree($warehouse_id) {
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return array('rooms' => array());
        }
        $tree = get_post_meta($warehouse_id, 'mgws_wh_loc_tree', true);
        return self::normalize_warehouse_loc_tree($tree);
    }

    public static function save_warehouse_location_tree($warehouse_id, $tree) {
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return;
        }
        $tree = self::normalize_warehouse_loc_tree($tree);
        update_post_meta($warehouse_id, 'mgws_wh_loc_tree', $tree);
    }

    public static function filter_site_tree_by_warehouse_links($site_tree, $wh_tree) {
        $site_tree = self::normalize_location_tree($site_tree);
        $wh_tree = self::normalize_warehouse_loc_tree($wh_tree);
        $out = array('rooms' => array());
        $wh_rooms = $wh_tree['rooms'] ?? array();
        if (!is_array($wh_rooms) || empty($wh_rooms)) {
            return $site_tree;
        }

        foreach ($wh_rooms as $room_name => $room_data) {
            if (!isset($site_tree['rooms'][$room_name])) {
                continue;
            }
            $site_room = $site_tree['rooms'][$room_name];
            if (!empty($room_data['all'])) {
                $out['rooms'][$room_name] = $site_room;
                continue;
            }
            $out['rooms'][$room_name] = array('racks' => array());
            $wh_racks = $room_data['racks'] ?? array();
            if (!is_array($wh_racks)) {
                $wh_racks = array();
            }
            foreach ($wh_racks as $rack_name => $rack_data) {
                if (!isset($site_room['racks'][$rack_name])) {
                    continue;
                }
                $site_rack = $site_room['racks'][$rack_name];
                if (!empty($rack_data['all'])) {
                    $out['rooms'][$room_name]['racks'][$rack_name] = $site_rack;
                    continue;
                }
                $out['rooms'][$room_name]['racks'][$rack_name] = array('shelves' => array());
                $wh_shelves = $rack_data['shelves'] ?? array();
                if (!is_array($wh_shelves)) {
                    $wh_shelves = array();
                }
                $filtered = array();
                foreach ($wh_shelves as $sh) {
                    if (in_array($sh, ($site_rack['shelves'] ?? array()), true)) {
                        $filtered[] = $sh;
                    }
                }
                $filtered = array_values(array_unique(array_filter(array_map('strval', $filtered))));
                sort($filtered, SORT_NATURAL | SORT_FLAG_CASE);
                $out['rooms'][$room_name]['racks'][$rack_name]['shelves'] = $filtered;
            }
        }

        return self::normalize_location_tree($out);
    }

    public static function link_location_to_warehouse($warehouse_id, $room, $rack, $shelf, $scope) {
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return array('ok' => false, 'message' => __('Invalid warehouse', 'mg-warehouse-stock'));
        }
        $room = self::sanitize_loc((string) $room);
        $rack = self::sanitize_loc((string) $rack);
        $shelf = self::sanitize_loc((string) $shelf);
        $scope = sanitize_key((string) $scope);
        if ($room === '') {
            return array('ok' => false, 'message' => __('Missing room', 'mg-warehouse-stock'));
        }

        $tree = self::get_warehouse_location_tree($warehouse_id);
        if (!isset($tree['rooms'][$room])) {
            $tree['rooms'][$room] = array('all' => 0, 'racks' => array());
        }

        if ($scope === 'room_all') {
            $tree['rooms'][$room]['all'] = 1;
            $tree['rooms'][$room]['racks'] = array();
            self::save_warehouse_location_tree($warehouse_id, $tree);
            return array('ok' => true);
        }

        if ($rack === '') {
            return array('ok' => false, 'message' => __('Missing rack', 'mg-warehouse-stock'));
        }
        if (!isset($tree['rooms'][$room]['racks'][$rack])) {
            $tree['rooms'][$room]['racks'][$rack] = array('all' => 0, 'shelves' => array());
        }

        if ($scope === 'rack_all') {
            $tree['rooms'][$room]['all'] = 0;
            $tree['rooms'][$room]['racks'][$rack]['all'] = 1;
            $tree['rooms'][$room]['racks'][$rack]['shelves'] = array();
            self::save_warehouse_location_tree($warehouse_id, $tree);
            return array('ok' => true);
        }

        if ($shelf === '') {
            return array('ok' => false, 'message' => __('Missing shelf', 'mg-warehouse-stock'));
        }
        $tree['rooms'][$room]['all'] = 0;
        $tree['rooms'][$room]['racks'][$rack]['all'] = 0;
        $arr = $tree['rooms'][$room]['racks'][$rack]['shelves'] ?? array();
        if (!is_array($arr)) {
            $arr = array();
        }
        $arr[] = $shelf;
        $arr = array_values(array_unique(array_filter(array_map('strval', $arr))));
        sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
        $tree['rooms'][$room]['racks'][$rack]['shelves'] = $arr;
        self::save_warehouse_location_tree($warehouse_id, $tree);
        return array('ok' => true);
    }

    public static function unlink_location_from_warehouse($warehouse_id, $room, $rack, $shelf, $scope) {
        $warehouse_id = (int) $warehouse_id;
        $room = self::sanitize_loc((string) $room);
        $rack = self::sanitize_loc((string) $rack);
        $shelf = self::sanitize_loc((string) $shelf);
        $scope = sanitize_key((string) $scope);
        if ($warehouse_id <= 0 || $room === '') {
            return array('ok' => false, 'message' => __('Missing data', 'mg-warehouse-stock'));
        }
        $tree = self::get_warehouse_location_tree($warehouse_id);
        if (!isset($tree['rooms'][$room])) {
            return array('ok' => true);
        }

        if ($scope === 'room_all') {
            unset($tree['rooms'][$room]);
            self::save_warehouse_location_tree($warehouse_id, $tree);
            return array('ok' => true);
        }

        if ($rack === '' || !isset($tree['rooms'][$room]['racks'][$rack])) {
            return array('ok' => true);
        }
        if ($scope === 'rack_all') {
            unset($tree['rooms'][$room]['racks'][$rack]);
            if (empty($tree['rooms'][$room]['racks']) && empty($tree['rooms'][$room]['all'])) {
                unset($tree['rooms'][$room]);
            }
            self::save_warehouse_location_tree($warehouse_id, $tree);
            return array('ok' => true);
        }

        if ($shelf === '') {
            return array('ok' => true);
        }
        $arr = $tree['rooms'][$room]['racks'][$rack]['shelves'] ?? array();
        if (!is_array($arr)) {
            $arr = array();
        }
        $arr = array_values(array_filter($arr, function ($v) use ($shelf) {
            return (string) $v !== (string) $shelf;
        }));
        $tree['rooms'][$room]['racks'][$rack]['shelves'] = $arr;
        if (empty($arr) && empty($tree['rooms'][$room]['racks'][$rack]['all'])) {
            unset($tree['rooms'][$room]['racks'][$rack]);
        }
        if (empty($tree['rooms'][$room]['racks']) && empty($tree['rooms'][$room]['all'])) {
            unset($tree['rooms'][$room]);
        }
        self::save_warehouse_location_tree($warehouse_id, $tree);
        return array('ok' => true);
    }

    public static function warehouse_allows_location($warehouse_id, $room, $rack, $shelf) {
        $warehouse_id = (int) $warehouse_id;
        $room = self::sanitize_loc((string) $room);
        $rack = self::sanitize_loc((string) $rack);
        $shelf = self::sanitize_loc((string) $shelf);

        if ($room === '') {
            return true;
        }

        $wh_tree = self::get_warehouse_location_tree($warehouse_id);
        $rooms = $wh_tree['rooms'] ?? array();
        if (!is_array($rooms) || empty($rooms)) {
            // No links configured => allow none (explicit linking required for stock operations).
            return false;
        }

        if (!isset($rooms[$room])) {
            return false;
        }
        if (!empty($rooms[$room]['all'])) {
            return true;
        }
        if ($rack === '') {
            return false;
        }
        $racks = $rooms[$room]['racks'] ?? array();
        if (!is_array($racks) || !isset($racks[$rack])) {
            return false;
        }
        if (!empty($racks[$rack]['all'])) {
            return true;
        }
        if ($shelf === '') {
            return false;
        }
        $shelves = $racks[$rack]['shelves'] ?? array();
        if (!is_array($shelves)) {
            return false;
        }
        return in_array($shelf, $shelves, true);
    }

    private static function tree_ensure_room(&$tree, $room) {
        $room = self::sanitize_loc((string) $room);
        if ($room === '') {
            return '';
        }
        if (!isset($tree['rooms']) || !is_array($tree['rooms'])) {
            $tree['rooms'] = array();
        }
        if (!isset($tree['rooms'][$room]) || !is_array($tree['rooms'][$room])) {
            $tree['rooms'][$room] = array('racks' => array());
        }
        if (!isset($tree['rooms'][$room]['racks']) || !is_array($tree['rooms'][$room]['racks'])) {
            $tree['rooms'][$room]['racks'] = array();
        }
        return $room;
    }

    private static function tree_ensure_rack(&$tree, $room, $rack) {
        $room = self::tree_ensure_room($tree, $room);
        $rack = self::sanitize_loc((string) $rack);
        if ($room === '' || $rack === '') {
            return '';
        }
        if (!isset($tree['rooms'][$room]['racks'][$rack]) || !is_array($tree['rooms'][$room]['racks'][$rack])) {
            $tree['rooms'][$room]['racks'][$rack] = array('shelves' => array());
        }
        if (!isset($tree['rooms'][$room]['racks'][$rack]['shelves']) || !is_array($tree['rooms'][$room]['racks'][$rack]['shelves'])) {
            $tree['rooms'][$room]['racks'][$rack]['shelves'] = array();
        }
        return $rack;
    }

    private static function tree_add_combo(&$tree, $room, $rack, $shelf) {
        $room = self::sanitize_loc((string) $room);
        $rack = self::sanitize_loc((string) $rack);
        $shelf = self::sanitize_loc((string) $shelf);
        if ($room === '') {
            return;
        }
        self::tree_ensure_room($tree, $room);
        if ($rack === '') {
            return;
        }
        self::tree_ensure_rack($tree, $room, $rack);
        if ($shelf === '') {
            return;
        }
        $arr = $tree['rooms'][$room]['racks'][$rack]['shelves'];
        if (!is_array($arr)) {
            $arr = array();
        }
        $arr[] = $shelf;
        $arr = array_values(array_unique(array_filter(array_map('strval', $arr))));
        sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
        $tree['rooms'][$room]['racks'][$rack]['shelves'] = $arr;
    }

    public static function normalize_location_tree($tree) {
        if (!is_array($tree)) {
            return array('rooms' => array());
        }
        $out = array('rooms' => array());
        $rooms = $tree['rooms'] ?? array();
        if (!is_array($rooms)) {
            $rooms = array();
        }
        foreach ($rooms as $room_name => $room_data) {
            $room_name = self::sanitize_loc((string) $room_name);
            if ($room_name === '') {
                continue;
            }
            $out['rooms'][$room_name] = array('racks' => array());
            $racks = is_array($room_data) ? ($room_data['racks'] ?? array()) : array();
            if (!is_array($racks)) {
                $racks = array();
            }
            foreach ($racks as $rack_name => $rack_data) {
                $rack_name = self::sanitize_loc((string) $rack_name);
                if ($rack_name === '') {
                    continue;
                }
                $shelves = is_array($rack_data) ? ($rack_data['shelves'] ?? array()) : array();
                if (!is_array($shelves)) {
                    $shelves = array();
                }
                $clean_shelves = array();
                foreach ($shelves as $sh) {
                    $sh = self::sanitize_loc((string) $sh);
                    if ($sh !== '') {
                        $clean_shelves[] = $sh;
                    }
                }
                $clean_shelves = array_values(array_unique(array_filter(array_map('strval', $clean_shelves))));
                sort($clean_shelves, SORT_NATURAL | SORT_FLAG_CASE);
                $out['rooms'][$room_name]['racks'][$rack_name] = array('shelves' => $clean_shelves);
            }
            uksort($out['rooms'][$room_name]['racks'], 'strnatcasecmp');
        }
        uksort($out['rooms'], 'strnatcasecmp');
        return $out;
    }
    public static function table_levels() {
        global $wpdb;
        return $wpdb->prefix . 'mg_stock_levels';
    }

    public static function count_levels_for_site($site_id) {
        global $wpdb;
        $table = self::table_levels();
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE site_id=%d",
            $site_id
        ));
    }

    public static function count_levels_for_warehouse($warehouse_id) {
        global $wpdb;
        $table = self::table_levels();
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE warehouse_id=%d",
            $warehouse_id
        ));
    }

    public static function count_moves_for_site($site_id) {
        global $wpdb;
        $table = self::table_moves();
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE site_id=%d",
            $site_id
        ));
    }

    public static function count_moves_for_warehouse($warehouse_id) {
        global $wpdb;
        $table = self::table_moves();
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE warehouse_id=%d",
            $warehouse_id
        ));
    }

    public static function table_moves() {
        global $wpdb;
        return $wpdb->prefix . 'mg_stock_moves';
    }

    /**
     * Intestazione di un movimento di magazzino.
     *
     * `mg_stock_moves` e' il libro: una riga per prodotto, e uno spostamento ne
     * scrive due per prodotto (uscita e entrata). Serve un altro livello per
     * dire "queste righe sono la stessa operazione", altrimenti dal libro non si
     * ricava quante operazioni sono avvenute, ne chi le ha fatte, ne perche'.
     */
    public static function table_movements() {
        global $wpdb;
        return $wpdb->prefix . 'mg_stock_movements';
    }

    public static function table_pos_idempotency() {
        global $wpdb;
        return $wpdb->prefix . 'mg_pos_idempotency';
    }

    public static function table_pos_shifts() {
        global $wpdb;
        return $wpdb->prefix . 'mg_pos_shifts';
    }

    public static function table_pos_receipts() {
        global $wpdb;
        return $wpdb->prefix . 'mg_pos_receipts';
    }

    public static function table_pos_receipt_lines() {
        global $wpdb;
        return $wpdb->prefix . 'mg_pos_receipt_lines';
    }

    public static function table_loyalty_cards() {
        global $wpdb;
        return $wpdb->prefix . 'mg_loyalty_cards';
    }

    public static function table_loyalty_movements() {
        global $wpdb;
        return $wpdb->prefix . 'mg_loyalty_movements';
    }

    public static function table_fornitori() {
        global $wpdb;
        return $wpdb->prefix . 'mg_fornitori';
    }

    public static function table_reorder_rules() {
        global $wpdb;
        return $wpdb->prefix . 'mg_reorder_rules';
    }

    public static function table_purchase_orders() {
        global $wpdb;
        return $wpdb->prefix . 'mg_purchase_orders';
    }

    public static function table_purchase_order_lines() {
        global $wpdb;
        return $wpdb->prefix . 'mg_purchase_order_lines';
    }

    public static function table_receipts() {
        global $wpdb;
        return $wpdb->prefix . 'mg_receipts';
    }

    public static function table_receipt_lines() {
        global $wpdb;
        return $wpdb->prefix . 'mg_receipt_lines';
    }

    public static function table_backorders() {
        global $wpdb;
        return $wpdb->prefix . 'mg_backorders';
    }

    public static function table_inventory_count_sessions() {
        global $wpdb;
        return $wpdb->prefix . 'mg_inventory_count_sessions';
    }

    public static function table_inventory_count_lines() {
        global $wpdb;
        return $wpdb->prefix . 'mg_inventory_count_lines';
    }

    public static function table_stock_reason_codes() {
        global $wpdb;
        return $wpdb->prefix . 'mg_stock_reason_codes';
    }

    public static function table_employees() {
        global $wpdb;
        return $wpdb->prefix . 'mg_employees';
    }

    public static function list_employees($search = '', $include_inactive = false, $limit = 100) {
        global $wpdb;
        $table = self::table_employees();
        $where = array();
        $params = array();
        if (!$include_inactive) {
            $where[] = 'active = %d';
            $params[] = 1;
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR role_label LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = max(1, min(200, (int) $limit));
        $sql = 'SELECT * FROM ' . $table;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY active DESC, last_name ASC, first_name ASC, id ASC LIMIT %d';
        $params[] = $limit;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function get_employee($employee_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_employees() . ' WHERE id = %d LIMIT 1',
            (int) $employee_id
        ), ARRAY_A);
    }

    public static function insert_employee($data) {
        global $wpdb;
        $now = current_time('mysql', true);
        $insert = array_merge(self::normalize_employee_data($data), array(
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        ));
        if (!$wpdb->insert(self::table_employees(), $insert)) {
            return null;
        }
        return self::get_employee((int) $wpdb->insert_id);
    }

    public static function update_employee($employee_id, $data) {
        global $wpdb;
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0 || !is_array(self::get_employee($employee_id))) {
            return null;
        }
        $update = self::normalize_employee_data($data, true);
        $update['updated_at_gmt'] = current_time('mysql', true);
        $updated = $wpdb->update(self::table_employees(), $update, array('id' => $employee_id));
        if ($updated === false) {
            return null;
        }
        return self::get_employee($employee_id);
    }

    public static function deactivate_employee($employee_id) {
        return self::update_employee((int) $employee_id, array('active' => 0, 'status' => 'inactive'));
    }

    private static function normalize_employee_data($data, $partial = false) {
        $data = is_array($data) ? $data : array();
        $fields = array();
        $map = array(
            'wp_user_id' => 0,
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'role_label' => '',
            'status' => 'active',
            'active' => 1,
            'salary_cents' => 0,
            'salary_currency' => 'EUR',
            'notes' => '',
        );
        foreach ($map as $key => $default) {
            if ($partial && !array_key_exists($key, $data)) {
                continue;
            }
            $value = array_key_exists($key, $data) ? $data[$key] : $default;
            if ($key === 'wp_user_id') {
                $fields[$key] = max(0, (int) $value);
            } elseif ($key === 'salary_cents') {
                $fields[$key] = max(0, (int) $value);
            } elseif ($key === 'salary_currency') {
                $currency = strtoupper(sanitize_text_field((string) $value));
                $fields[$key] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
            } elseif ($key === 'active') {
                $fields[$key] = ((int) $value) === 0 ? 0 : 1;
            } elseif ($key === 'email') {
                $fields[$key] = sanitize_email((string) $value);
            } elseif ($key === 'notes') {
                $fields[$key] = sanitize_textarea_field((string) $value);
            } elseif ($key === 'status') {
                $status = sanitize_key((string) $value);
                $fields[$key] = in_array($status, array('active', 'inactive'), true) ? $status : 'active';
                $fields['active'] = $fields[$key] === 'active' ? 1 : 0;
            } else {
                $fields[$key] = sanitize_text_field((string) $value);
            }
        }
        if (isset($fields['status'])) {
            $fields['active'] = $fields['status'] === 'active' ? 1 : 0;
        } elseif (isset($fields['active'])) {
            $fields['status'] = ((int) $fields['active']) === 1 ? 'active' : 'inactive';
        }
        return $fields;
    }

    public static function inventory_restock_tables_exist() {
        global $wpdb;
        $tables = array(
            self::table_fornitori(),
            self::table_reorder_rules(),
            self::table_purchase_orders(),
            self::table_purchase_order_lines(),
            self::table_receipts(),
            self::table_receipt_lines(),
            self::table_backorders(),
            self::table_inventory_count_sessions(),
            self::table_inventory_count_lines(),
            self::table_stock_reason_codes(),
        );
        foreach ($tables as $table) {
            if (empty($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)))) {
                return false;
            }
        }
        return true;
    }

    private static function inventory_restock_rows($table, $filters = array(), $order_by = '') {
        global $wpdb;
        $where = array('1=1');
        $params = array();
        foreach ($filters as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', (string) $column)) {
                continue;
            }
            if (is_int($value)) {
                $where[] = $column . '=%d';
                $params[] = $value;
            } else {
                $where[] = $column . '=%s';
                $params[] = (string) $value;
            }
        }
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where);
        if ($order_by !== '') {
            $sql .= ' ORDER BY ' . $order_by;
        }
        $query = empty($params) ? $sql : $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        return array_values(array_filter($rows, static function ($row) use ($filters) {
            if (!is_array($row)) {
                return false;
            }
            foreach ($filters as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    private static function inventory_restock_row($table, $id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $rows = self::inventory_restock_rows($table, array('id' => $id));
        return $rows[0] ?? null;
    }

    public static function list_suppliers($active = null) {
        $filters = array();
        if ($active !== null) {
            $filters['active'] = $active ? 1 : 0;
        }
        return self::inventory_restock_rows(self::table_fornitori(), $filters, 'id ASC');
    }

    public static function get_supplier($supplier_id) {
        return self::inventory_restock_row(self::table_fornitori(), $supplier_id);
    }

    public static function create_supplier($data) {
        global $wpdb;
        $table = self::table_fornitori();
        $now = gmdate('Y-m-d H:i:s');
        $defaults = array(
            'name' => '',
            'tax_id' => '',
            'email' => '',
            'phone' => '',
            'notes' => '',
            'payment_terms_days' => 0,
            'iban' => '',
            'lead_time_days' => 0,
            'active' => 1,
            'created_by_user_id' => 0,
            'updated_by_user_id' => 0,
        );
        $inserted = $wpdb->insert($table, array_merge($defaults, $data, array(
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        )));
        if (!$inserted) {
            return null;
        }
        return self::get_supplier((int) $wpdb->insert_id);
    }

    public static function update_supplier($supplier_id, $data) {
        global $wpdb;
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return null;
        }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(self::table_fornitori(), $data, array('id' => $supplier_id));
        if ($updated === false) {
            return null;
        }
        return self::get_supplier($supplier_id);
    }

    public static function supplier_in_use($supplier_id) {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return false;
        }
        foreach (array(self::table_reorder_rules(), self::table_purchase_orders()) as $table) {
            if (!empty(self::inventory_restock_rows($table, array('supplier_id' => $supplier_id)))) {
                return true;
            }
        }
        return false;
    }

    public static function delete_supplier($supplier_id) {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return false;
        }
        return is_array(self::update_supplier($supplier_id, array('active' => 0)));
    }

    public static function list_reorder_rules($site_id = 0, $warehouse_id = 0) {
        $filters = array();
        if ((int) $site_id > 0) {
            $filters['site_id'] = (int) $site_id;
        }
        if ((int) $warehouse_id > 0) {
            $filters['warehouse_id'] = (int) $warehouse_id;
        }
        return self::inventory_restock_rows(self::table_reorder_rules(), $filters, 'product_id ASC, variation_id ASC, id ASC');
    }

    public static function get_reorder_rule($rule_id) {
        return self::inventory_restock_row(self::table_reorder_rules(), $rule_id);
    }

    public static function create_reorder_rule($data) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(self::table_reorder_rules(), array_merge($data, array(
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        )));
        if (!$inserted) {
            return null;
        }
        return self::get_reorder_rule((int) $wpdb->insert_id);
    }

    public static function update_reorder_rule($rule_id, $data) {
        global $wpdb;
        $rule_id = (int) $rule_id;
        if ($rule_id <= 0) {
            return null;
        }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(self::table_reorder_rules(), $data, array('id' => $rule_id));
        if ($updated === false) {
            return null;
        }
        return self::get_reorder_rule($rule_id);
    }

    public static function delete_reorder_rule($rule_id) {
        global $wpdb;
        return $wpdb->delete(self::table_reorder_rules(), array('id' => (int) $rule_id)) === 1;
    }

    public static function reorder_rule_current_stock($rule) {
        global $wpdb;
        if (!is_array($rule)) {
            return 0;
        }
        $levels = self::table_levels();
        $where = array('product_id=%d', 'variation_id=%d');
        $params = array((int) $rule['product_id'], (int) $rule['variation_id']);
        if ((int) ($rule['site_id'] ?? 0) > 0) {
            $where[] = 'site_id=%d';
            $params[] = (int) $rule['site_id'];
        }
        if ((int) ($rule['warehouse_id'] ?? 0) > 0) {
            $where[] = 'warehouse_id=%d';
            $params[] = (int) $rule['warehouse_id'];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT site_id, warehouse_id, product_id, variation_id, qty FROM ' . $levels . ' WHERE ' . implode(' AND ', $where),
            $params
        ), ARRAY_A);
        $stock = 0;
        foreach (is_array($rows) ? $rows : array() as $row) {
            if ((int) ($row['product_id'] ?? 0) !== (int) $rule['product_id'] || (int) ($row['variation_id'] ?? 0) !== (int) $rule['variation_id']) {
                continue;
            }
            if ((int) ($rule['site_id'] ?? 0) > 0 && (int) ($row['site_id'] ?? 0) !== (int) $rule['site_id']) {
                continue;
            }
            if ((int) ($rule['warehouse_id'] ?? 0) > 0 && (int) ($row['warehouse_id'] ?? 0) !== (int) $rule['warehouse_id']) {
                continue;
            }
            $stock += (int) ($row['qty'] ?? 0);
        }
        return $stock;
    }

    public static function list_purchase_orders($site_id = 0, $supplier_id = 0, $status = '') {
        $filters = array();
        if ((int) $site_id > 0) {
            $filters['site_id'] = (int) $site_id;
        }
        if ((int) $supplier_id > 0) {
            $filters['supplier_id'] = (int) $supplier_id;
        }
        if ($status !== '') {
            $filters['status'] = (string) $status;
        }
        return self::inventory_restock_rows(self::table_purchase_orders(), $filters, 'updated_at_gmt DESC, id DESC');
    }

    public static function get_purchase_order($purchase_order_id) {
        return self::inventory_restock_row(self::table_purchase_orders(), $purchase_order_id);
    }

    public static function create_purchase_order($data) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(self::table_purchase_orders(), array_merge($data, array(
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        )));
        if (!$inserted) {
            return null;
        }
        return self::get_purchase_order((int) $wpdb->insert_id);
    }

    public static function update_purchase_order($purchase_order_id, $data) {
        global $wpdb;
        $purchase_order_id = (int) $purchase_order_id;
        if ($purchase_order_id <= 0) {
            return null;
        }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(self::table_purchase_orders(), $data, array('id' => $purchase_order_id));
        if ($updated === false) {
            return null;
        }
        return self::get_purchase_order($purchase_order_id);
    }

    public static function list_purchase_order_lines($purchase_order_id) {
        return self::inventory_restock_rows(self::table_purchase_order_lines(), array('purchase_order_id' => (int) $purchase_order_id), 'line_number ASC, id ASC');
    }

    public static function get_purchase_order_line($purchase_order_id, $line_id) {
        $line = self::inventory_restock_row(self::table_purchase_order_lines(), $line_id);
        if (!is_array($line) || (int) ($line['purchase_order_id'] ?? 0) !== (int) $purchase_order_id) {
            return null;
        }
        return $line;
    }

    public static function create_purchase_order_line($purchase_order_id, $data) {
        global $wpdb;
        $purchase_order_id = (int) $purchase_order_id;
        $line_number = 1;
        foreach (self::list_purchase_order_lines($purchase_order_id) as $line) {
            $line_number = max($line_number, (int) ($line['line_number'] ?? 0) + 1);
        }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(self::table_purchase_order_lines(), array_merge($data, array(
            'purchase_order_id' => $purchase_order_id,
            'line_number' => $line_number,
            'received_qty' => 0,
            'cancelled_qty' => 0,
            'stock_effect' => 'incoming',
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        )));
        if (!$inserted) {
            return null;
        }
        return self::get_purchase_order_line($purchase_order_id, (int) $wpdb->insert_id);
    }

    public static function update_purchase_order_line($purchase_order_id, $line_id, $data) {
        global $wpdb;
        $line = self::get_purchase_order_line($purchase_order_id, $line_id);
        if (!is_array($line)) {
            return null;
        }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(self::table_purchase_order_lines(), $data, array('id' => (int) $line['id']));
        if ($updated === false) {
            return null;
        }
        return self::get_purchase_order_line($purchase_order_id, $line_id);
    }

    public static function list_receipts($site_id = 0, $purchase_order_id = 0, $status = '') {
        $filters = array();
        if ((int) $site_id > 0) { $filters['site_id'] = (int) $site_id; }
        if ((int) $purchase_order_id > 0) { $filters['purchase_order_id'] = (int) $purchase_order_id; }
        if ($status !== '') { $filters['status'] = (string) $status; }
        return self::inventory_restock_rows(self::table_receipts(), $filters, 'updated_at_gmt DESC, id DESC');
    }

    public static function get_receipt($receipt_id) {
        return self::inventory_restock_row(self::table_receipts(), $receipt_id);
    }

    public static function get_receipt_by_idempotency_key($idempotency_key) {
        $idempotency_key = trim((string) $idempotency_key);
        if ($idempotency_key === '') { return null; }
        $rows = self::inventory_restock_rows(self::table_receipts(), array('idempotency_key' => $idempotency_key));
        return $rows[0] ?? null;
    }

    public static function create_receipt($data) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        if (!$wpdb->insert(self::table_receipts(), array_merge($data, array(
            'status' => (string) ($data['status'] ?? 'draft'), 'received_at_gmt' => null, 'validated_at_gmt' => null, 'posted_at_gmt' => null,
            'validated_by_user_id' => 0, 'posted_by_user_id' => 0, 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        )))) { return null; }
        return self::get_receipt((int) $wpdb->insert_id);
    }

    public static function update_receipt($receipt_id, $data) {
        global $wpdb;
        $receipt_id = (int) $receipt_id;
        if ($receipt_id <= 0) { return null; }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::table_receipts(), $data, array('id' => $receipt_id)) !== 1) { return null; }
        return self::get_receipt($receipt_id);
    }

    public static function list_receipt_lines($receipt_id) {
        return self::inventory_restock_rows(self::table_receipt_lines(), array('receipt_id' => (int) $receipt_id), 'line_number ASC, id ASC');
    }

    public static function get_receipt_line($receipt_id, $line_id) {
        $line = self::inventory_restock_row(self::table_receipt_lines(), $line_id);
        return is_array($line) && (int) ($line['receipt_id'] ?? 0) === (int) $receipt_id ? $line : null;
    }

    public static function create_receipt_line($receipt_id, $data) {
        global $wpdb;
        $receipt_id = (int) $receipt_id;
        $line_number = 1;
        foreach (self::list_receipt_lines($receipt_id) as $line) {
            $line_number = max($line_number, (int) ($line['line_number'] ?? 0) + 1);
        }
        $now = gmdate('Y-m-d H:i:s');
        if (!$wpdb->insert(self::table_receipt_lines(), array_merge($data, array(
            'receipt_id' => $receipt_id, 'line_number' => $line_number, 'stock_effect' => 'load',
            'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        )))) { return null; }
        return self::get_receipt_line($receipt_id, (int) $wpdb->insert_id);
    }

    public static function update_receipt_line($receipt_id, $line_id, $data) {
        global $wpdb;
        $line = self::get_receipt_line($receipt_id, $line_id);
        if (!is_array($line)) { return null; }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::table_receipt_lines(), $data, array('id' => (int) $line['id'])) !== 1) { return null; }
        return self::get_receipt_line($receipt_id, $line_id);
    }

    public static function list_backorders() {
        return self::inventory_restock_rows(self::table_backorders(), array(), 'updated_at_gmt DESC, id DESC');
    }

    public static function get_backorder_for_receipt_line($receipt_line_id) {
        $rows = self::inventory_restock_rows(self::table_backorders(), array('receipt_line_id' => (int) $receipt_line_id));
        return $rows[0] ?? null;
    }

    public static function create_or_update_backorder($receipt_line_id, $data) {
        global $wpdb;
        $existing = self::get_backorder_for_receipt_line($receipt_line_id);
        $now = gmdate('Y-m-d H:i:s');
        if (is_array($existing)) {
            $data['updated_at_gmt'] = $now;
            if ($wpdb->update(self::table_backorders(), $data, array('id' => (int) $existing['id'])) !== 1) { return null; }
            return self::inventory_restock_row(self::table_backorders(), (int) $existing['id']);
        }
        if (!$wpdb->insert(self::table_backorders(), array_merge($data, array(
            'receipt_line_id' => (int) $receipt_line_id, 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        )))) { return null; }
        return self::inventory_restock_row(self::table_backorders(), (int) $wpdb->insert_id);
    }

    public static function list_inventory_count_sessions($site_id = 0, $warehouse_id = 0) {
        $filters = array();
        if ((int) $site_id > 0) { $filters['site_id'] = (int) $site_id; }
        if ((int) $warehouse_id > 0) { $filters['warehouse_id'] = (int) $warehouse_id; }
        return self::inventory_restock_rows(self::table_inventory_count_sessions(), $filters, 'created_at_gmt DESC, id DESC');
    }

    public static function get_inventory_count_session($session_id) {
        return self::inventory_restock_row(self::table_inventory_count_sessions(), $session_id);
    }

    public static function create_inventory_count_session($data) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        if (!$wpdb->insert(self::table_inventory_count_sessions(), array_merge($data, array(
            'status' => 'draft', 'approved_by_user_id' => 0, 'approved_at_gmt' => null, 'posted_at_gmt' => null,
            'started_at_gmt' => $now, 'created_at_gmt' => $now, 'updated_at_gmt' => $now,
        )))) {
            return null;
        }
        return self::get_inventory_count_session((int) $wpdb->insert_id);
    }

    public static function update_inventory_count_session($session_id, $data) {
        global $wpdb;
        $session_id = (int) $session_id;
        if ($session_id <= 0) { return null; }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::table_inventory_count_sessions(), $data, array('id' => $session_id)) !== 1) { return null; }
        return self::get_inventory_count_session($session_id);
    }

    public static function list_inventory_count_lines($session_id) {
        return self::inventory_restock_rows(self::table_inventory_count_lines(), array('count_session_id' => (int) $session_id), 'id ASC');
    }

    public static function get_inventory_count_line($session_id, $line_id) {
        $line = self::inventory_restock_row(self::table_inventory_count_lines(), $line_id);
        return is_array($line) && (int) ($line['count_session_id'] ?? 0) === (int) $session_id ? $line : null;
    }

    public static function find_inventory_count_line($session_id, $warehouse_id, $product_id, $variation_id, $room, $rack, $shelf) {
        foreach (self::list_inventory_count_lines($session_id) as $line) {
            if ((int) ($line['warehouse_id'] ?? 0) === (int) $warehouse_id && (int) ($line['product_id'] ?? 0) === (int) $product_id && (int) ($line['variation_id'] ?? 0) === (int) $variation_id && (string) ($line['room'] ?? '') === self::sanitize_loc($room) && (string) ($line['rack'] ?? '') === self::sanitize_loc($rack) && (string) ($line['shelf'] ?? '') === self::sanitize_loc($shelf)) {
                return $line;
            }
        }
        return null;
    }

    public static function create_inventory_count_line($session_id, $data) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        if (!$wpdb->insert(self::table_inventory_count_lines(), array_merge($data, array('count_session_id' => (int) $session_id, 'stock_move_id' => 0, 'counted_at_gmt' => $now, 'created_at_gmt' => $now, 'updated_at_gmt' => $now)))) { return null; }
        return self::get_inventory_count_line($session_id, (int) $wpdb->insert_id);
    }

    public static function update_inventory_count_line($session_id, $line_id, $data) {
        global $wpdb;
        $line = self::get_inventory_count_line($session_id, $line_id);
        if (!is_array($line)) { return null; }
        $data['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::table_inventory_count_lines(), $data, array('id' => (int) $line['id'])) !== 1) { return null; }
        return self::get_inventory_count_line($session_id, (int) $line['id']);
    }

    public static function loyalty_tables_exist() {
        global $wpdb;
        $cards = self::table_loyalty_cards();
        $movements = self::table_loyalty_movements();
        $cards_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cards));
        $movements_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $movements));
        return !empty($cards_exists) && !empty($movements_exists);
    }

    public static function get_loyalty_account($customer_id) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_loyalty_cards() . ' WHERE customer_id = %d LIMIT 1',
            $customer_id
        ), ARRAY_A);
    }

    public static function get_loyalty_account_by_card($card_number) {
        global $wpdb;
        $card_number = trim((string) $card_number);
        if ($card_number === '') {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_loyalty_cards() . ' WHERE card_number = %s LIMIT 1',
            $card_number
        ), ARRAY_A);
    }

    public static function get_loyalty_cards_all() {
        global $wpdb;
        $cards = self::table_loyalty_cards();
        return $wpdb->get_results(
            "SELECT c.id, c.customer_id, c.card_number, c.tier, c.points_balance, c.enabled, c.created_at_gmt, c.updated_at_gmt,
                    u.user_email,
                    um_first.meta_value AS first_name,
                    um_last.meta_value AS last_name
             FROM {$cards} c
             LEFT JOIN {$wpdb->users} u ON c.customer_id = u.ID
             LEFT JOIN {$wpdb->usermeta} um_first ON c.customer_id = um_first.user_id AND um_first.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_last ON c.customer_id = um_last.user_id AND um_last.meta_key = 'last_name'",
            ARRAY_A
        );
    }

    public static function upsert_loyalty_card($customer_id, $card_number, $tier = 'bronze') {
        global $wpdb;
        $customer_id = (int) $customer_id;
        $card_number = trim((string) $card_number);
        $tier = sanitize_key((string) $tier);
        if ($customer_id <= 0 || $card_number === '') {
            return array('ok' => false, 'state' => 'invalid');
        }
        if ($tier === '') {
            $tier = 'bronze';
        }

        $cards = self::table_loyalty_cards();
        $existing_owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT customer_id FROM {$cards} WHERE card_number = %s AND customer_id != %d LIMIT 1",
            $card_number,
            $customer_id
        ));
        if ($existing_owner > 0) {
            return array('ok' => false, 'state' => 'conflict');
        }

        $now = gmdate('Y-m-d H:i:s');
        $account = self::get_loyalty_account($customer_id);
        if (is_array($account)) {
            $updated = $wpdb->update(
                $cards,
                array(
                    'card_number' => $card_number,
                    'tier' => $tier,
                    'updated_at_gmt' => $now,
                ),
                array('customer_id' => $customer_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            if ($updated === false) {
                return array('ok' => false, 'state' => 'error');
            }
            return array('ok' => true, 'account' => self::get_loyalty_account($customer_id));
        }

        $inserted = $wpdb->insert($cards, array(
            'customer_id' => $customer_id,
            'card_number' => $card_number,
            'tier' => $tier,
            'points_balance' => 0,
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        ), array('%d', '%s', '%s', '%d', '%s', '%s'));
        if (!$inserted) {
            $account = self::get_loyalty_account($customer_id);
            if (is_array($account)) {
                return array('ok' => false, 'state' => 'conflict');
            }
            return array('ok' => false, 'state' => 'error');
        }

        return array('ok' => true, 'account' => self::get_loyalty_account($customer_id));
    }

    public static function remove_loyalty_card($customer_id) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        $account = self::get_loyalty_account($customer_id);
        if (!is_array($account) || empty($account['card_number'])) {
            return array('ok' => false, 'state' => 'not_found');
        }

        $updated = $wpdb->update(
            self::table_loyalty_cards(),
            array(
                'card_number' => null,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('customer_id' => $customer_id),
            array('%s', '%s'),
            array('%d')
        );
        if ($updated === false) {
            return array('ok' => false, 'state' => 'error');
        }

        return array('ok' => true);
    }

    public static function change_loyalty_points($customer_id, $points, $direction, $reference, $note, $actor_user_id) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        $points = (int) $points;
        $direction = sanitize_key((string) $direction);
        if ($customer_id <= 0 || $points <= 0 || !in_array($direction, array('add', 'deduct'), true)) {
            return array('ok' => false, 'state' => 'invalid');
        }

        $cards = self::table_loyalty_cards();
        $movements = self::table_loyalty_movements();
        $wpdb->query('START TRANSACTION');
        $account = self::get_loyalty_account($customer_id);
        if (!is_array($account)) {
            $now = gmdate('Y-m-d H:i:s');
            $inserted = $wpdb->insert($cards, array(
                'customer_id' => $customer_id,
                'card_number' => null,
                'tier' => 'bronze',
                'points_balance' => 0,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ), array('%d', '%s', '%s', '%d', '%s', '%s'));
            if (!$inserted) {
                $account = self::get_loyalty_account($customer_id);
                if (!is_array($account)) {
                    $wpdb->query('ROLLBACK');
                    return array('ok' => false, 'state' => 'error');
                }
            } else {
                $account = self::get_loyalty_account($customer_id);
            }
        }

        $current_balance = (int) ($account['points_balance'] ?? 0);
        if ($direction === 'deduct' && $current_balance < $points) {
            $wpdb->query('ROLLBACK');
            return array('ok' => false, 'state' => 'insufficient_points');
        }

        $delta = $direction === 'add' ? $points : -$points;
        $new_balance = $current_balance + $delta;
        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(
            $cards,
            array(
                'points_balance' => $new_balance,
                'updated_at_gmt' => $now,
            ),
            array('customer_id' => $customer_id),
            array('%d', '%s'),
            array('%d')
        );
        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return array('ok' => false, 'state' => 'error');
        }

        $inserted = $wpdb->insert($movements, array(
            'customer_id' => $customer_id,
            'direction' => $direction,
            'points_delta' => $delta,
            'balance_after' => $new_balance,
            'reference' => substr(sanitize_text_field((string) $reference), 0, 191),
            'note' => sanitize_textarea_field((string) $note),
            'created_by_user_id' => (int) $actor_user_id,
            'created_at_gmt' => $now,
        ), array('%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s'));
        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            return array('ok' => false, 'state' => 'error');
        }

        $movement_id = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT');
        return array(
            'ok' => true,
            'points' => $new_balance,
            'movement_id' => $movement_id,
        );
    }

    public static function get_loyalty_history($customer_id, $page = 1, $per_page = 20) {
        global $wpdb;
        $customer_id = (int) $customer_id;
        $page = max(1, (int) $page);
        $per_page = min(100, max(1, (int) $per_page));
        if ($customer_id <= 0) {
            return array();
        }

        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, customer_id, direction, points_delta, balance_after, reference, note, created_by_user_id, created_at_gmt'
            . ' FROM ' . self::table_loyalty_movements()
            . ' WHERE customer_id = %d ORDER BY created_at_gmt DESC, id DESC LIMIT %d OFFSET %d',
            $customer_id,
            $per_page,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get_loyalty_stats() {
        global $wpdb;
        $cards = self::table_loyalty_cards();
        return array(
            'customers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cards}"),
            'cards' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cards} WHERE card_number IS NOT NULL AND card_number <> ''"),
            'points' => (int) $wpdb->get_var("SELECT COALESCE(SUM(points_balance), 0) FROM {$cards}"),
        );
    }

    public static function get_pos_idempotency($idempotency_key) {
        global $wpdb;
        $idempotency_key = (string) $idempotency_key;
        if ($idempotency_key === '') {
            return null;
        }

        $table = self::table_pos_idempotency();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s LIMIT 1",
            $idempotency_key
        ), ARRAY_A);
    }

    public static function reserve_pos_idempotency($idempotency_key, $payload_hash, $user_id) {
        global $wpdb;
        $idempotency_key = (string) $idempotency_key;
        $payload_hash = (string) $payload_hash;
        $user_id = (int) $user_id;
        if ($idempotency_key === '' || strlen($idempotency_key) > 191 || !preg_match('/^[a-f0-9]{64}$/', $payload_hash)) {
            return array('state' => 'error', 'message' => 'Invalid idempotency reservation');
        }

        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(self::table_pos_idempotency(), array(
            'idempotency_key' => $idempotency_key,
            'payload_hash' => $payload_hash,
            'status' => 'processing',
            'order_id' => 0,
            'user_id' => $user_id,
            'http_status' => 0,
            'response_json' => null,
            'error_code' => '',
            'error_message' => '',
            'recovery_state' => 'reserved',
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
        ));

        if ($inserted) {
            return array(
                'state' => 'reserved',
                'record' => array(
                    'idempotency_key' => $idempotency_key,
                    'payload_hash' => $payload_hash,
                    'status' => 'processing',
                    'order_id' => 0,
                    'user_id' => $user_id,
                    'http_status' => 0,
                    'response_json' => null,
                    'error_code' => '',
                    'error_message' => '',
                    'recovery_state' => 'reserved',
                    'created_at_gmt' => $now,
                    'updated_at_gmt' => $now,
                ),
            );
        }

        $record = self::get_pos_idempotency($idempotency_key);
        if (is_array($record)) {
            return array('state' => 'existing', 'record' => $record);
        }

        return array('state' => 'error', 'message' => 'Unable to reserve idempotency key');
    }

    public static function mark_pos_idempotency_succeeded($idempotency_key, $payload_hash, $order_id, $http_status, $response) {
        global $wpdb;
        $response_json = wp_json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($response_json)) {
            return false;
        }

        $updated = $wpdb->update(
            self::table_pos_idempotency(),
            array(
                'status' => 'succeeded',
                'order_id' => (int) $order_id,
                'http_status' => (int) $http_status,
                'response_json' => $response_json,
                'error_code' => '',
                'error_message' => '',
                'recovery_state' => 'none',
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array(
                'idempotency_key' => (string) $idempotency_key,
                'payload_hash' => (string) $payload_hash,
                'status' => 'processing',
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%s', '%s', '%s')
        );

        return $updated === 1;
    }

    public static function mark_pos_idempotency_failed($idempotency_key, $payload_hash, $order_id, $http_status, $error_code, $error_message, $recovery_state) {
        global $wpdb;
        $updated = $wpdb->update(
            self::table_pos_idempotency(),
            array(
                'status' => 'failed',
                'order_id' => (int) $order_id,
                'http_status' => (int) $http_status,
                'response_json' => null,
                'error_code' => (string) $error_code,
                'error_message' => (string) $error_message,
                'recovery_state' => (string) $recovery_state,
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array(
                'idempotency_key' => (string) $idempotency_key,
                'payload_hash' => (string) $payload_hash,
                'status' => 'processing',
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%s', '%s', '%s')
        );

        return $updated === 1;
    }

    // =========================================================================
    // ==                       POS SHIFT / TURNO CASSA                        ==
    // =========================================================================

    /**
 * Assegna il progressivo scontrino e registra il documento in modo atomico.
 *
 * Il progressivo e' un dato fiscale: la sequenza non puo' ripetersi ne
 * saltare. Per questo il numero lo calcola il server dentro la stessa
 * operazione che scrive la riga, e l'indice univoco
 * (business_day_id, register_name, sequential_number) rende un duplicato
 * impossibile a livello di database, non solo di logica applicativa.
 *
 * Con un registratore telematico collegato il progressivo lo emette il
 * dispositivo e l'app lo registra: in quel caso sequential_number arriva
 * gia' valorizzato e non viene calcolato qui.
 *
 * Allocates the receipt number and records the document atomically. The
 * number is a fiscal record: the sequence can neither repeat nor skip.
 */
public static function issue_pos_receipt(array $row) {
    global $wpdb;

    $table = self::table_pos_receipts();
    $business_day_id = (string) ($row['business_day_id'] ?? '');
    $register_name = (string) ($row['register_name'] ?? '');
    $receipt_key = (string) ($row['receipt_key'] ?? '');
    if ($receipt_key === '') {
        return new WP_Error('mgws_bad_request', 'receipt_key is required', array('status' => 400));
    }

    // Idempotenza: la stessa receipt_key non deve mai produrre due righe.
    // Idempotency: the same receipt_key must never produce two rows.
    $existing = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . $table . ' WHERE receipt_key = %s LIMIT 1',
        $receipt_key
    ), ARRAY_A);
    if (is_array($existing)) {
        return array('row' => $existing, 'replayed' => true);
    }

    // Con un dispositivo fiscale il numero e' gia' noto: si usa quello.
    // With a fiscal device the number is already known: use it as is.
    $issued_by_device = !empty($row['sequential_number']);
    $sequential_number = $issued_by_device ? (int) $row['sequential_number'] : 0;

    if (!$issued_by_device) {
        $next = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MAX(sequential_number), 0) + 1 FROM ' . $table
            . ' WHERE business_day_id = %s AND register_name = %s',
            $business_day_id,
            $register_name
        ));
        $sequential_number = (int) $next;
    }

    $inserted = $wpdb->insert($table, array(
        'receipt_key' => $receipt_key,
        'business_day_id' => $business_day_id,
        'register_name' => $register_name,
        'sequential_number' => $sequential_number,
        'status' => (string) ($row['status'] ?? 'paid'),
        'operation_type' => (string) ($row['operation_type'] ?? 'sale'),
            'shift_id' => !empty($row['shift_id']) ? (int) $row['shift_id'] : null,
        'order_id' => (int) ($row['order_id'] ?? 0),
        'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
        'coupon_code' => (string) ($row['coupon_code'] ?? ''),
        'note' => !empty($row['note']) ? (string) $row['note'] : null,
        'payment_method' => (string) ($row['payment_method'] ?? ''),
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_name' => !empty($row['customer_name']) ? (string) $row['customer_name'] : null,
        'subtotal' => (float) ($row['subtotal'] ?? 0),
        'vat_total' => (float) ($row['vat_total'] ?? 0),
        'tax_rate' => (float) ($row['tax_rate'] ?? 0),
        'discount_total' => (float) ($row['discount_total'] ?? 0),
        'coupon_discount' => (float) ($row['coupon_discount'] ?? 0),
        'total' => (float) ($row['total'] ?? 0),
        'sales_total' => (float) ($row['sales_total'] ?? 0),
        'returns_total' => (float) ($row['returns_total'] ?? 0),
        'net_total' => (float) ($row['net_total'] ?? 0),
        'received_amount' => (float) ($row['received_amount'] ?? 0),
        'change_amount' => (float) ($row['change_amount'] ?? 0),
        'issued_at_gmt' => (string) ($row['issued_at_gmt'] ?? gmdate('Y-m-d H:i:s')),
        'created_by' => (int) ($row['created_by'] ?? 0),
        'created_at_gmt' => gmdate('Y-m-d H:i:s'),
    ));

    if ($inserted === false) {
        // Concorrenza fra due casse: l'indice univoco ha respinto il numero
        // gia' preso. Si ritenta una volta con il progressivo ricalcolato.
        // Concurrency between two tills: the unique index rejected a number
        // already taken. Retry once with a recalculated number.
        if (!$issued_by_device) {
            $next = $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(MAX(sequential_number), 0) + 1 FROM ' . $table
                . ' WHERE business_day_id = %s AND register_name = %s',
                $business_day_id,
                $register_name
            ));
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . $table . ' SET sequential_number = %d WHERE receipt_key = %s',
                (int) $next,
                $receipt_key
            ));
            $row_after = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . $table . ' WHERE receipt_key = %s LIMIT 1',
                $receipt_key
            ), ARRAY_A);
            if (is_array($row_after)) {
                return array('row' => $row_after, 'replayed' => false);
            }
        }
        return new WP_Error('mgws_receipt_failed', 'Receipt could not be recorded', array('status' => 500));
    }

    $saved = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . $table . ' WHERE receipt_key = %s LIMIT 1',
        $receipt_key
    ), ARRAY_A);

    return array('row' => is_array($saved) ? $saved : array(), 'replayed' => false);
}

/**
 * Cambia lo stato del documento o gli appende una rettifica.
 *
 * Lo storico contabile non si cancella: l'annullamento e' uno stato e le
 * rettifiche restano in elenco, quindi il documento resta ricostruibile.
 * Changes the document status or appends a correction. Accounting history is
 * never deleted: cancelling is a status and corrections are kept.
 */
public static function update_pos_receipt_status($receipt_id, $status, $note = '') {
    global $wpdb;

    $table = self::table_pos_receipts();
    $row = self::get_pos_receipt_by_id($receipt_id);
    if (!is_array($row)) {
        return new WP_Error('mgws_receipt_not_found', 'POS receipt not found', array('status' => 404));
    }
    if (($row['status'] ?? '') === 'cancelled' && $status !== 'cancelled') {
        return new WP_Error(
            'mgws_receipt_cancelled',
            'A cancelled receipt cannot change state',
            array('status' => 409)
        );
    }

    $corrections = self::get_pos_receipt_corrections($receipt_id);
    if ($note !== '') {
        $corrections[] = array(
            'note' => $note,
            'status' => $status,
            'by' => get_current_user_id(),
            'at' => gmdate('c'),
        );
    }

    $wpdb->update(
        $table,
        array(
            'status' => $status,
            'corrections' => wp_json_encode($corrections),
        ),
        array('id' => (int) $receipt_id)
    );

    return self::get_pos_receipt_by_id($receipt_id);
}

/**
 * Rettifiche del documento, in ordine di registrazione.
 * Returns the corrections recorded on a document.
 */
public static function get_pos_receipt_corrections($receipt_id) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        'SELECT corrections FROM ' . self::table_pos_receipts() . ' WHERE id = %d',
        (int) $receipt_id
    ));
    if (empty($raw)) {
        return array();
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

public static function get_pos_receipt_by_id($receipt_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . self::table_pos_receipts() . ' WHERE id = %d LIMIT 1',
        (int) $receipt_id
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

public static function get_pos_receipt_by_key($receipt_key) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . self::table_pos_receipts() . ' WHERE receipt_key = %s LIMIT 1',
        (string) $receipt_key
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

/**
 * Salva le righe del documento, senza duplicarle se il documento esiste gia'.
 *
 * Le righe rendono il documento consultabile e permettono al server di
 * controllare i resi: il residuo rendibile di una linea si calcola dalle
 * righe restituite, quindi non dipende piu' dallo storico locale del
 * dispositivo.
 *
 * Stores the document lines without duplicating them when the document
 * already exists. The lines make the document readable and let the server
 * enforce returns against real data instead of device-local history.
 */
public static function save_pos_receipt_lines($receipt_id, array $lines) {
    global $wpdb;

    $table = self::table_pos_receipt_lines();
    $existing = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE receipt_id = %d',
        (int) $receipt_id
    ));
    if ($existing > 0) {
        return 0;
    }

    $inserted = 0;
    foreach ($lines as $line) {
        $ok = $wpdb->insert($table, array(
            'receipt_id' => (int) $receipt_id,
            'line_key' => (string) ($line['line_key'] ?? ''),
            'movement_type' => (string) ($line['movement_type'] ?? 'sale'),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'variation_id' => (int) ($line['variation_id'] ?? 0),
            'sku' => (string) ($line['sku'] ?? ''),
            'barcode' => (string) ($line['barcode'] ?? ''),
            'line_name' => (string) ($line['name'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_price' => (float) ($line['unit_price'] ?? 0),
            'discount_total' => (float) ($line['discount_total'] ?? 0),
            'discount_percent' => (float) ($line['discount_percent'] ?? 0),
            'subtotal' => (float) ($line['subtotal'] ?? 0),
            'vat_rate' => (float) ($line['vat_rate'] ?? 0),
            'source_receipt_key' => (string) ($line['source_sale_id'] ?? ''),
            'source_line_key' => (string) ($line['source_line_key'] ?? ''),
            'return_reason' => (string) ($line['return_reason'] ?? ''),
            'return_outcome' => (string) ($line['return_outcome'] ?? ''),
            'created_at_gmt' => gmdate('Y-m-d H:i:s'),
        ));
        if ($ok) {
            $inserted++;
        }
    }
    return $inserted;
}

/**
 * Restituisce le righe vendute di un documento, per il controllo dei resi.
 * Returns the sold lines of a document, for return validation.
 */
public static function get_pos_receipt_sold_lines($receipt_key) {
    global $wpdb;
    // Gli apici espliciti evitano che i segnaposto diventino letterali senza
    // virgolette e rompano l'IN.
    // Explicit quoting keeps the placeholders from becoming unquoted literals
    // and breaking the IN clause.
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT rl.* FROM ' . self::table_pos_receipt_lines() . ' rl'
        . ' INNER JOIN ' . self::table_pos_receipts() . ' r ON r.id = rl.receipt_id'
        . ' WHERE r.receipt_key = %s AND r.operation_type IN (\'sale\', \'exchange\')',
        (string) $receipt_key
    ), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/**
 * Quanto e' ancora rendibile su ogni riga venduta di un documento.
 *
 * Restituisce `line_key => quantita` per le righe con residuo, cosi' il
 * cliente vede gli stessi numeri che il server ha calcolato. La quantita'
 * gia' restituita e' la somma dei resi che puntano a quella riga: e il
 * server a tenere il conto, perche' il dispositivo puo' perdersi.
 *
 * Returns how much is still returnable per sold line. Already-returned
 * quantities come from the returns recorded server-side, because the device
 * can be lost.
 */
public static function get_pos_returnable_quantities($receipt_key) {
    global $wpdb;

    $sold = self::get_pos_receipt_sold_lines($receipt_key);
    if (empty($sold)) {
        return array();
    }

    // Le righe vendute di un documento sono gia' al netto delle righe di reso
    // dello stesso documento, quindi si contano solo quelle col movement_type
    // 'vendita': cosi' un cambio non torna due volte nel totale.
    $sold_by_key = array();
    foreach ($sold as $line) {
        $line_key = (string) $line['line_key'];
        $movement = (string) ($line['movement_type'] ?? '');
        $quantity = (int) $line['quantity'];
        if ($movement === 'return') {
            // Un reso dentro il documento di origine riduce il venduto.
            $sold_by_key[$line_key] = ($sold_by_key[$line_key] ?? 0) - abs($quantity);
            continue;
        }
        $sold_by_key[$line_key] = ($sold_by_key[$line_key] ?? 0) + $quantity;
    }

    $returns = $wpdb->get_results($wpdb->prepare(
        'SELECT rl.source_line_key, SUM(ABS(rl.quantity)) AS returned_quantity FROM '
        . self::table_pos_receipt_lines() . ' rl'
        . ' INNER JOIN ' . self::table_pos_receipts() . ' r ON r.id = rl.receipt_id'
        . ' WHERE rl.movement_type = %s AND rl.source_receipt_key = %s'
        . ' GROUP BY rl.source_line_key',
        'return',
        (string) $receipt_key
    ), ARRAY_A);

    $returned_by_key = array();
    if (is_array($returns)) {
        foreach ($returns as $row) {
            $returned_by_key[(string) $row['source_line_key']] = (int) $row['returned_quantity'];
        }
    }

    $result = array();
    foreach ($sold_by_key as $line_key => $quantity) {
        $remaining = $quantity - (int) ($returned_by_key[$line_key] ?? 0);
        if ($remaining > 0) {
            $result[$line_key] = $remaining;
        }
    }
    return $result;
}

public static function insert_pos_shift($row) {
        global $wpdb;
        $ok = (bool) $wpdb->insert(self::table_pos_shifts(), array(
            'shift_key' => (string) $row['shift_key'],
            'status' => 'open',
            'giornata_id' => (string) ($row['giornata_id'] ?? ''),
            'cassa_name' => (string) ($row['cassa_name'] ?? ''),
            'sede' => !empty($row['sede']) ? (string) $row['sede'] : null,
            'operator_id' => !empty($row['operator_id']) ? (int) $row['operator_id'] : null,
            'operator_name' => !empty($row['operator_name']) ? (string) $row['operator_name'] : null,
            'fondo_iniziale' => (float) ($row['fondo_iniziale'] ?? 0),
            'opened_at_gmt' => gmdate('Y-m-d H:i:s'),
            'created_by' => (int) ($row['created_by'] ?? 0),
        ));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get_pos_shift_by_key($shift_key) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_pos_shifts() . ' WHERE shift_key = %s LIMIT 1',
            (string) $shift_key
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function get_pos_shift_by_id($shift_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_pos_shifts() . ' WHERE id = %d LIMIT 1',
            (int) $shift_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function get_open_pos_shift_by_cassa($cassa_name) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_pos_shifts() . ' WHERE status = %s AND cassa_name = %s ORDER BY id DESC LIMIT 1',
            'open',
            (string) $cassa_name
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function list_pos_shifts($filters = array(), $limit = 100) {
        global $wpdb;
        $where = array('1=1');
        $params = array();
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['cassa_name'])) {
            $where[] = 'cassa_name = %s';
            $params[] = (string) $filters['cassa_name'];
        }
        if (!empty($filters['giornata_id'])) {
            $where[] = 'giornata_id = %s';
            $params[] = (string) $filters['giornata_id'];
        }
        $sql = 'SELECT * FROM ' . self::table_pos_shifts() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY opened_at_gmt DESC, id DESC LIMIT %d';
        $params[] = max(1, min(100, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function close_pos_shift($shift_id, array $close_totals) {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table_pos_shifts(),
            array(
                'status' => 'closed',
                'closed_at_gmt' => gmdate('Y-m-d H:i:s'),
                'close_totals' => wp_json_encode($close_totals),
            ),
            array('id' => (int) $shift_id, 'status' => 'open'),
            array('%s', '%s', '%s'),
            array('%d', '%s')
        );
    }

    public static function create_or_update_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $levels = self::table_levels();
        $moves = self::table_moves();
        $movements = self::table_movements();
        $pos_idempotency = self::table_pos_idempotency();
        $pos_shifts = self::table_pos_shifts();
        $pos_receipts = self::table_pos_receipts();
        $pos_receipt_lines = self::table_pos_receipt_lines();
        $loyalty_cards = self::table_loyalty_cards();
        $loyalty_movements = self::table_loyalty_movements();
        $fornitori = self::table_fornitori();
        $reorder_rules = self::table_reorder_rules();
        $purchase_orders = self::table_purchase_orders();
        $purchase_order_lines = self::table_purchase_order_lines();
        $receipts = self::table_receipts();
        $receipt_lines = self::table_receipt_lines();
        $backorders = self::table_backorders();
        $count_sessions = self::table_inventory_count_sessions();
        $count_lines = self::table_inventory_count_lines();
        $reason_codes = self::table_stock_reason_codes();
        $employees = self::table_employees();

        $sql_levels = "CREATE TABLE {$levels} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            qty INT NOT NULL DEFAULT 0,
            room VARCHAR(80) NOT NULL DEFAULT '',
            rack VARCHAR(80) NOT NULL DEFAULT '',
            shelf VARCHAR(80) NOT NULL DEFAULT '',
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_level (warehouse_id, product_id, variation_id, room, rack, shelf),
            KEY idx_site (site_id, warehouse_id),
            KEY idx_item (product_id, variation_id),
            KEY idx_qty (qty)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_movements = "CREATE TABLE {$movements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            movement_key VARCHAR(64) NOT NULL DEFAULT '',
            ts_gmt DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(32) NOT NULL DEFAULT '',
            site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            note TEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_movement_key (movement_key),
            KEY idx_ts (ts_gmt),
            KEY idx_user_ts (user_id, ts_gmt),
            KEY idx_type_ts (type, ts_gmt)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_moves = "CREATE TABLE {$moves} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts_gmt DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(16) NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL,
            warehouse_from BIGINT UNSIGNED NOT NULL DEFAULT 0,
            warehouse_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            qty INT NOT NULL,
            room VARCHAR(80) NOT NULL DEFAULT '',
            rack VARCHAR(80) NOT NULL DEFAULT '',
            shelf VARCHAR(80) NOT NULL DEFAULT '',
            ref_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            stock_effect VARCHAR(32) NOT NULL DEFAULT '',
            source_type VARCHAR(32) NOT NULL DEFAULT '',
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            note TEXT NULL,
            movement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            stock_before INT NULL DEFAULT NULL,
            stock_after INT NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_order (ref_order_id),
            KEY idx_wh_ts (warehouse_id, ts_gmt),
            KEY idx_item_ts (product_id, variation_id, ts_gmt),
            KEY idx_user_ts (user_id, ts_gmt),
            KEY idx_source (source_type, source_id, source_line_id),
            KEY idx_movement (movement_id, id),
            KEY idx_stock_effect_ts (stock_effect, ts_gmt),
            KEY idx_reason_code_ts (reason_code, ts_gmt)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_pos_idempotency = "CREATE TABLE {$pos_idempotency} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idempotency_key VARCHAR(191) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_json LONGTEXT NULL,
            error_code VARCHAR(64) NOT NULL DEFAULT '',
            error_message TEXT NULL,
            recovery_state VARCHAR(64) NOT NULL DEFAULT '',
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_idempotency_key (idempotency_key),
            KEY idx_payload_hash (payload_hash),
            KEY idx_order (order_id),
            KEY idx_status_updated (status, updated_at_gmt)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_pos_shifts = "CREATE TABLE {$pos_shifts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shift_key VARCHAR(191) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'open',
            giornata_id VARCHAR(64) NOT NULL DEFAULT '',
            cassa_name VARCHAR(191) NOT NULL DEFAULT '',
            sede VARCHAR(191) NULL DEFAULT NULL,
            operator_id BIGINT UNSIGNED NULL DEFAULT NULL,
            operator_name VARCHAR(191) NULL DEFAULT NULL,
            fondo_iniziale DECIMAL(15,4) NOT NULL DEFAULT 0,
            opened_at_gmt DATETIME NOT NULL,
            closed_at_gmt DATETIME NULL DEFAULT NULL,
            close_totals LONGTEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_shift_key (shift_key),
            KEY idx_status_cassa (status, cassa_name),
            KEY idx_giornata (giornata_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_pos_receipts = "CREATE TABLE {$pos_receipts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_key VARCHAR(191) NOT NULL,
            business_day_id VARCHAR(64) NOT NULL DEFAULT '',
            register_name VARCHAR(191) NOT NULL DEFAULT '',
            sequential_number INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'paid',
            operation_type VARCHAR(32) NOT NULL DEFAULT 'sale',
            shift_id BIGINT UNSIGNED NULL DEFAULT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            idempotency_key VARCHAR(191) NOT NULL DEFAULT '',
            coupon_code VARCHAR(191) NOT NULL DEFAULT '',
            note TEXT NULL,
            payment_method VARCHAR(64) NOT NULL DEFAULT '',
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(191) NULL DEFAULT NULL,
            subtotal DECIMAL(15,4) NOT NULL DEFAULT 0,
            vat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            discount_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            coupon_discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            corrections LONGTEXT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0,
            sales_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            returns_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            net_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            received_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            change_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            issued_at_gmt DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_receipt_key (receipt_key),
            UNIQUE KEY uq_sequential (business_day_id, register_name, sequential_number),
            KEY idx_day_register (business_day_id, register_name),
            KEY idx_issued (issued_at_gmt),
            KEY idx_shift (shift_id),
            KEY idx_operation (operation_type)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_pos_receipt_lines = "CREATE TABLE {$pos_receipt_lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            line_key VARCHAR(191) NOT NULL DEFAULT '',
            movement_type VARCHAR(16) NOT NULL DEFAULT 'sale',
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            barcode VARCHAR(191) NOT NULL DEFAULT '',
            line_name VARCHAR(255) NOT NULL DEFAULT '',
            quantity INT NOT NULL DEFAULT 0,
            unit_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,4) NOT NULL DEFAULT 0,
            vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            source_receipt_key VARCHAR(191) NOT NULL DEFAULT '',
            source_line_key VARCHAR(191) NOT NULL DEFAULT '',
            return_reason VARCHAR(191) NOT NULL DEFAULT '',
            return_outcome VARCHAR(32) NOT NULL DEFAULT '',
            created_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_receipt (receipt_id),
            KEY idx_line_key (receipt_id, line_key),
            KEY idx_source (source_receipt_key, source_line_key)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_loyalty_cards = "CREATE TABLE {$loyalty_cards} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            card_number VARCHAR(191) NULL DEFAULT NULL,
            tier VARCHAR(32) NOT NULL DEFAULT 'bronze',
            points_balance INT NOT NULL DEFAULT 0,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_customer (customer_id),
            UNIQUE KEY uq_card_number (card_number),
            KEY idx_card_lookup (card_number)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_loyalty_movements = "CREATE TABLE {$loyalty_movements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            direction VARCHAR(16) NOT NULL,
            points_delta INT NOT NULL,
            balance_after INT NOT NULL,
            reference VARCHAR(191) NOT NULL DEFAULT '',
            note TEXT NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_customer_time (customer_id, created_at_gmt, id),
            KEY idx_created_by_time (created_by_user_id, created_at_gmt)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_fornitori = "CREATE TABLE {$fornitori} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL DEFAULT '',
            tax_id VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            phone VARCHAR(64) NOT NULL DEFAULT '',
            notes TEXT NULL,
            payment_terms_days INT NOT NULL DEFAULT 0,
            iban VARCHAR(64) NOT NULL DEFAULT '',
            lead_time_days INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_active (active),
            KEY idx_name (name),
            KEY idx_email (email)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_reorder_rules = "CREATE TABLE {$reorder_rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reorder_point INT NOT NULL DEFAULT 0,
            target_stock INT NOT NULL DEFAULT 0,
            reorder_quantity INT NOT NULL DEFAULT 0,
            lead_time_days INT NOT NULL DEFAULT 0,
            safety_days INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_reorder_item (site_id, warehouse_id, product_id, variation_id),
            KEY idx_supplier_active (supplier_id, active),
            KEY idx_reorder_item (product_id, variation_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_purchase_orders = "CREATE TABLE {$purchase_orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL,
            document_number VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            ordered_at_gmt DATETIME NULL,
            expected_at_gmt DATETIME NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
            notes TEXT NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_purchase_order_number (site_id, document_number),
            KEY idx_supplier_status (supplier_id, status),
            KEY idx_warehouse_status (warehouse_id, status)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_purchase_order_lines = "CREATE TABLE {$purchase_order_lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_order_id BIGINT UNSIGNED NOT NULL,
            line_number INT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ordered_qty INT NOT NULL DEFAULT 0,
            received_qty INT NOT NULL DEFAULT 0,
            cancelled_qty INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
            supplier_sku VARCHAR(191) NOT NULL DEFAULT '',
            barcode VARCHAR(191) NOT NULL DEFAULT '',
            expected_at_gmt DATETIME NULL,
            stock_effect VARCHAR(32) NOT NULL DEFAULT 'incoming',
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_purchase_order_line (purchase_order_id, line_number),
            KEY idx_item (product_id, variation_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_receipts = "CREATE TABLE {$receipts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            purchase_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            document_number VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            idempotency_key VARCHAR(191) NULL DEFAULT NULL,
            received_at_gmt DATETIME NULL,
            validated_at_gmt DATETIME NULL,
            posted_at_gmt DATETIME NULL,
            validated_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            posted_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_receipt_number (site_id, document_number),
            UNIQUE KEY uq_receipt_idempotency (idempotency_key),
            KEY idx_purchase_order_status (purchase_order_id, status),
            KEY idx_supplier_status (supplier_id, status)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_receipt_lines = "CREATE TABLE {$receipt_lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id BIGINT UNSIGNED NOT NULL,
            line_number INT UNSIGNED NOT NULL,
            purchase_order_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            expected_qty INT NOT NULL DEFAULT 0,
            received_qty INT NOT NULL DEFAULT 0,
            rejected_qty INT NOT NULL DEFAULT 0,
            backorder_qty INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
            stock_effect VARCHAR(32) NOT NULL DEFAULT 'load',
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_receipt_line (receipt_id, line_number),
            KEY idx_purchase_order_line (purchase_order_line_id),
            KEY idx_item (product_id, variation_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_backorders = "CREATE TABLE {$backorders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_line_id BIGINT UNSIGNED NOT NULL,
            purchase_order_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            remaining_qty INT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            expected_at_gmt DATETIME NULL,
            resolved_at_gmt DATETIME NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_backorder_receipt_line (receipt_line_id),
            KEY idx_purchase_order_line_status (purchase_order_line_id, status),
            KEY idx_item_status (product_id, variation_id, status)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_count_sessions = "CREATE TABLE {$count_sessions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            document_number VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            started_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            approved_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_at_gmt DATETIME NULL,
            approved_at_gmt DATETIME NULL,
            posted_at_gmt DATETIME NULL,
            notes TEXT NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_count_session_number (site_id, document_number),
            KEY idx_warehouse_status (warehouse_id, status)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_count_lines = "CREATE TABLE {$count_lines} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            count_session_id BIGINT UNSIGNED NOT NULL,
            warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            room VARCHAR(80) NOT NULL DEFAULT '',
            rack VARCHAR(80) NOT NULL DEFAULT '',
            shelf VARCHAR(80) NOT NULL DEFAULT '',
            book_qty INT NOT NULL DEFAULT 0,
            physical_qty INT NOT NULL DEFAULT 0,
            discrepancy_qty INT NOT NULL DEFAULT 0,
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            stock_move_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            counted_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            counted_at_gmt DATETIME NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_count_line (count_session_id, warehouse_id, product_id, variation_id, room, rack, shelf),
            KEY idx_item (product_id, variation_id),
            KEY idx_stock_move (stock_move_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_reason_codes = "CREATE TABLE {$reason_codes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            stock_effect VARCHAR(32) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_reason_code (site_id, code),
            KEY idx_site_active (site_id, active),
            KEY idx_stock_effect (stock_effect)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_employees = "CREATE TABLE {$employees} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            first_name VARCHAR(191) NOT NULL DEFAULT '',
            last_name VARCHAR(191) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            phone VARCHAR(64) NOT NULL DEFAULT '',
            role_label VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            active TINYINT(1) NOT NULL DEFAULT 1,
            salary_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            salary_currency CHAR(3) NOT NULL DEFAULT 'EUR',
            notes TEXT NULL,
            created_at_gmt DATETIME NOT NULL,
            updated_at_gmt DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_active_name (active, last_name, first_name),
            KEY idx_wp_user (wp_user_id),
            KEY idx_email (email)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_levels);
        dbDelta($sql_movements);
        dbDelta($sql_moves);
        dbDelta($sql_pos_idempotency);
        dbDelta($sql_pos_shifts);
        dbDelta($sql_pos_receipts);
        dbDelta($sql_pos_receipt_lines);
        dbDelta($sql_loyalty_cards);
        // Aggiunge la colonna "enabled" se manca dalle installazioni precedenti.
        $existingColumns = $wpdb->get_col("SHOW COLUMNS FROM {$loyalty_cards} LIKE 'enabled'");
        if (empty($existingColumns)) {
            $wpdb->query("ALTER TABLE {$loyalty_cards} ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER updated_at_gmt");
        }
        dbDelta($sql_loyalty_movements);
        dbDelta($sql_fornitori);
        // Migrazione da fornitori basati su Woo customer: la nuova anagrafica
        // fornitori e' autonoma e non deve avere colonne utente/customer.
        $legacySupplierColumns = $wpdb->get_col("SHOW COLUMNS FROM {$fornitori} LIKE 'woo_customer_id'");
        if (!empty($legacySupplierColumns)) {
            $wpdb->query("ALTER TABLE {$fornitori} DROP INDEX uq_woo_customer");
            $wpdb->query("ALTER TABLE {$fornitori} DROP COLUMN woo_customer_id");
        }
        dbDelta($sql_reorder_rules);
        dbDelta($sql_purchase_orders);
        dbDelta($sql_purchase_order_lines);
        dbDelta($sql_receipts);
        dbDelta($sql_receipt_lines);
        dbDelta($sql_backorders);
        dbDelta($sql_count_sessions);
        dbDelta($sql_count_lines);
        dbDelta($sql_reason_codes);
        dbDelta($sql_employees);
    }

    public static function get_site_id_for_warehouse($warehouse_id) {
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return 0;
        }
        return (int) get_post_meta($warehouse_id, 'mg_site_id', true);
    }

    public static function reassign_levels_site_id($warehouse_id, $new_site_id) {
        global $wpdb;
        $levels = self::table_levels();
        $warehouse_id = (int) $warehouse_id;
        $new_site_id = (int) $new_site_id;
        if ($warehouse_id <= 0) {
            return;
        }
        $wpdb->update(
            $levels,
            array('site_id' => $new_site_id, 'updated_at_gmt' => gmdate('Y-m-d H:i:s')),
            array('warehouse_id' => $warehouse_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    public static function sanitize_loc($value) {
        $value = trim($value);
        if (strlen($value) > 80) {
            $value = substr($value, 0, 80);
        }
        return $value;
    }

    public static function format_location_label($room, $rack, $shelf) {
        $parts = array();
        $room = trim((string) $room);
        $rack = trim((string) $rack);
        $shelf = trim((string) $shelf);
        if ($room !== '') {
            $parts[] = $room;
        }
        if ($rack !== '') {
            $parts[] = $rack;
        }
        if ($shelf !== '') {
            $parts[] = $shelf;
        }
        if (empty($parts)) {
            return __('No location', 'mg-warehouse-stock');
        }
        return implode(' / ', $parts);
    }

    public static function get_levels_for_items($cards, $site_limit) {
        global $wpdb;
        $table = self::table_levels();

        $conds = array();
        $params = array();
        foreach ($cards as $c) {
            $pid = (int) $c['product_id'];
            $vid = (int) $c['variation_id'];
            $conds[] = '(product_id = %d AND variation_id = %d)';
            $params[] = $pid;
            $params[] = $vid;
        }
        $where = implode(' OR ', $conds);
        $sql = "SELECT site_id, warehouse_id, product_id, variation_id, qty, room, rack, shelf
                FROM {$table}
                WHERE qty >= 1 AND ({$where})";
        if ((int) $site_limit > 0) {
            $sql .= ' AND site_id = %d';
            $params[] = (int) $site_limit;
        }
        $sql .= ' ORDER BY site_id ASC, warehouse_id ASC, qty DESC';

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $rows = is_array($rows) ? $rows : array();

        // If warehouse has location links, hide non-allowed locations.
        $cache = array();
        $out = array();
        foreach ($rows as $r) {
            $wid = (int) ($r['warehouse_id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            if (!isset($cache[$wid])) {
                $cache[$wid] = self::get_warehouse_location_tree($wid);
            }
            $wh_tree = $cache[$wid];
            $rooms = $wh_tree['rooms'] ?? array();
            // If no links configured, keep all.
            if (!is_array($rooms) || empty($rooms)) {
                $out[] = $r;
                continue;
            }
            if (self::warehouse_allows_location($wid, (string) ($r['room'] ?? ''), (string) ($r['rack'] ?? ''), (string) ($r['shelf'] ?? ''))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function get_levels_for_item($product_id, $variation_id, $site_limit = 0) {
        global $wpdb;
        $table = self::table_levels();
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $site_limit = (int) $site_limit;
        if ($product_id <= 0) {
            return array();
        }

        $sql = "SELECT * FROM {$table} WHERE product_id=%d AND variation_id=%d";
        $params = array($product_id, $variation_id);
        if ($site_limit > 0) {
            $sql .= ' AND site_id=%d';
            $params[] = $site_limit;
        }
        $sql .= ' ORDER BY site_id ASC, warehouse_id ASC, room ASC, rack ASC, shelf ASC';

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get_levels_for_warehouse($warehouse_id, $site_limit = 0) {
        global $wpdb;
        $table = self::table_levels();
        $warehouse_id = (int) $warehouse_id;
        $site_limit = (int) $site_limit;
        if ($warehouse_id <= 0) {
            return array();
        }
        $sql = "SELECT * FROM {$table} WHERE warehouse_id=%d";
        $params = array($warehouse_id);
        if ($site_limit > 0) {
            $sql .= ' AND site_id=%d';
            $params[] = $site_limit;
        }
        $sql .= ' ORDER BY product_id ASC, variation_id ASC, room ASC, rack ASC, shelf ASC';
        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function upsert_level($warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf) {
        global $wpdb;
        $levels = self::table_levels();

        $warehouse_id = (int) $warehouse_id;
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $qty = (int) $qty;
        $room = self::sanitize_loc($room);
        $rack = self::sanitize_loc($rack);
        $shelf = self::sanitize_loc($shelf);

        $site_id = self::get_site_id_for_warehouse($warehouse_id);
        if ($site_id <= 0) {
            return array('ok' => false, 'message' => __('Warehouse has no site', 'mg-warehouse-stock'));
        }

        if (!self::warehouse_allows_location($warehouse_id, $room, $rack, $shelf)) {
            return array('ok' => false, 'message' => __('Location is not linked to this warehouse. Link the shelves in WooCommerce → Warehouse.', 'mg-warehouse-stock'));
        }

        $now = gmdate('Y-m-d H:i:s');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, qty FROM {$levels} WHERE warehouse_id=%d AND product_id=%d AND variation_id=%d AND room=%s AND rack=%s AND shelf=%s",
            $warehouse_id,
            $product_id,
            $variation_id,
            $room,
            $rack,
            $shelf
        ), ARRAY_A);

        if ($existing) {
            $wpdb->update(
                $levels,
                array('site_id' => $site_id, 'qty' => $qty, 'updated_at_gmt' => $now),
                array('id' => (int) $existing['id']),
                array('%d', '%d', '%s'),
                array('%d')
            );
            return array('ok' => true, 'old_qty' => (int) $existing['qty'], 'new_qty' => $qty, 'site_id' => $site_id);
        }

        $inserted = $wpdb->insert($levels, array(
            'site_id' => $site_id,
            'warehouse_id' => $warehouse_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'qty' => $qty,
            'room' => $room,
            'rack' => $rack,
            'shelf' => $shelf,
            'updated_at_gmt' => $now,
        ));
        if (!$inserted) {
            return array('ok' => false, 'message' => __('Could not write the stock level', 'mg-warehouse-stock'));
        }
        return array('ok' => true, 'old_qty' => 0, 'new_qty' => $qty, 'site_id' => $site_id);
    }

    /**
     * Colonne opzionali accettate da {@see insert_move()}.
     *
     * Sono un elenco chiuso perche' `insert_move()` ha gia' quattordici
     * parametri posizionali: aggiungerne altri renderebbe ogni chiamata
     * illegibile e un refuso silenzioso invece che un errore. La chiave sbagliata
     * viene scartata, non passata a `$wpdb`.
     */
    private static function move_extra_columns() {
        return array('movement_id', 'stock_before', 'stock_after', 'stock_effect', 'source_type', 'source_id', 'source_line_id', 'reason_code');
    }

    public static function insert_move($type, $site_id, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, $ref_order_id = 0, $note = null, $warehouse_from = 0, $warehouse_to = 0, array $extra = array()) {
        global $wpdb;
        $moves = self::table_moves();

        $data = array(
            'ts_gmt' => gmdate('Y-m-d H:i:s'),
            'user_id' => (int) $user_id,
            'type' => (string) $type,
            'site_id' => (int) $site_id,
            'warehouse_id' => (int) $warehouse_id,
            'warehouse_from' => (int) $warehouse_from,
            'warehouse_to' => (int) $warehouse_to,
            'product_id' => (int) $product_id,
            'variation_id' => (int) $variation_id,
            'qty' => (int) $qty,
            'room' => self::sanitize_loc($room),
            'rack' => self::sanitize_loc($rack),
            'shelf' => self::sanitize_loc($shelf),
            'ref_order_id' => (int) $ref_order_id,
            'note' => $note,
        );
        $allowed = self::move_extra_columns();
        foreach ($extra as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $data[$column] = $value === null ? null : (is_int($value) ? $value : (string) $value);
        }

        $inserted = $wpdb->insert($moves, $data);
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Crea o recupera l'intestazione di un movimento e restituisce il suo id.
     *
     * L'app invia una riga per volta, quindi non puo' creare il movimento con la
     * prima riga e completarlo con le altre: ogni richiesta porta la stessa
     * `movement_key` e la prima che arriva crea l'intestazione, le successive la
     * ritrovano. `INSERT IGNORE` sulla chiave unica rende due richieste della
     * stessa operazione che arrivano insieme un no-op invece di un errore.
     *
     * Senza `movement_key` vale 0 e la riga resta senza movimento: e' il caso dei
     * flussi interni del plugin che scrivono il libro senza passare da qui.
     */
    public static function ensure_movement($movement_key, $type, $user_id, $site_id = 0, $reason_code = '', $note = null) {
        global $wpdb;

        $key = self::sanitize_movement_key($movement_key);
        if ($key === '') {
            return 0;
        }
        $table = self::table_movements();
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (movement_key, ts_gmt, user_id, type, site_id, reason_code, note) VALUES (%s, %s, %d, %s, %d, %s, %s)",
            $key,
            gmdate('Y-m-d H:i:s'),
            (int) $user_id,
            (string) $type,
            (int) $site_id,
            (string) $reason_code,
            $note === null ? null : (string) $note
        ));
        $wpdb->suppress_errors($suppress);

        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE movement_key = %s", $key));
        return $id;
    }

    public static function get_movement($movement_id) {
        global $wpdb;
        $id = (int) $movement_id;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_movements() . " WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Riduce a una chiave confrontabile e limitata in lunghezza.
     *
     * La chiave arriva dal client ed entra in una colonna con indice unico: se
     * contiene caratteri di controllo o supera la lunghezza della colonna,
     * l'indice la troncerebbe o fallirebbe l'inserimento. Meglio ridurla qui, una
     * volta sola, che lasciare che a farlo sia il database.
     */
    private static function sanitize_movement_key($movement_key) {
        $key = strtolower(trim((string) $movement_key));
        $key = preg_replace('/[^a-z0-9\-_]/', '', $key);
        if (!is_string($key)) {
            return '';
        }
        return substr($key, 0, 64);
    }

    public static function list_moves($filters = array(), $limit = 50, $offset = 0) {
        global $wpdb;
        $where = array('1=1');
        $params = array();
        foreach (array('site_id', 'product_id', 'user_id') as $column) {
            if (isset($filters[$column]) && (int) $filters[$column] > 0) {
                $where[] = $column . ' = %d';
                $params[] = (int) $filters[$column];
            }
        }
        if (array_key_exists('variation_id', $filters) && (int) $filters['variation_id'] >= 0) {
            $where[] = 'variation_id = %d';
            $params[] = (int) $filters['variation_id'];
        }
        foreach (array('source_type', 'reason_code', 'stock_effect') as $column) {
            if (!empty($filters[$column])) {
                $where[] = $column . ' = %s';
                $params[] = (string) $filters[$column];
            }
        }
        if (isset($filters['movement_id']) && (int) $filters['movement_id'] > 0) {
            $where[] = 'movement_id = %d';
            $params[] = (int) $filters['movement_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'ts_gmt >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'ts_gmt <= %s';
            $params[] = (string) $filters['date_to'];
        }
        $sql = 'SELECT * FROM ' . self::table_moves() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ts_gmt DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = max(1, min(100, (int) $limit));
        $params[] = max(0, (int) $offset);
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function count_moves($filters = array()) {
        global $wpdb;
        $where = array('1=1');
        $params = array();
        foreach (array('site_id', 'product_id', 'user_id') as $column) {
            if (isset($filters[$column]) && (int) $filters[$column] > 0) {
                $where[] = $column . ' = %d';
                $params[] = (int) $filters[$column];
            }
        }
        if (array_key_exists('variation_id', $filters) && (int) $filters['variation_id'] >= 0) {
            $where[] = 'variation_id = %d';
            $params[] = (int) $filters['variation_id'];
        }
        foreach (array('source_type', 'reason_code', 'stock_effect') as $column) {
            if (!empty($filters[$column])) {
                $where[] = $column . ' = %s';
                $params[] = (string) $filters[$column];
            }
        }
        if (isset($filters['movement_id']) && (int) $filters['movement_id'] > 0) {
            $where[] = 'movement_id = %d';
            $params[] = (int) $filters['movement_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'ts_gmt >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'ts_gmt <= %s';
            $params[] = (string) $filters['date_to'];
        }
        $sql = 'SELECT COUNT(*) FROM ' . self::table_moves() . ' WHERE ' . implode(' AND ', $where);
        return (int) $wpdb->get_var($params === array() ? $sql : $wpdb->prepare($sql, $params));
    }

    public static function get_move($movement_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_moves() . ' WHERE id = %d', (int) $movement_id), ARRAY_A);
    }

    public static function insert_inventory_count_move($site_id, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, $session_id, $line_id, $reason_code, $note) {
        global $wpdb;
        $inserted = $wpdb->insert(self::table_moves(), array(
            'ts_gmt' => gmdate('Y-m-d H:i:s'),
            'user_id' => (int) $user_id,
            'type' => 'adjust',
            'site_id' => (int) $site_id,
            'warehouse_id' => (int) $warehouse_id,
            'warehouse_from' => 0,
            'warehouse_to' => 0,
            'product_id' => (int) $product_id,
            'variation_id' => (int) $variation_id,
            'qty' => (int) $qty,
            'room' => self::sanitize_loc($room),
            'rack' => self::sanitize_loc($rack),
            'shelf' => self::sanitize_loc($shelf),
            'ref_order_id' => 0,
            'stock_effect' => 'adjustment',
            'source_type' => 'inventory_count',
            'source_id' => (int) $session_id,
            'source_line_id' => (int) $line_id,
            'reason_code' => self::sanitize_loc($reason_code),
            'note' => $note,
        ));
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function insert_quick_load_move($site_id, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, $note, $movement_id = 0, $stock_before = null, $stock_after = null) {
        global $wpdb;
        $inserted = $wpdb->insert(self::table_moves(), array(
            'ts_gmt' => gmdate('Y-m-d H:i:s'),
            'user_id' => (int) $user_id,
            'type' => 'in',
            'site_id' => (int) $site_id,
            'warehouse_id' => (int) $warehouse_id,
            'warehouse_from' => 0,
            'warehouse_to' => 0,
            'product_id' => (int) $product_id,
            'variation_id' => (int) $variation_id,
            'qty' => (int) $qty,
            'room' => self::sanitize_loc($room),
            'rack' => self::sanitize_loc($rack),
            'shelf' => self::sanitize_loc($shelf),
            'ref_order_id' => 0,
            'stock_effect' => 'load',
            'source_type' => 'quick_load',
            'source_id' => 0,
            'source_line_id' => 0,
            'reason_code' => 'quick_load',
            'note' => $note,
            'movement_id' => (int) $movement_id,
            'stock_before' => $stock_before === null ? null : (int) $stock_before,
            'stock_after' => $stock_after === null ? null : (int) $stock_after,
        ));
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function insert_receipt_move($site_id, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, $receipt_id, $receipt_line_id, $reason_code, $note) {
        global $wpdb;
        $inserted = $wpdb->insert(self::table_moves(), array(
            'ts_gmt' => gmdate('Y-m-d H:i:s'),
            'user_id' => (int) $user_id,
            'type' => 'in',
            'site_id' => (int) $site_id,
            'warehouse_id' => (int) $warehouse_id,
            'warehouse_from' => 0,
            'warehouse_to' => 0,
            'product_id' => (int) $product_id,
            'variation_id' => (int) $variation_id,
            'qty' => (int) $qty,
            'room' => self::sanitize_loc($room),
            'rack' => self::sanitize_loc($rack),
            'shelf' => self::sanitize_loc($shelf),
            'ref_order_id' => 0,
            'stock_effect' => 'load',
            'source_type' => 'receipt',
            'source_id' => (int) $receipt_id,
            'source_line_id' => (int) $receipt_line_id,
            'reason_code' => self::sanitize_loc($reason_code),
            'note' => $note,
        ));
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function get_level_row($warehouse_id, $product_id, $variation_id, $room, $rack, $shelf) {
        global $wpdb;
        $levels = self::table_levels();
        $warehouse_id = (int) $warehouse_id;
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $room = self::sanitize_loc($room);
        $rack = self::sanitize_loc($rack);
        $shelf = self::sanitize_loc($shelf);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$levels} WHERE warehouse_id=%d AND product_id=%d AND variation_id=%d AND room=%s AND rack=%s AND shelf=%s",
            $warehouse_id,
            $product_id,
            $variation_id,
            $room,
            $rack,
            $shelf
        ), ARRAY_A);
    }

    public static function apply_delta_level($warehouse_id, $product_id, $variation_id, $delta_qty, $room, $rack, $shelf) {
        global $wpdb;
        $levels = self::table_levels();

        $warehouse_id = (int) $warehouse_id;
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $delta_qty = (int) $delta_qty;
        $room = self::sanitize_loc($room);
        $rack = self::sanitize_loc($rack);
        $shelf = self::sanitize_loc($shelf);

        $site_id = self::get_site_id_for_warehouse($warehouse_id);
        if ($site_id <= 0) {
            return array('ok' => false, 'message' => __('Warehouse has no site', 'mg-warehouse-stock'));
        }

        if (!self::warehouse_allows_location($warehouse_id, $room, $rack, $shelf)) {
            return array('ok' => false, 'message' => __('Location is not linked to this warehouse. Link the shelves in WooCommerce → Warehouse.', 'mg-warehouse-stock'));
        }

        $now = gmdate('Y-m-d H:i:s');

        // Try update first.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$levels}
             SET qty = qty + %d, site_id=%d, updated_at_gmt=%s
             WHERE warehouse_id=%d AND product_id=%d AND variation_id=%d AND room=%s AND rack=%s AND shelf=%s
               AND (qty + %d) >= 0",
            $delta_qty,
            $site_id,
            $now,
            $warehouse_id,
            $product_id,
            $variation_id,
            $room,
            $rack,
            $shelf,
            $delta_qty
        ));

        if ((int) $updated === 1) {
            $new_qty = self::get_level_row($warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
            return array('ok' => true, 'site_id' => $site_id, 'new_qty' => (int) ($new_qty['qty'] ?? 0));
        }

        // If missing and delta positive, insert.
        if ($delta_qty > 0) {
            $inserted = $wpdb->insert($levels, array(
                'site_id' => $site_id,
                'warehouse_id' => $warehouse_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'qty' => $delta_qty,
                'room' => $room,
                'rack' => $rack,
                'shelf' => $shelf,
                'updated_at_gmt' => $now,
            ));
            if ($inserted) {
                return array('ok' => true, 'site_id' => $site_id, 'new_qty' => $delta_qty);
            }
            return array('ok' => false, 'message' => __('Could not write the stock level', 'mg-warehouse-stock'));
        }

        return array('ok' => false, 'message' => __('Not enough stock, or no such stock level', 'mg-warehouse-stock'));
    }

    public static function get_moves($filters = array(), $limit = 200) {
        global $wpdb;
        $moves = self::table_moves();
        $where = array('1=1');
        $params = array();

        $limit = (int) $limit;
        if ($limit <= 0 || $limit > 1000) {
            $limit = 200;
        }

        if (!empty($filters['site_id'])) {
            $where[] = 'site_id = %d';
            $params[] = (int) $filters['site_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            $where[] = 'warehouse_id = %d';
            $params[] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['ref_order_id'])) {
            $where[] = 'ref_order_id = %d';
            $params[] = (int) $filters['ref_order_id'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 'product_id = %d';
            $params[] = (int) $filters['product_id'];
        }
        if (isset($filters['variation_id']) && $filters['variation_id'] !== '') {
            $where[] = 'variation_id = %d';
            $params[] = (int) $filters['variation_id'];
        }
        if (!empty($filters['from_gmt'])) {
            $where[] = 'ts_gmt >= %s';
            $params[] = (string) $filters['from_gmt'];
        }
        if (!empty($filters['to_gmt'])) {
            $where[] = 'ts_gmt <= %s';
            $params[] = (string) $filters['to_gmt'];
        }

        $sql = "SELECT * FROM {$moves} WHERE " . implode(' AND ', $where) . " ORDER BY ts_gmt DESC, id DESC LIMIT {$limit}";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get_location_suggestions_for_warehouse($warehouse_id) {
        global $wpdb;
        $levels = self::table_levels();
        $warehouse_id = (int) $warehouse_id;
        if ($warehouse_id <= 0) {
            return array(
                'rooms' => array(),
                'racks' => array(),
                'shelves' => array(),
                'racks_by_room' => array(),
                'shelves_by_room_rack' => array(),
                'tree' => array('rooms' => array()),
            );
        }

        $site_id = self::get_site_id_for_warehouse($warehouse_id);
        if ($site_id <= 0) {
            return array(
                'rooms' => array(),
                'racks' => array(),
                'shelves' => array(),
                'racks_by_room' => array(),
                'shelves_by_room_rack' => array(),
                'tree' => array('rooms' => array()),
            );
        }

        // Master data is site-level: rooms can be reused across multiple warehouses in the same site.
        // Backward-compat: also read legacy per-warehouse arrays/tree and merge into site tree.
        $meta_rooms = get_post_meta($site_id, 'mgws_site_rooms', true);
        $meta_racks = get_post_meta($site_id, 'mgws_site_racks', true);
        $meta_shelves = get_post_meta($site_id, 'mgws_site_shelves', true);
        if (!is_array($meta_rooms)) {
            $meta_rooms = array();
        }
        if (!is_array($meta_racks)) {
            $meta_racks = array();
        }
        if (!is_array($meta_shelves)) {
            $meta_shelves = array();
        }

        $legacy_rooms = get_post_meta($warehouse_id, 'mgws_rooms', true);
        $legacy_racks = get_post_meta($warehouse_id, 'mgws_racks', true);
        $legacy_shelves = get_post_meta($warehouse_id, 'mgws_shelves', true);
        if (is_array($legacy_rooms)) {
            $meta_rooms = array_merge($meta_rooms, $legacy_rooms);
        }
        if (is_array($legacy_racks)) {
            $meta_racks = array_merge($meta_racks, $legacy_racks);
        }
        if (is_array($legacy_shelves)) {
            $meta_shelves = array_merge($meta_shelves, $legacy_shelves);
        }

        // Use real stock combos across the entire site to keep the shared structure complete.
        $combos = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT room, rack, shelf FROM {$levels} WHERE site_id=%d AND room <> '' ORDER BY room ASC, rack ASC, shelf ASC LIMIT 900",
            $site_id
        ), ARRAY_A);

        // Hierarchical tree is site-level (shared across warehouses).
        $tree = self::get_site_location_tree($site_id);

        $legacy_tree = get_post_meta($warehouse_id, 'mgws_loc_tree', true);
        if (!empty($legacy_tree)) {
            self::merge_tree($tree, $legacy_tree);
        }

        // Merge combos from real stock levels into the tree.
        if (is_array($combos)) {
            foreach ($combos as $c) {
                self::tree_add_combo($tree, (string) ($c['room'] ?? ''), (string) ($c['rack'] ?? ''), (string) ($c['shelf'] ?? ''));
            }
        }
        $tree = self::normalize_location_tree($tree);

        // Persist merged site tree so rooms/racks/shelves are reusable across warehouses.
        self::save_site_location_tree($site_id, $tree);

        // Build hierarchical maps from the full site tree.
        // Note: even if the warehouse has no linked shelves yet, we still return the full structure so it can be selected.

        $racks_by_room = array();
        $shelves_by_room_rack = array();
        foreach (($tree['rooms'] ?? array()) as $room_name => $room_data) {
            $racks_by_room[$room_name] = array_keys($room_data['racks'] ?? array());
            $shelves_by_room_rack[$room_name] = array();
            foreach (($room_data['racks'] ?? array()) as $rack_name => $rack_data) {
                $shelves_by_room_rack[$room_name][$rack_name] = $rack_data['shelves'] ?? array();
            }
        }

        // Flat lists are derived from the tree so they include even never-used rooms/racks/shelves.
        $lists = self::site_tree_to_lists($tree);

        return array(
            'rooms' => $lists['rooms'] ?? array(),
            'racks' => $lists['racks'] ?? array(),
            'shelves' => $lists['shelves'] ?? array(),
            'racks_by_room' => $racks_by_room,
            'shelves_by_room_rack' => $shelves_by_room_rack,
            'tree' => $tree,
        );
    }

    public static function sum_qty_for_item($product_id, $variation_id) {
        global $wpdb;
        $table = self::table_levels();
        $pid = (int) $product_id;
        $vid = (int) $variation_id;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM {$table} WHERE product_id=%d AND variation_id=%d",
            $pid,
            $vid
        ));
    }

    public static function commit_order_accept($order_id, $user_id, $allocations) {
        global $wpdb;
        $levels = self::table_levels();
        $moves = self::table_moves();

        $wpdb->query('START TRANSACTION');
        foreach ($allocations as $a) {
            $site_id = (int) $a['site_id'];
            $warehouse_id = (int) $a['warehouse_id'];
            $product_id = (int) $a['product_id'];
            $variation_id = (int) $a['variation_id'];
            $use_qty = (int) $a['use_qty'];
            $room = self::sanitize_loc($a['room']);
            $rack = self::sanitize_loc($a['rack']);
            $shelf = self::sanitize_loc($a['shelf']);

            if (!self::warehouse_allows_location($warehouse_id, $room, $rack, $shelf)) {
                $wpdb->query('ROLLBACK');
                return array('ok' => false, 'message' => __('Location is not linked to this warehouse. Link the shelves in WooCommerce → Warehouse.', 'mg-warehouse-stock'));
            }

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$levels}
                 SET qty = qty - %d, updated_at_gmt = %s
                 WHERE site_id = %d AND warehouse_id = %d AND product_id = %d AND variation_id = %d
                   AND room = %s AND rack = %s AND shelf = %s
                   AND qty >= %d",
                $use_qty,
                gmdate('Y-m-d H:i:s'),
                $site_id,
                $warehouse_id,
                $product_id,
                $variation_id,
                $room,
                $rack,
                $shelf,
                $use_qty
            ));

            if ((int) $updated !== 1) {
                $wpdb->query('ROLLBACK');
                return array('ok' => false, 'message' => __('Stock changed or is no longer sufficient, reload and try again', 'mg-warehouse-stock'));
            }

            $inserted = $wpdb->insert($moves, array(
                'ts_gmt' => gmdate('Y-m-d H:i:s'),
                'user_id' => (int) $user_id,
                'type' => 'out',
                'site_id' => $site_id,
                'warehouse_id' => $warehouse_id,
                'warehouse_from' => $warehouse_id,
                'warehouse_to' => 0,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'qty' => $use_qty,
                'room' => $room,
                'rack' => $rack,
                'shelf' => $shelf,
                'ref_order_id' => (int) $order_id,
                'note' => null,
            ));
            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return array('ok' => false, 'message' => __('Could not save the movement', 'mg-warehouse-stock'));
            }
        }
        $wpdb->query('COMMIT');
        return array('ok' => true, 'message' => 'OK');
    }
}
