<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product kits: a named bundle of component products with quantities.
 *
 * A kit is a definition. Stock deduction on sale is NOT automatic: the caller
 * (POS checkout or the WooCommerce order acceptance screen) decides when and
 * from which physical site the components are taken. The physical site is the
 * unit of deduction; the "warehouse" is only a virtual grouping (e.g. T-shirts,
 * Shoes) inside that site, and room/rack/shelf locate the piece.
 *
 * Methods here expose:
 *   - find_for_product(): resolve a kit from a sold product/variation
 *   - expand_components(): component needs for N kit units
 *   - site_availability(): per physical site, how many kits can be built
 *   - deduct_from_site(): take component stock from one physical site
 *   - restore_to_site(): give component stock back to one physical site
 */
class MGWS_Kits {
    public static function list($active = null, $limit = 100, $offset = 0) {
        global $wpdb;
        $table = MGWS_DB::table_kits();
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        $where = array('1=1');
        $params = array();
        if ($active !== null) {
            $where[] = 'active = %d';
            $params[] = (int) $active ? 1 : 0;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $all = array_merge(array($table), $params, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $all), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        foreach ($rows as &$row) {
            $row['components'] = self::components((int) $row['id']);
        }
        return $rows;
    }

    public static function get($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_kits();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['components'] = self::components($id);
        return $row;
    }

    public static function components($kit_id) {
        global $wpdb;
        $table = MGWS_DB::table_kit_components();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE kit_id = %d ORDER BY id ASC', $table, (int) $kit_id), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function create($name, $product_id, $variation_id, $sku, $note, $created_by_user_id) {
        if (trim((string) $name) === '') {
            return new WP_Error('mgws_bad_request', 'name is required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_kits();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'name' => (string) $name,
                'product_id' => (int) $product_id,
                'variation_id' => (int) $variation_id,
                'sku' => sanitize_text_field((string) $sku),
                'active' => 1,
                'note' => $note !== null && $note !== '' ? (string) $note : null,
                'created_by_user_id' => (int) $created_by_user_id,
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save kit', array('status' => 500));
        }
        return self::get((int) $wpdb->insert_id);
    }

    public static function add_component($kit_id, $product_id, $variation_id, $qty) {
        $kit = self::get((int) $kit_id);
        if (!$kit) {
            return new WP_Error('mgws_not_found', 'Kit not found', array('status' => 404));
        }
        $product_id = (int) $product_id;
        $qty = (int) $qty;
        if ($product_id <= 0 || $qty <= 0) {
            return new WP_Error('mgws_bad_request', 'product_id and qty are required', array('status' => 400));
        }
        global $wpdb;
        $table = MGWS_DB::table_kit_components();
        $inserted = $wpdb->insert(
            $table,
            array(
                'kit_id' => (int) $kit_id,
                'product_id' => $product_id,
                'variation_id' => (int) $variation_id,
                'qty' => $qty,
            ),
            array('%d', '%d', '%d', '%d')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save component', array('status' => 500));
        }
        return self::get((int) $kit_id);
    }

    public static function remove_component($component_id) {
        $component_id = (int) $component_id;
        global $wpdb;
        $table = MGWS_DB::table_kit_components();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $component_id), ARRAY_A);
        if (!is_array($row)) {
            return new WP_Error('mgws_not_found', 'Component not found', array('status' => 404));
        }
        $kit_id = (int) $row['kit_id'];
        $wpdb->delete($table, array('id' => $component_id), array('%d'));
        return self::get($kit_id);
    }

    public static function delete($id) {
        $id = (int) $id;
        $kit = self::get($id);
        if (!$kit) {
            return new WP_Error('mgws_not_found', 'Kit not found', array('status' => 404));
        }
        global $wpdb;
        $comp_table = MGWS_DB::table_kit_components();
        $wpdb->delete($comp_table, array('kit_id' => $id), array('%d'));
        $table = MGWS_DB::table_kits();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        return true;
    }

    /**
     * Resolve the kit defined for a product/variation, if any.
     *
     * @return array|null
     */
    public static function find_for_product($product_id, $variation_id = 0) {
        global $wpdb;
        $table = MGWS_DB::table_kits();
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE product_id = %d AND variation_id = %d AND active = 1 LIMIT 1',
            $table,
            $product_id,
            $variation_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['components'] = self::components((int) $row['id']);
        return $row;
    }

