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
 * Data preservation is the default. Deleting the plugin does NOT touch the
 * database unless the administrator explicitly opted in through the
 * `mgws_uninstall_on_delete` option (exposed in the POS settings). A user who
 * just wants to disable the plugin, or who deletes it by mistake, keeps every
 * level, movement, receipt, purchase order and count. The fiscal documents
 * (POS receipts, receipt lines, shift closings) are never removed, even when
 * the opt-in is on: they are the shop's fiscal record.
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
     * Removes the plugin's tables, options, capabilities, custom post types,
     * user meta and rewrite rules.
     *
     * @return void
     */
    function mgws_uninstall_all(): void {
        global $wpdb;

        // Data preservation is the default. Nothing is dropped unless the
        // administrator opted in before deleting the plugin.
        if (!get_option('mgws_uninstall_on_delete', false)) {
            // Still tidy the schedule and the capabilities the plugin may have
            // left on roles, so a deactivated-then-deleted plugin does not
            // leave ghost capabilities behind. Tables and user data stay.
            wp_clear_scheduled_hook('mgws_reconcile_stock');
            wp_clear_scheduled_hook('mgws_run_count_schedules');
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
                'mgws_costs_read',
                'mgws_costs_manage',
                'mgws_roles_read',
                'mgws_roles_manage',
                'mgws_lots_manage',
                'mgws_transfers_manage',
                'mgws_price_lists_manage',
                'mgws_approval_rules_manage',
                'mgws_returns_manage',
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
            delete_option('mgws_caps_version');
            return;
        }

        // All non-fiscal tables. The fiscal ones (pos_receipts,
        // pos_receipt_lines, pos_closings, mg_documents) are deliberately kept:
        // they are the shop's fiscal record and survive the plugin.
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
            MGWS_DB::table_stock_lots(),
            MGWS_DB::table_stock_lots_levels(),
            MGWS_DB::table_stock_transfers(),
            MGWS_DB::table_stock_transfer_lines(),
            MGWS_DB::table_price_lists(),
            MGWS_DB::table_price_list_items(),
            MGWS_DB::table_approval_rules(),
            MGWS_DB::table_returns(),
            MGWS_DB::table_return_lines(),
            MGWS_DB::table_product_costs(),
            MGWS_DB::table_permission_sets(),
            MGWS_DB::table_supplier_price_history(),
            MGWS_DB::table_count_schedules(),
            MGWS_DB::table_shipments(),
            MGWS_DB::table_reservations(),
            MGWS_DB::table_payments(),
            MGWS_DB::table_kits(),
            MGWS_DB::table_kit_components(),
            MGWS_DB::table_product_barcodes(),
        );

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall drops the plugin's own tables.
        }

        foreach (array('mgws_caps_version', 'mgws_db_schema_version', 'mgws_pos_turno_obbligatorio', 'mgws_shared_dashboards', 'mgws_print_templates', 'mgws_print_paper_formats', 'mgws_print_categories', 'mgws_print_defaults_loaded', 'mgws_permission_sets_seeded', 'mgws_uninstall_on_delete') as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook('mgws_reconcile_stock');
        wp_clear_scheduled_hook('mgws_run_count_schedules');

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
            'mgws_costs_read',
            'mgws_costs_manage',
            'mgws_roles_read',
            'mgws_roles_manage',
            'mgws_lots_manage',
            'mgws_transfers_manage',
            'mgws_price_lists_manage',
            'mgws_approval_rules_manage',
            'mgws_returns_manage',
        );

        $roles = wp_roles();
        if ($roles instanceof WP_Roles) {
            foreach (array_keys($roles->roles) as $roleName) {
                $role = get_role((string) $roleName);
                if (!$role instanceof WP_Role) {
                    continue;
                }
                // Remove the mgws_* roles this plugin created, not only the
                // capabilities it scattered on foreign roles.
                if (strpos((string) $roleName, 'mgws_') === 0) {
                    remove_role((string) $roleName);
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

        flush_rewrite_rules();
    }
}
