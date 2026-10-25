<?php

/**
 * Uninstall routine for MG Warehouse Stock.
 *
 * WordPress runs this file when the plugin is deleted. It is also included by
 * directory scanners, so the WP_UNINSTALL_PLUGIN guard is the first executable
 * statement and nothing below it may run without that constant.
 *
 * Nothing here may depend on WooCommerce. The most common reason a user deletes
 * this plugin is that it is the thing they are uninstalling, and a cleanup that
 * fatals because a dependency is already inactive leaves the store's database
 * littered with tables it can no longer reach.
 *
 * @package MG_Warehouse_Stock
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('MGWS_PLUGIN_DIR')) {
    define('MGWS_PLUGIN_DIR', __DIR__);
}

require_once MGWS_PLUGIN_DIR . '/includes/mgws-db.php';

if (!function_exists('mgws_uninstall_all')) {
    /**
     * Removes every trace of the plugin: tables, options, capabilities,
     * custom post types, user meta and rewrite rules.
     *
     * The order matters. Warehouses reference sites, so they are deleted first.
     * Capabilities are removed from the roles that hold them, so a capability
     * cannot outlive the feature that introduced it.
     *
     * @return void
     */
    function mgws_uninstall_all(): void {
        global $wpdb;

        $tables = array(
            MGWS_DB::table_levels(),
            MGWS_DB::table_moves(),
            MGWS_DB::table_movements(),
            MGWS_DB::table_pos_shifts(),
            MGWS_DB::table_pos_idempotency(),
            MGWS_DB::table_loyalty_cards(),
            MGWS_DB::table_loyalty_movements(),
            MGWS_DB::table_fornitori(),
            MGWS_DB::table_employees(),
            MGWS_DB::table_reorder_rules(),
            MGWS_DB::table_purchase_orders(),
            MGWS_DB::table_purchase_order_lines(),
            MGWS_DB::table_receipts(),
            MGWS_DB::table_receipt_lines(),
            MGWS_DB::table_backorders(),
            MGWS_DB::table_inventory_count_sessions(),
            MGWS_DB::table_inventory_count_lines(),
            MGWS_DB::table_stock_reason_codes(),
        );

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall drops the plugin's own tables; there is nothing to cache.
        }

        foreach (array('mgws_caps_version', 'mgws_db_schema_version', 'mgws_pos_turno_obbligatorio', 'mgws_shared_dashboards', 'mgws_print_templates', 'mgws_print_paper_formats', 'mgws_print_categories', 'mgws_print_defaults_loaded') as $option) {
            delete_option($option);
        }

        $capabilities = array(
            'mgws_stock_read',
            'mgws_stock_move',
            'mgws_purchase_approve',
            'mgws_supplier_manage',
            'mgws_order_accept',
            'mgws_manage_credentials',
            'mgws_manage_user_permissions',
            'mgws_manage_employees',
            'mgws_reports_read',
        );

        $roles = wp_roles();
        if ($roles instanceof WP_Roles) {
            foreach (array_keys($roles->roles) as $roleName) {
                $role = get_role((string) $roleName);
                if (!$role instanceof WP_Role) {
                    continue;
                }
                foreach ($capabilities as $capability) {
                    $role->remove_cap($capability);
                }
            }
        }

        // Warehouses first: a warehouse belongs to a site, not the other way round.
        foreach (array('mg_warehouse', 'mg_site') as $postType) {
            $postIds = get_posts(array(
                'post_type' => $postType,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
            ));
            foreach ((array) $postIds as $postId) {
                wp_delete_post((int) $postId, true);
            }
        }

        delete_metadata('user', 0, 'mg_default_site_id', '', true);
        delete_metadata('user', 0, '_mgws_dashboards', '', true);

        // The wc-mg-accepted order status is registered at runtime through
        // register_post_status and wc_order_statuses, never persisted, so
        // there is no stored row to delete and no WooCommerce call to make.
        // Flushing the rules is what removes the post type routes.

        flush_rewrite_rules();
    }
}