    /**
     * Component needs for $kit_qty units of a kit product. Returns an array of
     * ['product_id', 'variation_id', 'qty'] where qty is the total component
     * quantity required (component_qty * kit_qty). Empty when the product is
     * not a kit or has no components.
     *
     * @return array<int,array<string,int>>
     */
    public static function expand_components($product_id, $variation_id, $kit_qty) {
        $kit = self::find_for_product((int) $product_id, (int) $variation_id);
        if (!is_array($kit) || empty($kit['components'])) {
            return array();
        }
        $kit_qty = max(1, (int) $kit_qty);
        $out = array();
        foreach ($kit['components'] as $comp) {
            $out[] = array(
                'product_id' => (int) ($comp['product_id'] ?? 0),
                'variation_id' => (int) ($comp['variation_id'] ?? 0),
                'qty' => (int) ($comp['qty'] ?? 0) * $kit_qty,
            );
        }
        return $out;
    }

    /**
     * Per physical site: how many kit units can be built from the available
     * component stock. The calculation is done per site, summing the component
     * quantities across every virtual warehouse of that site; the kit count for
     * a site is the minimum over its components of floor(available / needed).
     *
     * Sites with zero availability are still returned, flagged as unavailable,
     * so the caller can show them as "non disponible in questa sede".
     *
     * @return array<int,array<string,mixed>>
     */
    public static function site_availability($product_id, $variation_id, $kit_qty = 1) {
        $kit = self::find_for_product((int) $product_id, (int) $variation_id);
        if (!is_array($kit) || empty($kit['components'])) {
            return array();
        }
        $kit_qty = max(1, (int) $kit_qty);

        // Collect component product/variation keys.
        $components = array();
        foreach ($kit['components'] as $comp) {
            $components[] = array(
                'product_id' => (int) ($comp['product_id'] ?? 0),
                'variation_id' => (int) ($comp['variation_id'] ?? 0),
                'qty' => (int) ($comp['qty'] ?? 0),
            );
        }

        // Group levels by physical site for every component.
        $sites = array();
        foreach ($components as $comp) {
            $levels = MGWS_DB::get_levels_for_item($comp['product_id'], $comp['variation_id'], 0);
            foreach (is_array($levels) ? $levels : array() as $level) {
                $sid = (int) ($level['site_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($sites[$sid])) {
                    $sites[$sid] = array();
                }
                if (!isset($sites[$sid][$comp['product_id'] . ':' . $comp['variation_id']])) {
                    $sites[$sid][$comp['product_id'] . ':' . $comp['variation_id']] = 0;
                }
                $sites[$sid][$comp['product_id'] . ':' . $comp['variation_id']] += (int) ($level['qty'] ?? 0);
            }
        }

        // Sites that exist but have no levels at all still need to be shown.
        $site_ids = array_keys($sites);
        // Also include sites with warehouses but no levels.
        global $wpdb;
        $site_rows = get_posts(array('post_type' => 'mg_site', 'posts_per_page' => -1, 'fields' => 'ids'));
        foreach (is_array($site_rows) ? $site_rows : array() as $sid) {
            $sid = (int) $sid;
            if ($sid > 0 && !isset($sites[$sid])) {
                $sites[$sid] = array();
            }
        }

        $result = array();
        foreach ($sites as $sid => $comp_totals) {
            $available_kits = PHP_INT_MAX;
            $component_detail = array();
            foreach ($components as $comp) {
                $key = $comp['product_id'] . ':' . $comp['variation_id'];
                $total = (int) ($comp_totals[$key] ?? 0);
                $needed = $comp['qty'] * $kit_qty;
                $can_build = $comp['qty'] > 0 ? (int) floor($total / $comp['qty']) : 0;
                if ($can_build < $available_kits) {
                    $available_kits = $can_build;
                }
                $component_detail[] = array(
                    'product_id' => $comp['product_id'],
                    'variation_id' => $comp['variation_id'],
                    'available_qty' => $total,
                    'needed_per_kit' => $comp['qty'],
                    'can_build' => $can_build,
                );
            }
            if ($available_kits === PHP_INT_MAX) {
                $available_kits = 0;
            }
            $result[] = array(
                'site_id' => (int) $sid,
                'site_name' => function_exists('get_the_title') ? get_the_title($sid) : ('Site ' . $sid),
                'available_kits' => $available_kits,
                'available' => $available_kits >= $kit_qty,
                'components' => $component_detail,
            );
        }

        // Sort by site id.
        usort($result, function ($a, $b) {
            return (int) $a['site_id'] <=> (int) $b['site_id'];
        });
        return $result;
    }

    /**
     * Take component stock for $kit_qty units of a kit from a single physical
     * site. Allocates across the virtual warehouses of that site (which hold
     * the components), preferring real shelves before the Unassigned sink.
     *
     * @return array{ok:bool,allocations?:array,message?:string,shortfall?:int}
     */
    /**
     * Build the component allocations for $kit_qty units of a kit from a single
     * physical site, without writing anything. Returns an array of allocation
     * rows (site_id, warehouse_id, product_id, variation_id, room, rack, shelf,
     * use_qty) plus the shortfall. Used by deduct_from_site and by the POS
     * checkout, which writes the movements inside its own transaction.
     *
     * @return array{allocations:array,shortfall:int}
     */
    public static function build_component_allocations($site_id, $product_id, $variation_id, $kit_qty) {
        $site_id = (int) $site_id;
        $kit_qty = max(1, (int) $kit_qty);
        $needs = self::expand_components((int) $product_id, (int) $variation_id, $kit_qty);
        $allocations = array();
        $shortfall = 0;
        if (empty($needs)) {
            return array('allocations' => $allocations, 'shortfall' => $shortfall);
        }

        foreach ($needs as $need) {
            $comp_pid = (int) $need['product_id'];
            $comp_vid = (int) $need['variation_id'];
            $comp_qty = (int) $need['qty'];
            if ($comp_pid <= 0 || $comp_qty <= 0) {
                continue;
            }

            $levels = MGWS_DB::get_levels_for_item($comp_pid, $comp_vid, $site_id);
            $usable = array();
            foreach (is_array($levels) ? $levels : array() as $level) {
                if ((int) ($level['qty'] ?? 0) <= 0) {
                    continue;
                }
                $usable[] = $level;
            }
            if (empty($usable)) {
                $shortfall += $comp_qty;
                continue;
            }

            usort($usable, function ($a, $b) {
                $a_system = (class_exists('MGWS_Stock_Reconciler') && MGWS_Stock_Reconciler::is_system_warehouse((int) ($a['warehouse_id'] ?? 0))) ? 1 : 0;
                $b_system = (class_exists('MGWS_Stock_Reconciler') && MGWS_Stock_Reconciler::is_system_warehouse((int) ($b['warehouse_id'] ?? 0))) ? 1 : 0;
                if ($a_system !== $b_system) {
                    return $a_system <=> $b_system;
                }
                return (int) ($a['warehouse_id'] ?? 0) <=> (int) ($b['warehouse_id'] ?? 0);
            });

            $remaining = $comp_qty;
            foreach ($usable as $level) {
                if ($remaining <= 0) {
                    break;
                }
                $available = (int) ($level['qty'] ?? 0);
                $use = min($remaining, $available);
                if ($use <= 0) {
                    continue;
                }
                $allocations[] = array(
                    'site_id' => $site_id,
                    'warehouse_id' => (int) ($level['warehouse_id'] ?? 0),
                    'product_id' => $comp_pid,
                    'variation_id' => $comp_vid,
                    'room' => (string) ($level['room'] ?? ''),
                    'rack' => (string) ($level['rack'] ?? ''),
                    'shelf' => (string) ($level['shelf'] ?? ''),
                    'use_qty' => $use,
                    'priority' => 0,
                );
                $remaining -= $use;
            }
            $shortfall += $remaining;
        }

        return array('allocations' => $allocations, 'shortfall' => $shortfall);
    }

    public static function deduct_from_site($site_id, $product_id, $variation_id, $kit_qty, $ref_order_id = 0, $user_id = 0, $note = '') {
        $site_id = (int) $site_id;
        $kit_qty = max(1, (int) $kit_qty);
        $built = self::build_component_allocations($site_id, (int) $product_id, (int) $variation_id, $kit_qty);
        $allocations = $built['allocations'];
        $shortfall = (int) $built['shortfall'];

        if (empty($allocations)) {
            return array('ok' => false, 'message' => 'No component stock available in this site', 'shortfall' => $shortfall, 'allocations' => array());
        }

        $result = MGWS_DB::commit_order_accept((int) $ref_order_id, (int) $user_id, $allocations);
        if (empty($result['ok'])) {
            return array('ok' => false, 'message' => (string) ($result['message'] ?? 'Could not deduct component stock'), 'shortfall' => $shortfall, 'allocations' => $allocations);
        }
        $woo_errors = self::commit_components_to_woo($allocations, -1);
        return array('ok' => true, 'allocations' => $allocations, 'shortfall' => $shortfall, 'woo_errors' => $woo_errors);
    }

    /**
     * Report component movements to WooCommerce.
     *
     * WooCommerce reduces the kit product it sold, never its components: the
     * component totals move only when MGWS sends the delta. Without it the
     * nightly reconciliation sees Woo above the ledger and puts the pieces
     * back into the Unassigned warehouse, undoing the deduction.
     *
     * @param array<int,array<string,mixed>> $allocations Rows written to the ledger.
     * @param int $sign -1 for a deduction, +1 for a restore.
     * @return string[] One message per product whose total could not be moved.
     */
    public static function commit_components_to_woo(array $allocations, $sign) {
        $sign = (int) $sign < 0 ? -1 : 1;
        $deltas = array();
        foreach ($allocations as $a) {
            $qty = (int) ($a['use_qty'] ?? 0);
            $pid = (int) ($a['product_id'] ?? 0);
            if ($qty <= 0 || $pid <= 0) {
                continue;
            }
            $key = $pid . ':' . (int) ($a['variation_id'] ?? 0);
            $deltas[$key] = ($deltas[$key] ?? 0) + $sign * $qty;
        }
        $errors = array();
        foreach ($deltas as $key => $delta) {
            list($pid, $vid) = array_map('intval', explode(':', $key));
            $result = MGWS_Woo_Stock::commit_delta($pid, $vid, (int) $delta);
            if (empty($result['ok'])) {
                $errors[] = sprintf('#%d/%d: %s', $pid, $vid, (string) ($result['message'] ?? 'error'));
            }
        }
        return $errors;
    }

    /**
     * Pieces of one item the ledger took for an order and has not given back
     * yet (out minus in on that ref_order_id). A restore can never exceed it,
     * otherwise it would create stock that was never taken.
     */
    public static function net_taken_for_order($order_id, $product_id, $variation_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }
        $net = 0;
        foreach (MGWS_DB::get_moves_for_order($order_id) as $move) {
            if ((int) ($move['product_id'] ?? 0) !== (int) $product_id || (int) ($move['variation_id'] ?? 0) !== (int) $variation_id) {
                continue;
            }
            $type = (string) ($move['type'] ?? '');
            if ($type === 'out') {
                $net += (int) ($move['qty'] ?? 0);
            } elseif ($type === 'in') {
                $net -= (int) ($move['qty'] ?? 0);
            }
        }
        return max(0, $net);
    }

    /**
     * Give component stock back to a single physical site. The caller passes the
     * same kit product/variation and quantity that were originally deducted; the
     * method re-adds the component stock to the levels of that site.
     *
     * @return array{ok:bool,message?:string}
     */
    public static function restore_to_site($site_id, $product_id, $variation_id, $kit_qty, $ref_order_id = 0, $user_id = 0) {
        $site_id = (int) $site_id;
        $kit_qty = max(1, (int) $kit_qty);
        $needs = self::expand_components((int) $product_id, (int) $variation_id, $kit_qty);
        if (empty($needs)) {
            return array('ok' => true);
        }
        // A restore gives back what a deduction took: without the order that
        // took it there is nothing to measure it against.
        $ref_order_id = (int) $ref_order_id;
        if ($ref_order_id <= 0) {
            return array('ok' => false, 'message' => 'ref_order_id is required to restore kit components');
        }

        // Rebuild one allocation per component, picking the first level of that
        // component in the site (or creating a synthetic Unassigned row).
        $allocations = array();
        foreach ($needs as $need) {
            $comp_pid = (int) $need['product_id'];
            $comp_vid = (int) $need['variation_id'];
            $comp_qty = min((int) $need['qty'], self::net_taken_for_order($ref_order_id, $comp_pid, $comp_vid));
            if ($comp_pid <= 0 || $comp_qty <= 0) {
                continue;
            }
            $levels = MGWS_DB::get_levels_for_item($comp_pid, $comp_vid, $site_id);
            $level = null;
            foreach (is_array($levels) ? $levels : array() as $l) {
                $level = $l;
                break;
            }
            if (is_array($level)) {
                $allocations[] = array(
                    'site_id' => $site_id,
                    'warehouse_id' => (int) ($level['warehouse_id'] ?? 0),
                    'product_id' => $comp_pid,
                    'variation_id' => $comp_vid,
                    'room' => (string) ($level['room'] ?? ''),
                    'rack' => (string) ($level['rack'] ?? ''),
                    'shelf' => (string) ($level['shelf'] ?? ''),
                    'use_qty' => $comp_qty,
                );
            } else {
                // No level exists: use the first warehouse of the site.
                $wh_ids = get_posts(array('post_type' => 'mg_warehouse', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array(array('key' => 'mg_site_id', 'value' => $site_id, 'compare' => '='))));
                $wh_id = is_array($wh_ids) && !empty($wh_ids) ? (int) $wh_ids[0] : 0;
                if ($wh_id <= 0) {
                    continue;
                }
                $allocations[] = array(
                    'site_id' => $site_id,
                    'warehouse_id' => $wh_id,
                    'product_id' => $comp_pid,
                    'variation_id' => $comp_vid,
                    'room' => '',
                    'rack' => '',
                    'shelf' => '',
                    'use_qty' => $comp_qty,
                );
            }
        }

        if (empty($allocations)) {
            return array('ok' => true);
        }
        $result = MGWS_DB::commit_order_release((int) $ref_order_id, (int) $user_id, $allocations);
        if (empty($result['ok'])) {
            return array('ok' => false, 'message' => (string) ($result['message'] ?? 'Could not restore component stock'));
        }
        return array('ok' => true, 'allocations' => $allocations, 'woo_errors' => self::commit_components_to_woo($allocations, 1));
    }
}