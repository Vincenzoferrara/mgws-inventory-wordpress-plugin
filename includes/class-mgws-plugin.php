<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/mgws-db.php';
require_once __DIR__ . '/class-mgws-rest-api.php';
require_once __DIR__ . '/class-mgws-reports.php';
require_once __DIR__ . '/class-mgws-print.php';

class MGWS_Plugin {
    private static $instance = null;
    private const CAPS_VERSION_OPTION = 'mgws_caps_version';
    private const DB_SCHEMA_VERSION_OPTION = 'mgws_db_schema_version';
    private const DB_SCHEMA_VERSION = '10';

    /**
     * When enabled, MGWS is the single source of truth for stock and
     * WooCommerce is not allowed to decrement it a second time.
     *
     * Off by default. The filter it controls is order-wide, so refusing it
     * disables stock reduction for every item in the order and for every other
     * plugin on the site. A store that wants MGWS authoritative turns this on.
     */
    public const OPTION_WOOCOMMERCE_STOCK_AUTHORITY = 'mgws_woocommerce_stock_authority';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        MGWS_DB::create_or_update_tables();
        update_option(self::DB_SCHEMA_VERSION_OPTION, self::DB_SCHEMA_VERSION);
        self::ensure_role_capabilities();
        update_option(self::CAPS_VERSION_OPTION, MGWS_PLUGIN_VERSION);
    }

    private function __construct() {
        $this->maybe_upgrade_database();
        $this->maybe_upgrade_capabilities();

        // REST API (minimal; permissions via WP capabilities).
        MGWS_REST_API::instance();

        add_action('init', array($this, 'register_user_meta'));
        add_action('init', array($this, 'register_cpts'));
        add_action('init', array($this, 'register_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_order_status_to_list'));

        // Avoid Woo double stock reduction, but only for stores that made MGWS
        // their stock authority. See filter_can_reduce_order_stock().
        add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'filter_can_reduce_order_stock'), 10, 2);

        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('admin_notices', array($this, 'notice_woocommerce_missing'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_menu', array($this, 'register_admin_pages'));

        add_action('wp_ajax_mgws_get_accept_tree', array($this, 'ajax_get_accept_tree'));
        add_action('wp_ajax_mgws_commit_accept', array($this, 'ajax_commit_accept'));

        add_action('wp_ajax_mgws_get_product_stock', array($this, 'ajax_get_product_stock'));
        add_action('wp_ajax_mgws_apply_inventory_op', array($this, 'ajax_apply_inventory_op'));
        add_action('wp_ajax_mgws_get_warehouses_for_site', array($this, 'ajax_get_warehouses_for_site'));
        add_action('wp_ajax_mgws_get_location_suggestions', array($this, 'ajax_get_location_suggestions'));
        add_action('wp_ajax_mgws_create_site', array($this, 'ajax_create_site'));
        add_action('wp_ajax_mgws_create_warehouse', array($this, 'ajax_create_warehouse'));
        add_action('wp_ajax_mgws_add_location_value', array($this, 'ajax_add_location_value'));
        add_action('wp_ajax_mgws_delete_location_value', array($this, 'ajax_delete_location_value'));

        add_action('wp_ajax_mgws_link_warehouse_location', array($this, 'ajax_link_warehouse_location'));
        add_action('wp_ajax_mgws_unlink_warehouse_location', array($this, 'ajax_unlink_warehouse_location'));

        add_action('wp_ajax_mgws_admin_get_tree', array($this, 'ajax_admin_get_tree'));

        add_action('wp_ajax_mgws_delete_site', array($this, 'ajax_delete_site'));
        add_action('wp_ajax_mgws_delete_warehouse', array($this, 'ajax_delete_warehouse'));

        // Product UI: separate Woo product data tab.
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));

        add_action('show_user_profile', array($this, 'render_user_site_field'));
        add_action('edit_user_profile', array($this, 'render_user_site_field'));
        add_action('personal_options_update', array($this, 'save_user_site_field'));
        add_action('edit_user_profile_update', array($this, 'save_user_site_field'));

        add_action('save_post_product', array($this, 'save_product_default_location'), 10, 2);

        // No custom top-level GUI: integrate into existing Woo screens.
    }

    private function maybe_upgrade_capabilities() {
        $installed = (string) get_option(self::CAPS_VERSION_OPTION, '');
        // A capability added without a version bump still reaches the roles.
        $admin = get_role('administrator');
        $missing_caps = $admin && !$admin->has_cap('mgws_reports_read');
        if ($installed === (string) MGWS_PLUGIN_VERSION && !$missing_caps) {
            return;
        }
        self::ensure_role_capabilities();
        update_option(self::CAPS_VERSION_OPTION, MGWS_PLUGIN_VERSION);
    }

    private function maybe_upgrade_database() {
        $installed = (string) get_option(self::DB_SCHEMA_VERSION_OPTION, '');
        if ($installed === self::DB_SCHEMA_VERSION) {
            return;
        }
        MGWS_DB::create_or_update_tables();
        update_option(self::DB_SCHEMA_VERSION_OPTION, self::DB_SCHEMA_VERSION);
    }

    /**
     * Decides whether WooCommerce may decrement stock for an order.
     *
     * WooCommerce asks this once per order, not once per item, so a `false`
     * here stops stock reduction for the whole order. That is what MGWS needs
     * when it owns the stock ledger, and what it must not impose on a store
     * that has not asked for it.
     *
     * @param bool       $reduce Whether WooCommerce wants to reduce stock.
     * @param mixed|null $order  The order, unused: the decision is store-wide.
     * @return bool
     */
    public static function filter_can_reduce_order_stock(bool $reduce, mixed $order = null): bool {
        if ('1' === (string) get_option(self::OPTION_WOOCOMMERCE_STOCK_AUTHORITY, '0')) {
            return false;
        }
        return $reduce;
    }

    /**
     * Tells the administrator that the plugin cannot do anything useful without
     * WooCommerce. An admin notice rather than a wp_die: the point is to explain,
     * not to lock anyone out of their own dashboard.
     *
     * @return void
     */
    public function notice_woocommerce_missing(): void {
        if (class_exists('WooCommerce')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'MG Warehouse Stock needs WooCommerce to be installed and active. Warehouse levels, purchase orders and the point of sale stay unavailable until it is.',
            'mg-warehouse-stock'
        );
        echo '</p></div>';
    }

    private static function custom_caps() {
        return array(
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
    }

    private static function operator_caps() {
        return array(
            'mgws_stock_read',
            'mgws_stock_move',
            'mgws_purchase_approve',
            'mgws_supplier_manage',
            'mgws_order_accept',
            'mgws_manage_employees',
            'mgws_reports_read',
        );
    }

    private static function admin_only_caps() {
        return array(
            'mgws_manage_credentials',
            'mgws_manage_user_permissions',
        );
    }

    private static function ensure_role_capabilities() {
        $caps = self::custom_caps();
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($caps as $cap) {
                $admin->add_cap($cap);
            }
        }

        $shop_manager = get_role('shop_manager');
        if ($shop_manager) {
            foreach (self::operator_caps() as $cap) {
                $shop_manager->add_cap($cap);
            }
            foreach (self::admin_only_caps() as $cap) {
                $shop_manager->remove_cap($cap);
            }
        }
    }

    public function register_admin_pages() {
        // Under WooCommerce menu.
        add_submenu_page(
            'woocommerce',
            __('Warehouse', 'mg-warehouse-stock'),
            __('Warehouse', 'mg-warehouse-stock'),
            'manage_woocommerce',
            'mgws-masterdata',
            array($this, 'render_masterdata_page')
        );
    }

    public function render_masterdata_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do that', 'mg-warehouse-stock'));
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Warehouse', 'mg-warehouse-stock') . '</h1>';
        echo '<p><small class="mgws-help">' . esc_html__('Manage the location tree: Site → Warehouse → Room → Rack → Shelf.', 'mg-warehouse-stock') . '</small></p>';
        echo '<div id="mgws-master" data-site-limit="' . esc_attr((int) $site_limit) . '">';
        wp_nonce_field('mgws_admin_nonce', 'mgws_admin_nonce');
        echo '<div class="mgws-toolbar">';
        echo '<button type="button" class="button button-primary" id="mgws-master-reload">' . esc_html__('Reload', 'mg-warehouse-stock') . '</button> ';
        if ($site_limit <= 0) {
            echo '<button type="button" class="button" id="mgws-master-add-site">' . esc_html__('+ Site', 'mg-warehouse-stock') . '</button> ';
        }
        echo '<input type="text" id="mgws-master-filter" placeholder="' . esc_attr__('Search (site, warehouse, room...)', 'mg-warehouse-stock') . '" style="min-width:320px; max-width:100%;" /> ';
        echo '<button type="button" class="button" id="mgws-master-expand">' . esc_html__('Expand', 'mg-warehouse-stock') . '</button> ';
        echo '<button type="button" class="button" id="mgws-master-collapse">' . esc_html__('Collapse', 'mg-warehouse-stock') . '</button> ';
        echo '<span id="mgws-master-msg" style="margin-left:8px;"></span>';
        echo '</div>';
        echo '<div id="mgws-master-tree" style="margin-top:12px;"></div>';
        echo '</div>';
        echo '</div>';
    }

    private function can_manage_master_data() {
        // Master data management is now intended to happen inside Woo product UI.
        // Allow Woo managers (shop_manager/admin) to use + buttons.
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function add_product_data_tab($tabs) {
        if (!current_user_can('edit_products')) {
            return $tabs;
        }

        // Hide MGWS tab for virtual products (server-side).
        global $post;
        $product_id = $post ? (int) $post->ID : 0;
        $p = ($product_id > 0 && function_exists('wc_get_product')) ? wc_get_product($product_id) : null;
        if ($p && $p->is_virtual()) {
            return $tabs;
        }

        $tabs['mgws_stock'] = array(
            'label' => __('Warehouse', 'mg-warehouse-stock'),
            'target' => 'mgws_stock_data',
            'class' => array(),
            'priority' => 75,
        );
        return $tabs;
    }

    public function render_product_data_panel() {
        if (!current_user_can('edit_products')) {
            return;
        }
        global $post;
        $product_id = $post ? (int) $post->ID : 0;
        $product = ($product_id > 0 && function_exists('wc_get_product')) ? wc_get_product($product_id) : null;
        $is_virtual = $product ? (bool) $product->is_virtual() : false;

        if ($is_virtual) {
            // Do not render the panel at all for virtual products.
            return;
        }
        echo '<div id="mgws_stock_data" class="panel woocommerce_options_panel">';
        $this->render_product_stock_section();
        echo '</div>';
    }

    public function register_cpts() {
        register_post_type('mg_site', array(
            'labels' => array(
                'name' => _x('Sites', 'post type plural name', 'mg-warehouse-stock'),
                'singular_name' => _x('Site', 'post type singular name', 'mg-warehouse-stock'),
            ),
            'public' => false,
            // Keep internal but remove standalone UI.
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title'),
            'menu_icon' => 'dashicons-location',
        ));

        register_post_type('mg_warehouse', array(
            'labels' => array(
                'name' => _x('Warehouses', 'post type plural name', 'mg-warehouse-stock'),
                'singular_name' => _x('Warehouse', 'post type singular name', 'mg-warehouse-stock'),
            ),
            'public' => false,
            // Keep internal but remove standalone UI.
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title'),
            'menu_icon' => 'dashicons-store',
        ));
    }

    public function register_user_meta() {
        register_meta('user', 'mg_default_site_id', array(
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function ($allowed, $meta_key, $user_id) {
                $current_user_id = (int) get_current_user_id();
                $target_user_id = (int) $user_id;

                if ($current_user_id <= 0 || $target_user_id <= 0) {
                    return false;
                }

                if (current_user_can('manage_options')) {
                    return true;
                }

                if ($current_user_id === $target_user_id) {
                    return true;
                }

                if (current_user_can('edit_user', $target_user_id)) {
                    return true;
                }

                return false;
            },
        ));
    }

    private function get_sites_for_user($site_limit) {
        $site_limit = (int) $site_limit;
        if ($site_limit > 0) {
            return get_posts(array(
                'post_type' => 'mg_site',
                'post__in' => array($site_limit),
                'posts_per_page' => 1,
            ));
        }
        return get_posts(array(
            'post_type' => 'mg_site',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
    }

    private function render_inline_select_with_add($id, $name, $options, $selected, $can_add, $add_action) {
        echo '<div class="mgws-inline">';
        echo '<select class="mgws-select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
        echo '<option value="0">' . esc_html__('-- select --', 'mg-warehouse-stock') . '</option>';
        foreach ($options as $opt) {
            $opt_id = (string) ($opt['id'] ?? '');
            $opt_name = (string) ($opt['name'] ?? '');
            if ($opt_id === '') {
                continue;
            }
            echo '<option value="' . esc_attr($opt_id) . '"' . selected((string) $selected, $opt_id, false) . '>' . esc_html($opt_name) . '</option>';
        }
        echo '</select>';
        if ($can_add) {
            echo '<button type="button" class="button mgws-add" data-action="' . esc_attr($add_action) . '" data-target="' . esc_attr($id) . '">+</button>';
        }
        echo '</div>';
    }

    private function render_location_select_with_add($id, $name, $values, $selected, $warehouse_select_id, $field, $can_add = true) {
        $values = is_array($values) ? $values : array();
        echo '<div class="mgws-inline">';
        echo '<select class="mgws-select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" data-field="' . esc_attr($field) . '">';
        echo '<option value="">--</option>';
        foreach ($values as $v) {
            $v = (string) $v;
            if ($v === '') {
                continue;
            }
            echo '<option value="' . esc_attr($v) . '"' . selected((string) $selected, $v, false) . '>' . esc_html($v) . '</option>';
        }
        echo '</select>';
        if ($can_add) {
            echo '<button type="button" class="button mgws-add" data-action="add_location" data-field="' . esc_attr($field) . '" data-warehouse-select="' . esc_attr($warehouse_select_id) . '" data-target="' . esc_attr($id) . '">+</button>';
        }
        echo '</div>';
    }

    public function register_order_status() {
        register_post_status('wc-mg-accepted', array(
            'label' => _x('Accepted', 'order status', 'mg-warehouse-stock'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => /* translators: %s: number of orders with the Accepted status. */ _n_noop('Accepted <span class="count">(%s)</span>', 'Accepted <span class="count">(%s)</span>', 'mg-warehouse-stock'),
        ));
    }

    public function add_order_status_to_list($order_statuses) {
        $new_statuses = array();
        foreach ($order_statuses as $key => $label) {
            $new_statuses[$key] = $label;
            if ($key === 'wc-processing') {
                $new_statuses['wc-mg-accepted'] = _x('Accepted', 'order status', 'mg-warehouse-stock');
            }
        }
        if (!isset($new_statuses['wc-mg-accepted'])) {
            $new_statuses['wc-mg-accepted'] = _x('Accepted', 'order status', 'mg-warehouse-stock');
        }
        return $new_statuses;
    }

    public function add_order_metabox() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $screen = 'shop_order';
        if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)
            && function_exists('wc_get_container')
            && function_exists('wc_get_page_screen_id')) {
            try {
                $controller = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class);
                if (method_exists($controller, 'custom_orders_table_usage_is_enabled')
                    && $controller->custom_orders_table_usage_is_enabled()) {
                    $screen = wc_get_page_screen_id('shop-order');
                }
            } catch (\Throwable $error) {
                $screen = 'shop_order';
            }
        }

        add_meta_box(
            'mgws_accept_box',
            __('Picking / Acceptance', 'mg-warehouse-stock'),
            array($this, 'render_order_metabox'),
            $screen,
            'side',
            'high'
        );
    }

    public function render_order_metabox($post_or_order) {
        if (!current_user_can('edit_shop_orders')) {
            echo '<p>' . esc_html__('You do not have permission to do that.', 'mg-warehouse-stock') . '</p>';
            return;
        }

        if ($post_or_order instanceof WP_Post) {
            $order_id = (int) $post_or_order->ID;
        } elseif (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            $order_id = (int) $post_or_order->get_id();
        } else {
            // Read-only: the order id of the HPOS edit screen being rendered.
            $order_id = isset($_GET['id']) ? absint((string) wp_unslash($_GET['id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($order_id <= 0) {
            echo '<p>' . esc_html__('The order could not be loaded.', 'mg-warehouse-stock') . '</p>';
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($order instanceof WC_Order) {
            $committed = (int) $order->get_meta('_mgws_accept_committed', true);
        } else {
            $committed = (int) get_post_meta($order_id, '_mgws_accept_committed', true);
        }

        wp_nonce_field('mgws_accept_nonce', 'mgws_accept_nonce');

        echo '<div id="mgws-accept" data-order-id="' . esc_attr($order_id) . '">';
        if ($committed === 1) {
            echo '<p><strong>' . esc_html__('Order already accepted.', 'mg-warehouse-stock') . '</strong></p>';
        }
        echo '<p><button type="button" class="button button-primary" id="mgws-load-tree">' . esc_html__('Load availability', 'mg-warehouse-stock') . '</button></p>';
        echo '<div id="mgws-tree" style="max-height: 360px; overflow: auto;"></div>';
        echo '<div id="mgws-totals" style="margin-top: 8px;"></div>';
        echo '<p style="margin-top: 10px;"><button type="button" class="button button-primary" id="mgws-commit" disabled>' . esc_html__('Accept order', 'mg-warehouse-stock') . '</button></p>';
        echo '<div id="mgws-msg" style="margin-top: 8px;"></div>';
        echo '</div>';
    }

    private function render_product_stock_section() {
        if (!current_user_can('edit_products')) {
            return;
        }

        global $post;
        if (!$post || (string) $post->post_type !== 'product') {
            return;
        }

        $product_id = (int) $post->ID;
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            echo '<p>' . esc_html__('Invalid product.', 'mg-warehouse-stock') . '</p>';
            return;
        }

        $variation_id = 0;
        $variations = array();
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            if (!empty($variations)) {
                $variation_id = (int) $variations[0];
            }
        }

        $default_site_id = (int) get_post_meta($product_id, 'mgws_default_site_id', true);
        $default_warehouse_id = (int) get_post_meta($product_id, 'mgws_default_warehouse_id', true);
        $default_room = (string) get_post_meta($product_id, 'mgws_default_room', true);
        $default_rack = (string) get_post_meta($product_id, 'mgws_default_rack', true);
        $default_shelf = (string) get_post_meta($product_id, 'mgws_default_shelf', true);

        if ($site_limit > 0) {
            $default_site_id = $site_limit;
        }

        wp_nonce_field('mgws_product_nonce', 'mgws_product_nonce');
        wp_nonce_field('mgws_product_save_defaults', 'mgws_product_save_defaults');

        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__('Default location', 'mg-warehouse-stock') . '</strong></p>';
        echo '<p class="form-field"><small class="mgws-help">' . esc_html__('Order: Site → Warehouse → Room → Rack → Shelf. To add or remove locations, go to WooCommerce → Warehouse.', 'mg-warehouse-stock') . '</small></p>';
        $is_virtual = $product ? (bool) $product->is_virtual() : false;
        $user_id = (int) get_current_user_id();
        echo '<div id="mgws-product" data-product-id="' . esc_attr($product_id) . '" data-site-limit="' . esc_attr($site_limit) . '" data-default-site-id="' . esc_attr($default_site_id) . '" data-is-virtual="' . esc_attr($is_virtual ? 1 : 0) . '" data-user-id="' . esc_attr($user_id) . '">';

        if ($default_site_id <= 0 || $default_warehouse_id <= 0) {
            echo '<p class="form-field"><span style="color:#b32d2e; font-weight:600;">' . esc_html__('Default location not set.', 'mg-warehouse-stock') . '</span></p>';
        }

        // No per-variation stock ops in product UI.
        echo '<input type="hidden" id="mgws-variation" value="0" />';

        // Default location selectors (saved on product update) - all in one row.
        $sites = $this->get_sites_for_user($site_limit);
        $site_opts = array();
        foreach ($sites as $s) {
            $site_opts[] = array('id' => (int) $s->ID, 'name' => (string) $s->post_title);
        }

        // Warehouses dropdown (filtered by selected site).
        $warehouses = $this->get_warehouses_for_site_limit($default_site_id);
        $wh_opts = array();
        foreach ($warehouses as $w) {
            $wh_opts[] = array('id' => (int) $w->ID, 'name' => (string) $w->post_title);
        }

        // room/rack/shelf as selects with +.
        $suggest = $default_warehouse_id > 0
            ? MGWS_DB::get_location_suggestions_for_warehouse($default_warehouse_id)
            : array('rooms' => array(), 'racks' => array(), 'shelves' => array());

        echo '<div class="mgws-loc-card">';
        echo '<div class="mgws-loc-row">';
        echo '<div class="mgws-field mgws-loc-field"><div class="mgws-field-label">' . esc_html__('Site', 'mg-warehouse-stock') . '</div>';
        if ($site_limit > 0) {
            $site_title = !empty($sites) ? $sites[0]->post_title : (__('Site #', 'mg-warehouse-stock') . ' ' . $site_limit);
            // Use an enabled select with a single option so selectWoo/select2 renders it.
            echo '<select class="mgws-select" id="mgws-site" name="mgws_default_site_id">'
                . '<option value="' . esc_attr($site_limit) . '" selected>' . esc_html($site_title) . '</option>'
                . '</select>';
        } else {
            $this->render_inline_select_with_add('mgws-site', 'mgws_default_site_id', $site_opts, $default_site_id, false, 'create_site');
        }
        echo '</div>';

        echo '<div class="mgws-field mgws-loc-field"><div class="mgws-field-label">' . esc_html__('Warehouse', 'mg-warehouse-stock') . '</div>';
        $this->render_inline_select_with_add('mgws-product-warehouse', 'mgws_default_warehouse_id', $wh_opts, $default_warehouse_id, false, 'create_warehouse');
        echo '</div>';

        echo '<div class="mgws-field mgws-loc-field"><div class="mgws-field-label">' . esc_html__('Room', 'mg-warehouse-stock') . '</div>';
        $this->render_location_select_with_add('mgws-default-room', 'mgws_default_room', $suggest['rooms'], $default_room, 'mgws-product-warehouse', 'room', false);
        echo '</div>';

        echo '<div class="mgws-field mgws-loc-field"><div class="mgws-field-label">' . esc_html__('Rack', 'mg-warehouse-stock') . '</div>';
        $this->render_location_select_with_add('mgws-default-rack', 'mgws_default_rack', $suggest['racks'], $default_rack, 'mgws-product-warehouse', 'rack', false);
        echo '</div>';

        echo '<div class="mgws-field mgws-loc-field"><div class="mgws-field-label">' . esc_html__('Shelf', 'mg-warehouse-stock') . '</div>';
        $this->render_location_select_with_add('mgws-default-shelf', 'mgws_default_shelf', $suggest['shelves'], $default_shelf, 'mgws-product-warehouse', 'shelf', false);
        echo '</div>';

        $master_url = admin_url('admin.php?page=mgws-masterdata');
        echo '</div>';

        echo '<div class="mgws-inline">';
        echo '<a class="button" href="' . esc_url($master_url) . '">' . esc_html__('Manage sites, warehouses and locations', 'mg-warehouse-stock') . '</a>';
        echo '</div>';
        echo '</div>';

        $purchase_cost = (string) get_post_meta($product_id, '_purchase_cost', true);
        echo '<div class="options_group">';
        echo '<p class="form-field"><label for="_purchase_cost">' . esc_html__('Purchase cost', 'mg-warehouse-stock') . '</label><input type="text" class="short wc_input_price" name="_purchase_cost" id="_purchase_cost" value="' . esc_attr($purchase_cost) . '"> <span class="description">' . esc_html__('WooCommerce `_purchase_cost` meta, also updated by supplier receipts.', 'mg-warehouse-stock') . '</span></p>';
        echo '</div>';

        echo '<div id="mgws-product-msg"></div>';

        echo '</div>';
        echo '</div>';

        // Per-variation default locations (sezione separata).
        if ($product->is_type('variable') && !empty($variations)) {
            echo '<div class="options_group">';
            echo '<p class="form-field"><strong>' . esc_html__('Default location per variation', 'mg-warehouse-stock') . '</strong></p>';
            echo '<p class="form-field"><small>' . esc_html__('Set Site / Warehouse / Room / Rack / Shelf for each variation. All fields are optional.', 'mg-warehouse-stock') . '</small></p>';

            echo '<p class="form-field">';
            echo '<span class="mgws-inline">';
            echo '<input type="text" id="mgws-var-filter" placeholder="' . esc_attr__('Filter variations...', 'mg-warehouse-stock') . '" />';
            echo '<button type="button" class="button" id="mgws-var-fill-empty">' . esc_html__('Copy default to empty', 'mg-warehouse-stock') . '</button>';
            echo '<button type="button" class="button" id="mgws-var-reset-cols">' . esc_html__('Reset columns', 'mg-warehouse-stock') . '</button>';
            echo '<small style="opacity:.85;">' . esc_html__('Save the product to confirm.', 'mg-warehouse-stock') . '</small>';
            echo '</span>';
            echo '</p>';

            echo '<div class="mgws-variation-defaults" style="max-height: 340px; overflow:auto; border:1px solid #dcdcde; padding:6px; background:#fff;">';
            echo '<table id="mgws-var-table" class="widefat striped mgws-var-table" style="border-collapse:collapse;">';
            echo '<colgroup>';
            echo '<col><col><col><col><col><col><col><col><col>';
            echo '</colgroup>';
            echo '<thead>';
            echo '<tr>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Name', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">SKU</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Purchase cost', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Variation', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Site', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Warehouse', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Room', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Rack', 'mg-warehouse-stock') . '</th>';
            echo '<th style="text-align:left; padding:4px; border-bottom:1px solid #dcdcde;">' . esc_html__('Shelf', 'mg-warehouse-stock') . '</th>';
            echo '</tr>';
            echo '</thead><tbody>';

            $sites_for_user = $this->get_sites_for_user($site_limit);

            foreach ($variations as $vid) {
                $vid = (int) $vid;
                $vp = wc_get_product($vid);
                $base_name = $product ? (string) $product->get_name() : __('Product', 'mg-warehouse-stock');
                $sku = ($vp && method_exists($vp, 'get_sku')) ? (string) $vp->get_sku() : '';
                $attr_lines = array();
                if ($vp && method_exists($vp, 'get_variation_attributes')) {
                    $vattrs = (array) $vp->get_variation_attributes();
                    foreach ($vattrs as $k => $v) {
                        $v = (string) $v;
                        if ($v === '') {
                            continue;
                        }
                        // $k is like "attribute_pa_color" or "attribute_size".
                        $tax = (string) preg_replace('/^attribute_/', '', (string) $k);
                        if (taxonomy_exists($tax)) {
                            $term = get_term_by('slug', $v, $tax);
                            $attr_lines[] = $term && !is_wp_error($term) ? (string) $term->name : $v;
                        } else {
                            // Custom product attribute stored as raw string.
                            $attr_lines[] = $v;
                        }
                    }
                }

                $v_site_id = (int) get_post_meta($vid, 'mgws_default_site_id', true);
                $v_wh_id = (int) get_post_meta($vid, 'mgws_default_warehouse_id', true);
                $v_room = (string) get_post_meta($vid, 'mgws_default_room', true);
                $v_rack = (string) get_post_meta($vid, 'mgws_default_rack', true);
                $v_shelf = (string) get_post_meta($vid, 'mgws_default_shelf', true);
                $v_purchase_cost = (string) get_post_meta($vid, '_purchase_cost', true);

                if ($site_limit > 0) {
                    $v_site_id = $site_limit;
                }

                $v_wh_options = $v_site_id > 0 ? $this->get_warehouses_for_site_limit($v_site_id) : array();

                echo '<tr class="mgws-var-row" data-variation-id="' . esc_attr($vid) . '">';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">' . esc_html($base_name) . '</td>';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">' . esc_html($sku) . '</td>';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;"><input type="text" class="short wc_input_price" name="mgws_var_defaults[' . esc_attr($vid) . '][purchase_cost]" value="' . esc_attr($v_purchase_cost) . '" style="width:90px"></td>';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">';
                if (!empty($attr_lines)) {
                    $first = true;
                    foreach ($attr_lines as $aline) {
                        if (!$first) {
                            echo '<br>';
                        }
                        $first = false;
                        echo '<small>' . esc_html((string) $aline) . '</small>';
                    }
                } else {
                    echo '<small>-</small>';
                }
                echo '</td>';

                // Site
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">';
                if ($site_limit > 0) {
                    $site_title = !empty($sites_for_user) ? $sites_for_user[0]->post_title : (__('Site #', 'mg-warehouse-stock') . ' ' . $site_limit);
                    echo '<small>' . esc_html($site_title) . '</small>';
                    echo '<input type="hidden" class="mgws-var-site" name="mgws_var_defaults[' . esc_attr($vid) . '][site_id]" value="' . esc_attr($site_limit) . '">';
                } else {
                    echo '<div class="mgws-inline">';
                    echo '<select class="mgws-select mgws-var-site" name="mgws_var_defaults[' . esc_attr($vid) . '][site_id]">';
                    echo '<option value="0">--</option>';
                    foreach ($sites_for_user as $s) {
                        echo '<option value="' . esc_attr((int) $s->ID) . '"' . selected((int) $s->ID, (int) $v_site_id, false) . '>' . esc_html($s->post_title) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                echo '</td>';

                // Warehouse
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">';
                echo '<div class="mgws-inline">';
                echo '<select class="mgws-select mgws-var-warehouse" name="mgws_var_defaults[' . esc_attr($vid) . '][warehouse_id]">';
                echo '<option value="0">--</option>';
                foreach ($v_wh_options as $w) {
                    echo '<option value="' . esc_attr((int) $w->ID) . '"' . selected((int) $w->ID, (int) $v_wh_id, false) . '>' . esc_html($w->post_title) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '</td>';

                // Room/rack/shelf as selects (populated via JS based on warehouse).
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">'
                    . '<div class="mgws-inline">'
                    . '<select class="mgws-select mgws-var-room" name="mgws_var_defaults[' . esc_attr($vid) . '][room]" data-current="' . esc_attr($v_room) . '"></select>'
                    . '</div>'
                    . '</td>';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">'
                    . '<div class="mgws-inline">'
                    . '<select class="mgws-select mgws-var-rack" name="mgws_var_defaults[' . esc_attr($vid) . '][rack]" data-current="' . esc_attr($v_rack) . '"></select>'
                    . '</div>'
                    . '</td>';
                echo '<td style="padding:4px; border-top:1px solid #f0f0f1;">'
                    . '<div class="mgws-inline">'
                    . '<select class="mgws-select mgws-var-shelf" name="mgws_var_defaults[' . esc_attr($vid) . '][shelf]" data-current="' . esc_attr($v_shelf) . '"></select>'
                    . '</div>'
                    . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
            echo '</div>';
        }

        // (Levels/moves removed from product UI)
    }

    public function enqueue_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $is_master_screen = ($screen->id === 'woocommerce_page_mgws-masterdata');
        if ($is_master_screen) {
            if (function_exists('WC') && function_exists('wc_enqueue_js')) {
                wp_enqueue_script('selectWoo');
                wp_enqueue_script('wc-enhanced-select');
                wp_enqueue_style('woocommerce_admin_styles');
                wc_enqueue_js("jQuery( document.body ).trigger( 'wc-enhanced-select-init' );");
            }
            wp_enqueue_style(
                'mgws-admin-master',
                plugins_url('assets/admin-masterdata.css', MGWS_PLUGIN_FILE),
                array(),
                MGWS_PLUGIN_VERSION
            );
            wp_enqueue_script(
                'mgws-admin-master',
                plugins_url('assets/admin-masterdata.js', MGWS_PLUGIN_FILE),
                // wp-i18n is declared rather than left to wp_set_script_translations,
                // which adds it behind the scenes: the script calls wp.i18n, so the
                // dependency belongs where the other dependencies are.
                array('jquery', 'wp-i18n'),
                MGWS_PLUGIN_VERSION,
                true
            );
            wp_localize_script('mgws-admin-master', 'MGWS_MASTER', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ));
            wp_set_script_translations('mgws-admin-master', 'mg-warehouse-stock', MGWS_PLUGIN_DIR . '/languages');
            return;
        }

        // Order metabox assets.
        $hpos_order_screen_id = function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id('shop-order')
            : 'woocommerce_page_wc-orders';
        $is_order_screen = ($screen->id === 'shop_order')
            || ($screen->id === $hpos_order_screen_id)
            || (($screen->post_type ?? '') === 'shop_order')
            || (filter_input(INPUT_GET, 'page') === 'wc-orders' && filter_input(INPUT_GET, 'action') === 'edit');
        if ($is_order_screen) {
            wp_enqueue_style(
                'mgws-admin-order',
                plugins_url('assets/admin-order.css', MGWS_PLUGIN_FILE),
                array(),
                MGWS_PLUGIN_VERSION
            );
            wp_enqueue_script(
                'mgws-admin-order',
                plugins_url('assets/admin-order.js', MGWS_PLUGIN_FILE),
                array('jquery', 'wp-i18n'),
                MGWS_PLUGIN_VERSION,
                true
            );
            wp_localize_script('mgws-admin-order', 'MGWS', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ));
            wp_set_script_translations('mgws-admin-order', 'mg-warehouse-stock', MGWS_PLUGIN_DIR . '/languages');
            return;
        }

        // Product data (Attributes tab) assets.
        $is_product_screen = ($screen->id === 'product') || (($screen->post_type ?? '') === 'product');
        if ($is_product_screen) {
            if (function_exists('WC') && function_exists('wc_enqueue_js')) {
                wp_enqueue_script('selectWoo');
                wp_enqueue_script('wc-enhanced-select');
                wp_enqueue_style('woocommerce_admin_styles');
                wc_enqueue_js("jQuery( document.body ).trigger( 'wc-enhanced-select-init' );");
            }
            wp_enqueue_style(
                'mgws-admin-product',
                plugins_url('assets/admin-product.css', MGWS_PLUGIN_FILE),
                array(),
                MGWS_PLUGIN_VERSION
            );
            wp_enqueue_script(
                'mgws-admin-product',
                plugins_url('assets/admin-product.js', MGWS_PLUGIN_FILE),
                array('jquery', 'wp-i18n'),
                MGWS_PLUGIN_VERSION,
                true
            );
            wp_localize_script('mgws-admin-product', 'MGWS_PRODUCT', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ));
            wp_set_script_translations('mgws-admin-product', 'mg-warehouse-stock', MGWS_PLUGIN_DIR . '/languages');
            return;
        }

        // No other custom screens.
    }

    private function get_warehouses_for_site_limit($site_limit) {
        $site_limit = (int) $site_limit;
        if ($site_limit > 0) {
            return get_posts(array(
                'post_type' => 'mg_warehouse',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'meta_query' => array(
                    array(
                        'key' => 'mg_site_id',
                        'value' => $site_limit,
                        'compare' => '=',
                    ),
                ),
            ));
        }
        return get_posts(array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
    }

    public function save_product_default_location($post_id, $post) {
        if (!isset($_POST['mgws_product_save_defaults']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mgws_product_save_defaults'])), 'mgws_product_save_defaults')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $site_id = isset($_POST['mgws_default_site_id']) ? (int) $_POST['mgws_default_site_id'] : 0;
        $warehouse_id = isset($_POST['mgws_default_warehouse_id']) ? (int) $_POST['mgws_default_warehouse_id'] : 0;
        $room = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['mgws_default_room'] ?? '')));
        $rack = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['mgws_default_rack'] ?? '')));
        $shelf = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['mgws_default_shelf'] ?? '')));

        // Enforce hierarchy: room -> rack -> shelf.
        if ($room === '') {
            $rack = '';
            $shelf = '';
        } elseif ($rack === '') {
            $shelf = '';
        }

        if ($site_limit > 0) {
            $site_id = $site_limit;
        }

        if ($warehouse_id > 0) {
            $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
            if ($wh_site <= 0) {
                $warehouse_id = 0;
            } elseif ($site_id > 0 && (int) $wh_site !== (int) $site_id) {
                // Warehouse not in selected site.
                $warehouse_id = 0;
            } else {
                $site_id = (int) $wh_site;
            }
        }

        update_post_meta($post_id, 'mgws_default_site_id', (int) $site_id);
        update_post_meta($post_id, 'mgws_default_warehouse_id', (int) $warehouse_id);
        update_post_meta($post_id, 'mgws_default_room', $room);
        update_post_meta($post_id, 'mgws_default_rack', $rack);
        update_post_meta($post_id, 'mgws_default_shelf', $shelf);
        if (isset($_POST['_purchase_cost'])) {
            update_post_meta($post_id, '_purchase_cost', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_purchase_cost'])), 4));
        }

        // Per-variation defaults.
        if (isset($_POST['mgws_var_defaults']) && is_array($_POST['mgws_var_defaults']) && function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            if ($product && $product->is_type('variable')) {
                $allowed_vars = array_map('intval', $product->get_children());
                $allowed_map = array();
                foreach ($allowed_vars as $v) {
                    $allowed_map[$v] = true;
                }

                // Each value is cast or passed through sanitize_loc() below.
                foreach (wp_unslash($_POST['mgws_var_defaults']) as $vid_raw => $vals) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $vid = (int) $vid_raw;
                    if ($vid <= 0 || !isset($allowed_map[$vid])) {
                        continue;
                    }
                    if (!is_array($vals)) {
                        continue;
                    }

                    $v_site_id = isset($vals['site_id']) ? (int) $vals['site_id'] : 0;
                    $v_wh_id = isset($vals['warehouse_id']) ? (int) $vals['warehouse_id'] : 0;
                    $v_room = MGWS_DB::sanitize_loc((string) ($vals['room'] ?? ''));
                    $v_rack = MGWS_DB::sanitize_loc((string) ($vals['rack'] ?? ''));
                    $v_shelf = MGWS_DB::sanitize_loc((string) ($vals['shelf'] ?? ''));

                    // Enforce hierarchy: room -> rack -> shelf.
                    if ($v_room === '') {
                        $v_rack = '';
                        $v_shelf = '';
                    } elseif ($v_rack === '') {
                        $v_shelf = '';
                    }

                    if ($site_limit > 0) {
                        $v_site_id = $site_limit;
                    }

                    if ($v_wh_id > 0) {
                        $wh_site = MGWS_DB::get_site_id_for_warehouse($v_wh_id);
                        if ($wh_site <= 0) {
                            $v_wh_id = 0;
                        } elseif ($v_site_id > 0 && (int) $wh_site !== (int) $v_site_id) {
                            $v_wh_id = 0;
                        } else {
                            $v_site_id = (int) $wh_site;
                        }
                    }

                    update_post_meta($vid, 'mgws_default_site_id', (int) $v_site_id);
                    update_post_meta($vid, 'mgws_default_warehouse_id', (int) $v_wh_id);
                    update_post_meta($vid, 'mgws_default_room', $v_room);
                    update_post_meta($vid, 'mgws_default_rack', $v_rack);
                    update_post_meta($vid, 'mgws_default_shelf', $v_shelf);
                    if (isset($vals['purchase_cost'])) {
                        update_post_meta($vid, '_purchase_cost', wc_format_decimal((string) $vals['purchase_cost'], 4));
                    }
                }
            }
        }
    }

    private function resolve_item_ids_from_selection($product_or_variation_id) {
        $selected_id = (int) $product_or_variation_id;
        if ($selected_id <= 0 || !function_exists('wc_get_product')) {
            return array('ok' => false, 'message' => __('Invalid product', 'mg-warehouse-stock'));
        }
        $p = wc_get_product($selected_id);
        if (!$p) {
            return array('ok' => false, 'message' => __('Product not found', 'mg-warehouse-stock'));
        }
        if ($p->is_type('variation')) {
            $variation_id = $selected_id;
            $product_id = (int) $p->get_parent_id();
            return array('ok' => true, 'product_id' => $product_id, 'variation_id' => $variation_id);
        }
        return array('ok' => true, 'product_id' => $selected_id, 'variation_id' => 0);
    }

    private function get_user_site_limit($user_id) {
        $site_id = (int) get_user_meta($user_id, 'mg_default_site_id', true);
        return $site_id > 0 ? $site_id : 0;
    }

    private function can_read_stock_ajax() {
        return current_user_can('mgws_stock_read') || current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function can_move_stock_ajax() {
        return current_user_can('mgws_stock_move') || current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function can_accept_orders_ajax() {
        return current_user_can('mgws_order_accept') || current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function validate_item_for_stock_ops($product_id, $variation_id) {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;

        if ($product_id <= 0 || $variation_id < 0) {
            return new WP_Error('mgws_bad_request', __('Invalid product', 'mg-warehouse-stock'));
        }

        if (!function_exists('wc_get_product')) {
            return true;
        }

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                return new WP_Error('mgws_bad_request', __('Invalid variation', 'mg-warehouse-stock'));
            }
            if ((int) $variation->get_parent_id() !== $product_id) {
                return new WP_Error('mgws_bad_request', __('Variation does not belong to this product', 'mg-warehouse-stock'));
            }
            return true;
        }

        $product = wc_get_product($product_id);
        if (!$product || $product->is_type('variation')) {
            return new WP_Error('mgws_bad_request', __('Invalid product', 'mg-warehouse-stock'));
        }

        return true;
    }

    public function ajax_get_accept_tree() {
        if (!$this->can_accept_orders_ajax()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_accept_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : null;
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order', 'mg-warehouse-stock')), 404);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());

        $cards = array();
        foreach ($order->get_items('line_item') as $item) {
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $key = $product_id . ':' . $variation_id;
            if (!isset($cards[$key])) {
                $cards[$key] = array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'required_qty' => 0,
                );
            }
            $cards[$key]['required_qty'] += (int) $item->get_quantity();
        }

        if (empty($cards)) {
            wp_send_json_success(array('sites' => array(), 'totals' => array('required' => 0, 'available' => 0, 'allocated' => 0, 'remaining' => 0), 'user_site_limit' => $site_limit));
        }

        $levels = MGWS_DB::get_levels_for_items(array_values($cards), $site_limit);

        $site_ids = array();
        $warehouse_ids = array();
        foreach ($levels as $row) {
            $site_ids[(int) $row['site_id']] = true;
            $warehouse_ids[(int) $row['warehouse_id']] = true;
        }
        $site_names = $this->load_post_titles(array_keys($site_ids));
        $warehouse_names = $this->load_post_titles(array_keys($warehouse_ids));

        $available_total = array();
        $available_by_wh = array();
        foreach ($levels as $row) {
            $k = (int) $row['product_id'] . ':' . (int) $row['variation_id'];
            $available_total[$k] = ($available_total[$k] ?? 0) + (int) $row['qty'];
            $wh = (int) $row['warehouse_id'];
            $available_by_wh[$wh] = $available_by_wh[$wh] ?? array();
            $available_by_wh[$wh][$k] = ($available_by_wh[$wh][$k] ?? 0) + (int) $row['qty'];
        }

        $tree = array();
        foreach ($levels as $row) {
            $sid = (int) $row['site_id'];
            $wid = (int) $row['warehouse_id'];
            $card_key = (int) $row['product_id'] . ':' . (int) $row['variation_id'];

            if (!isset($tree[$sid])) {
                $tree[$sid] = array(
                    'site_id' => $sid,
                    'site_name' => $site_names[$sid] ?? (__('Site #', 'mg-warehouse-stock') . ' ' . $sid),
                    'warehouses' => array(),
                );
            }
            if (!isset($tree[$sid]['warehouses'][$wid])) {
                $tree[$sid]['warehouses'][$wid] = array(
                    'warehouse_id' => $wid,
                    'warehouse_name' => $warehouse_names[$wid] ?? (__('Warehouse #', 'mg-warehouse-stock') . ' ' . $wid),
                    'cards' => array(),
                );
            }
            if (!isset($tree[$sid]['warehouses'][$wid]['cards'][$card_key])) {
                $title = $this->format_card_title((int) $row['product_id'], (int) $row['variation_id']);
                $tree[$sid]['warehouses'][$wid]['cards'][$card_key] = array(
                    'card_key' => $card_key,
                    'product_id' => (int) $row['product_id'],
                    'variation_id' => (int) $row['variation_id'],
                    'title' => $title,
                    'required_qty' => $cards[$card_key]['required_qty'] ?? 0,
                    'available_total_qty' => $available_total[$card_key] ?? 0,
                    'available_in_warehouse_qty' => $available_by_wh[$wid][$card_key] ?? 0,
                    'locations' => array(),
                );
            }

            $label = MGWS_DB::format_location_label($row['room'], $row['rack'], $row['shelf']);
            $tree[$sid]['warehouses'][$wid]['cards'][$card_key]['locations'][] = array(
                'room' => (string) $row['room'],
                'rack' => (string) $row['rack'],
                'shelf' => (string) $row['shelf'],
                'qty' => (int) $row['qty'],
                'label' => $label,
            );
        }

        $sites_out = array();
        foreach ($tree as $site) {
            $warehouses_out = array();
            foreach ($site['warehouses'] as $wh) {
                $cards_out = array();
                foreach ($wh['cards'] as $c) {
                    usort($c['locations'], function ($a, $b) {
                        return $b['qty'] <=> $a['qty'];
                    });
                    $cards_out[] = $c;
                }
                $wh['cards'] = $cards_out;
                $warehouses_out[] = $wh;
            }
            $site['warehouses'] = $warehouses_out;
            $sites_out[] = $site;
        }

        $total_required = 0;
        $total_available = 0;
        foreach ($cards as $k => $c) {
            $total_required += (int) $c['required_qty'];
            $total_available += (int) ($available_total[$k] ?? 0);
        }

        $totals = array(
            'required' => $total_required,
            'available' => $total_available,
            'allocated' => 0,
            'remaining' => $total_required,
        );

        wp_send_json_success(array(
            'order_id' => $order_id,
            'user_site_limit' => $site_limit,
            'sites' => $sites_out,
            'totals' => $totals,
            'cards' => array_values($cards),
        ));
    }

    public function ajax_commit_accept() {
        if (!$this->can_accept_orders_ajax()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_accept_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : null;
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order', 'mg-warehouse-stock')), 404);
        }

        $committed = (int) get_post_meta($order_id, '_mgws_accept_committed', true);
        if ($committed === 1) {
            wp_send_json_error(array('message' => __('Order already accepted', 'mg-warehouse-stock')), 409);
        }

        // JSON document: sanitizing it as text would corrupt it. Every decoded field is validated below.
        $payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            wp_send_json_error(array('message' => __('Invalid payload', 'mg-warehouse-stock')), 400);
        }

        $allow_partial = !empty($data['allow_partial']);
        $cards_in = is_array($data['cards'] ?? null) ? $data['cards'] : array();

        $required_map = array();
        foreach ($order->get_items('line_item') as $item) {
            $pid = (int) $item->get_product_id();
            $vid = (int) $item->get_variation_id();
            $k = $pid . ':' . $vid;
            $required_map[$k] = ($required_map[$k] ?? 0) + (int) $item->get_quantity();
        }

        $allocations = array();
        $allocated_by_card = array();
        $touched_items = array();

        $site_limit = $this->get_user_site_limit(get_current_user_id());

        foreach ($cards_in as $card) {
            $pid = (int) ($card['product_id'] ?? 0);
            $vid = (int) ($card['variation_id'] ?? 0);
            $card_key = $pid . ':' . $vid;
            if ($pid <= 0) {
                continue;
            }
            $touched_items[$card_key] = array('product_id' => $pid, 'variation_id' => $vid);

            $allocs = is_array($card['allocations'] ?? null) ? $card['allocations'] : array();
            foreach ($allocs as $a) {
                $use_qty = (int) ($a['use_qty'] ?? 0);
                if ($use_qty <= 0) {
                    continue;
                }
                $site_id = (int) ($a['site_id'] ?? 0);
                $warehouse_id = (int) ($a['warehouse_id'] ?? 0);
                $room = MGWS_DB::sanitize_loc((string) ($a['room'] ?? ''));
                $rack = MGWS_DB::sanitize_loc((string) ($a['rack'] ?? ''));
                $shelf = MGWS_DB::sanitize_loc((string) ($a['shelf'] ?? ''));
                $priority = (int) ($a['priority'] ?? 0);

                if ($warehouse_id <= 0 || $site_id <= 0) {
                    continue;
                }
                if ($site_limit > 0 && $site_id !== $site_limit) {
                    wp_send_json_error(array('message' => __('Allocation is outside your assigned site', 'mg-warehouse-stock')), 403);
                }

                $allocations[] = array(
                    'card_key' => $card_key,
                    'site_id' => $site_id,
                    'warehouse_id' => $warehouse_id,
                    'product_id' => $pid,
                    'variation_id' => $vid,
                    'room' => $room,
                    'rack' => $rack,
                    'shelf' => $shelf,
                    'use_qty' => $use_qty,
                    'priority' => $priority,
                );
                $allocated_by_card[$card_key] = ($allocated_by_card[$card_key] ?? 0) + $use_qty;
            }
        }

        if (!$allow_partial) {
            foreach ($required_map as $k => $req) {
                $alloc = (int) ($allocated_by_card[$k] ?? 0);
                if ($alloc < (int) $req) {
                    wp_send_json_error(array('message' => __('Not enough stock for every order line', 'mg-warehouse-stock')), 400);
                }
            }
        }

        usort($allocations, function ($a, $b) {
            $pa = (int) $a['priority'];
            $pb = (int) $b['priority'];
            if ($pa === $pb) {
                return (int) $b['use_qty'] <=> (int) $a['use_qty'];
            }
            return $pa <=> $pb;
        });

        $user_id = get_current_user_id();
        $result = MGWS_DB::commit_order_accept($order_id, $user_id, $allocations);
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['message']), 409);
        }

        foreach ($touched_items as $it) {
            $this->sync_woo_stock((int) $it['product_id'], (int) $it['variation_id']);
        }

        $allocation_audit = array(
            'order_id' => $order_id,
            'committed_at_gmt' => gmdate('Y-m-d H:i:s'),
            'cards' => array(),
            'totals' => array('total_required' => 0, 'total_allocated' => 0, 'total_remaining' => 0),
        );
        foreach ($required_map as $k => $req) {
            $alloc = (int) ($allocated_by_card[$k] ?? 0);
            $remaining = max(0, (int) $req - $alloc);
            list($pid, $vid) = array_map('intval', explode(':', $k));
            $allocation_audit['cards'][] = array(
                'product_id' => $pid,
                'variation_id' => $vid,
                'required_qty' => (int) $req,
                'allocated_qty' => $alloc,
                'remaining_qty' => $remaining,
            );
            $allocation_audit['totals']['total_required'] += (int) $req;
            $allocation_audit['totals']['total_allocated'] += $alloc;
            $allocation_audit['totals']['total_remaining'] += $remaining;
        }

        update_post_meta($order_id, '_mgws_accept_committed', 1);
        update_post_meta($order_id, '_mgws_accept_committed_at_gmt', gmdate('Y-m-d H:i:s'));
        update_post_meta($order_id, '_mgws_accept_committed_by', $user_id);
        update_post_meta($order_id, '_mgws_allocation_v1', wp_json_encode($allocation_audit));

        $note = __('Order accepted.', 'mg-warehouse-stock');
        if ($allocation_audit['totals']['total_remaining'] > 0) {
            $note .= ' ' . sprintf(
                /* translators: %d: number of units that could not be allocated. */
                __('Warning: not enough stock for %d units.', 'mg-warehouse-stock'),
                (int) $allocation_audit['totals']['total_remaining']
            );
        }
        $order->add_order_note($note, false, true);
        $order->update_status('mg-accepted');

        wp_send_json_success(array('message' => __('Order accepted', 'mg-warehouse-stock'), 'totals' => $allocation_audit['totals']));
    }

    private function sync_woo_stock($product_id, $variation_id) {
        $sum = MGWS_DB::sum_qty_for_item($product_id, $variation_id);
        $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$product) {
            return;
        }
        $product->set_manage_stock(true);
        $product->set_stock_quantity($sum);
        $product->set_stock_status($sum > 0 ? 'instock' : 'outofstock');
        $product->save();
    }

    private function load_post_titles($ids) {
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $posts = get_posts(array(
            'post_type' => array('mg_site', 'mg_warehouse'),
            'post__in' => $ids,
            'posts_per_page' => -1,
            'orderby' => 'post__in',
        ));
        foreach ($posts as $p) {
            $out[(int) $p->ID] = $p->post_title;
        }
        return $out;
    }

    private function format_card_title($product_id, $variation_id) {
        if ($variation_id > 0) {
            $p = wc_get_product($variation_id);
            if ($p) {
                return $p->get_formatted_name();
            }
        }
        $p = wc_get_product($product_id);
        return $p ? $p->get_name() : (__('Product #', 'mg-warehouse-stock') . ' ' . $product_id);
    }

    public function ajax_get_product_stock() {
        if (!$this->can_read_stock_ajax()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_product_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        $item_ok = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($item_ok)) {
            wp_send_json_error(array('message' => $item_ok->get_error_message()), 400);
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());

        $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, $site_limit);
        $warehouse_ids = array();
        $site_ids = array();
        foreach ($levels as $lv) {
            $warehouse_ids[(int) $lv['warehouse_id']] = true;
            $site_ids[(int) $lv['site_id']] = true;
        }
        $warehouse_names = $this->load_post_titles(array_keys($warehouse_ids));
        $site_names = $this->load_post_titles(array_keys($site_ids));

        foreach ($levels as &$lv) {
            $wid = (int) $lv['warehouse_id'];
            $lv['warehouse_name'] = $warehouse_names[$wid] ?? ('#' . $wid);
            $sid = (int) $lv['site_id'];
            $lv['site_name'] = $site_names[$sid] ?? ('#' . $sid);
            $lv['location_label'] = MGWS_DB::format_location_label($lv['room'], $lv['rack'], $lv['shelf']);
        }

        $moves = MGWS_DB::get_moves(array(
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'site_id' => $site_limit > 0 ? $site_limit : 0,
        ), 50);

        wp_send_json_success(array('levels' => $levels, 'moves' => $moves));
    }

    public function ajax_get_warehouses_for_site() {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0) {
            $site_id = $site_limit;
        }
        if ($site_id <= 0) {
            wp_send_json_success(array('warehouses' => array()));
        }

        $warehouses = $this->get_warehouses_for_site_limit($site_id);
        $out = array();
        foreach ($warehouses as $w) {
            $out[] = array('id' => (int) $w->ID, 'name' => $w->post_title, 'site_id' => $site_id);
        }
        wp_send_json_success(array('warehouses' => $out));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_get_location_suggestions() {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($warehouse_id <= 0) {
            wp_send_json_success(array('rooms' => array(), 'racks' => array(), 'shelves' => array()));
        }
        $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        if ($site_limit > 0 && (int) $wh_site !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Warehouse is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $suggest = MGWS_DB::get_location_suggestions_for_warehouse($warehouse_id);
        wp_send_json_success($suggest);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_create_site() {
        if (!$this->can_manage_master_data()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0) {
            wp_send_json_error(array('message' => __('This user is limited to one site and cannot create new ones', 'mg-warehouse-stock')), 403);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(array('message' => __('Site name is missing', 'mg-warehouse-stock')), 400);
        }

        $id = wp_insert_post(array(
            'post_type' => 'mg_site',
            'post_title' => $name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ));
        if (is_wp_error($id) || (int) $id <= 0) {
            wp_send_json_error(array('message' => __('Could not create the site', 'mg-warehouse-stock')), 500);
        }

        wp_send_json_success(array('site' => array('id' => (int) $id, 'name' => $name)));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_create_warehouse() {
        if (!$this->can_manage_master_data()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($site_id <= 0 || $name === '') {
            wp_send_json_error(array('message' => __('Missing data', 'mg-warehouse-stock')), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0) {
            $site_id = (int) $site_limit;
        }

        $id = wp_insert_post(array(
            'post_type' => 'mg_warehouse',
            'post_title' => $name,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ));
        if (is_wp_error($id) || (int) $id <= 0) {
            wp_send_json_error(array('message' => __('Could not create the warehouse', 'mg-warehouse-stock')), 500);
        }
        update_post_meta((int) $id, 'mg_site_id', $site_id);

        wp_send_json_success(array('warehouse' => array('id' => (int) $id, 'name' => $name, 'site_id' => $site_id)));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_admin_get_tree() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_admin_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $sites = $this->get_sites_for_user($site_limit);
        $sites_out = array();
        foreach ($sites as $s) {
            $sid = (int) $s->ID;
            $warehouses = $this->get_warehouses_for_site_limit($sid);
            $wh_out = array();
            foreach ($warehouses as $w) {
                $wid = (int) $w->ID;
                $wh_tree = MGWS_DB::get_warehouse_location_tree($wid);
                $counts = array('rooms' => 0, 'racks' => 0, 'shelves' => 0);
                foreach (($wh_tree['rooms'] ?? array()) as $room_name => $room_data) {
                    $counts['rooms'] += 1;
                    foreach (($room_data['racks'] ?? array()) as $rack_name => $rack_data) {
                        $counts['racks'] += 1;
                        $counts['shelves'] += is_array($rack_data['shelves'] ?? null) ? count($rack_data['shelves']) : 0;
                    }
                }
                $wh_out[] = array(
                    'id' => $wid,
                    'name' => (string) $w->post_title,
                    'wh_tree' => $wh_tree,
                    'wh_counts' => $counts,
                );
            }

            // Location structure is shared across warehouses within the same site.
            $tree = MGWS_DB::get_site_location_tree($sid);
            // Ensure we also merge in any existing combos in levels (site-wide) and legacy warehouse trees.
            if (!empty($wh_out)) {
                MGWS_DB::get_location_suggestions_for_warehouse((int) $wh_out[0]['id']);
                $tree = MGWS_DB::get_site_location_tree($sid);
            }
            $counts = array('rooms' => 0);
            if (is_array($tree) && isset($tree['rooms']) && is_array($tree['rooms'])) {
                $counts['rooms'] = count($tree['rooms']);
            }

            $sites_out[] = array(
                'id' => $sid,
                'name' => (string) $s->post_title,
                'warehouses' => $wh_out,
                'tree' => $tree,
                'counts' => $counts,
            );
        }

        wp_send_json_success(array('sites' => $sites_out, 'site_limit' => $site_limit));
    }

    public function ajax_add_location_value() {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

        $parent_room = isset($_POST['parent_room']) ? sanitize_text_field(wp_unslash($_POST['parent_room'])) : '';
        $parent_rack = isset($_POST['parent_rack']) ? sanitize_text_field(wp_unslash($_POST['parent_rack'])) : '';

        if (($warehouse_id <= 0 && $site_id <= 0) || $value === '') {
            wp_send_json_error(array('message' => __('Missing data', 'mg-warehouse-stock')), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());

        // Resolve site_id either from payload or from warehouse.
        if ($site_id <= 0) {
            $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        }
        if ($site_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid site', 'mg-warehouse-stock')), 400);
        }
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Site is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $value = MGWS_DB::sanitize_loc($value);
        $parent_room = MGWS_DB::sanitize_loc($parent_room);
        $parent_rack = MGWS_DB::sanitize_loc($parent_rack);

        $meta_key = '';
        if ($field === 'room') {
            $meta_key = 'mgws_site_rooms';
        } elseif ($field === 'rack') {
            $meta_key = 'mgws_site_racks';
        } elseif ($field === 'shelf') {
            $meta_key = 'mgws_site_shelves';
        } else {
            wp_send_json_error(array('message' => __('Invalid field', 'mg-warehouse-stock')), 400);
        }

        // Enforce hierarchy: room -> rack -> shelf.
        if ($field === 'rack' && $parent_room === '') {
            wp_send_json_error(array('message' => __('Select a room first', 'mg-warehouse-stock')), 400);
        }
        if ($field === 'shelf' && ($parent_room === '' || $parent_rack === '')) {
            wp_send_json_error(array('message' => __('Select a room and a rack first', 'mg-warehouse-stock')), 400);
        }

        $arr = get_post_meta($site_id, $meta_key, true);
        if (!is_array($arr)) {
            $arr = array();
        }
        $arr[] = $value;
        $arr = array_values(array_unique(array_filter(array_map('strval', $arr))));
        sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
        update_post_meta($site_id, $meta_key, $arr);

        // Update hierarchical tree meta.
        $tree = get_post_meta($site_id, 'mgws_site_loc_tree', true);
        $tree = MGWS_DB::normalize_location_tree($tree);
        if ($field === 'room') {
            if (!isset($tree['rooms'][$value])) {
                $tree['rooms'][$value] = array('racks' => array());
            }
        } elseif ($field === 'rack') {
            if (!isset($tree['rooms'][$parent_room])) {
                $tree['rooms'][$parent_room] = array('racks' => array());
            }
            if (!isset($tree['rooms'][$parent_room]['racks'][$value])) {
                $tree['rooms'][$parent_room]['racks'][$value] = array('shelves' => array());
            }
        } elseif ($field === 'shelf') {
            if (!isset($tree['rooms'][$parent_room])) {
                $tree['rooms'][$parent_room] = array('racks' => array());
            }
            if (!isset($tree['rooms'][$parent_room]['racks'][$parent_rack])) {
                $tree['rooms'][$parent_room]['racks'][$parent_rack] = array('shelves' => array());
            }
            $sarr = $tree['rooms'][$parent_room]['racks'][$parent_rack]['shelves'] ?? array();
            if (!is_array($sarr)) {
                $sarr = array();
            }
            $sarr[] = $value;
            $sarr = array_values(array_unique(array_filter(array_map('strval', $sarr))));
            sort($sarr, SORT_NATURAL | SORT_FLAG_CASE);
            $tree['rooms'][$parent_room]['racks'][$parent_rack]['shelves'] = $sarr;
        }

        $tree = MGWS_DB::normalize_location_tree($tree);
        update_post_meta($site_id, 'mgws_site_loc_tree', $tree);

        // Return suggestions for the current warehouse (or any warehouse in the same site).
        $wid_for_suggest = $warehouse_id;
        if ($wid_for_suggest <= 0) {
            $whs = get_posts(array(
                'post_type' => 'mg_warehouse',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => 'mg_site_id',
                        'value' => $site_id,
                        'compare' => '=',
                    ),
                ),
            ));
            if (!empty($whs)) {
                $wid_for_suggest = (int) $whs[0]->ID;
            }
        }
        $suggest = $wid_for_suggest > 0 ? MGWS_DB::get_location_suggestions_for_warehouse($wid_for_suggest) : array('rooms' => array(), 'racks' => array(), 'shelves' => array(), 'racks_by_room' => array(), 'shelves_by_room_rack' => array(), 'tree' => array('rooms' => array()));
        wp_send_json_success($suggest);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_delete_location_value() {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        $this->require_ajax_nonce_any(array('mgws_product_nonce', 'mgws_admin_nonce'));
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_ajax_nonce_any(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
        $parent_room = isset($_POST['parent_room']) ? sanitize_text_field(wp_unslash($_POST['parent_room'])) : '';
        $parent_rack = isset($_POST['parent_rack']) ? sanitize_text_field(wp_unslash($_POST['parent_rack'])) : '';

        if (($warehouse_id <= 0 && $site_id <= 0) || $field === '' || $value === '') {
            wp_send_json_error(array('message' => __('Missing data', 'mg-warehouse-stock')), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_id <= 0) {
            $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        }
        if ($site_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid site', 'mg-warehouse-stock')), 400);
        }
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Site is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $value = MGWS_DB::sanitize_loc($value);
        $parent_room = MGWS_DB::sanitize_loc($parent_room);
        $parent_rack = MGWS_DB::sanitize_loc($parent_rack);

        // Enforce hierarchy requirements.
        if ($field === 'rack' && $parent_room === '') {
            wp_send_json_error(array('message' => __('Select a room first', 'mg-warehouse-stock')), 400);
        }
        if ($field === 'shelf' && ($parent_room === '' || $parent_rack === '')) {
            wp_send_json_error(array('message' => __('Select a room and a rack first', 'mg-warehouse-stock')), 400);
        }

        // Block deletion if used by stock levels.
        $links = MGWS_DB::count_warehouse_links_using_location($site_id, $field, $value, $parent_room, $parent_rack);
        if ($links > 0) {
            wp_send_json_error(array(
                'code' => 'mgws_not_deletable',
                'message' => sprintf(
                    /* translators: %d: number of warehouses linked to this location. */
                    __('Cannot delete: linked to %d warehouses', 'mg-warehouse-stock'),
                    (int) $links
                ),
            ), 409);
        }

        $used = MGWS_DB::count_levels_using_location($site_id, $field, $value, $parent_room, $parent_rack);
        if ($used > 0) {
            wp_send_json_error(array(
                'code' => 'mgws_not_deletable',
                'message' => sprintf(
                    /* translators: %d: number of stock levels stored at this location. */
                    __('Cannot delete: used by %d stock levels', 'mg-warehouse-stock'),
                    (int) $used
                ),
            ), 409);
        }

        $res = MGWS_DB::delete_from_site_tree($site_id, $field, $value, $parent_room, $parent_rack);
        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 400);
        }

        // Return updated suggestions.
        $wid_for_suggest = $warehouse_id;
        if ($wid_for_suggest <= 0) {
            $whs = get_posts(array(
                'post_type' => 'mg_warehouse',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => 'mg_site_id',
                        'value' => $site_id,
                        'compare' => '=',
                    ),
                ),
            ));
            if (!empty($whs)) {
                $wid_for_suggest = (int) $whs[0]->ID;
            }
        }
        $suggest = $wid_for_suggest > 0
            ? MGWS_DB::get_location_suggestions_for_warehouse($wid_for_suggest)
            : array('rooms' => array(), 'racks' => array(), 'shelves' => array(), 'racks_by_room' => array(), 'shelves_by_room_rack' => array(), 'tree' => array('rooms' => array()));
        wp_send_json_success($suggest);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    private function require_admin_nonce() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_admin_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }
    }

    private function require_ajax_nonce_any($nonces) {
        $nonces = is_array($nonces) ? $nonces : array();
        $val = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ($val === '') {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }
        foreach ($nonces as $n) {
            if ($n && wp_verify_nonce($val, (string) $n)) {
                return;
            }
        }
        wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
    }

    private function require_admin_delete_caps() {
        // Deletions are destructive: keep to admins.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_admin_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }
    }

    private function site_tree_has_room_rack_shelf($site_id, $room, $rack, $shelf, $scope) {
        $site_id = (int) $site_id;
        $tree = MGWS_DB::get_site_location_tree($site_id);
        $rooms = $tree['rooms'] ?? array();
        if (!is_array($rooms) || !isset($rooms[$room])) {
            return false;
        }
        if ($scope === 'room_all') {
            return true;
        }
        $racks = $rooms[$room]['racks'] ?? array();
        if (!is_array($racks) || !isset($racks[$rack])) {
            return false;
        }
        if ($scope === 'rack_all') {
            return true;
        }
        $shelves = $racks[$rack]['shelves'] ?? array();
        if (!is_array($shelves)) {
            return false;
        }
        return in_array($shelf, $shelves, true);
    }

    public function ajax_link_warehouse_location() {
        $this->require_admin_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_admin_nonce(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $room = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['room'] ?? '')));
        $rack = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['rack'] ?? '')));
        $shelf = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['shelf'] ?? '')));
        $scope = sanitize_key((string) wp_unslash($_POST['scope'] ?? ''));
        if ($warehouse_id <= 0 || $room === '' || $scope === '') {
            wp_send_json_error(array('message' => __('Missing data', 'mg-warehouse-stock')), 400);
        }

        if ($scope !== 'shelf_one') {
            wp_send_json_error(array('message' => __('Only shelf links are allowed here', 'mg-warehouse-stock')), 400);
        }
        if ($rack === '' || $shelf === '') {
            wp_send_json_error(array('message' => __('Select a rack and a shelf', 'mg-warehouse-stock')), 400);
        }

        $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Warehouse is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        if (!$this->site_tree_has_room_rack_shelf($site_id, $room, $rack, $shelf, $scope)) {
            wp_send_json_error(array('message' => __('Location not found in the site structure', 'mg-warehouse-stock')), 400);
        }

        $res = MGWS_DB::link_location_to_warehouse($warehouse_id, $room, $rack, $shelf, $scope);
        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 400);
        }
        wp_send_json_success(array('wh_tree' => MGWS_DB::get_warehouse_location_tree($warehouse_id)));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_unlink_warehouse_location() {
        $this->require_admin_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_admin_nonce(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $room = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['room'] ?? '')));
        $rack = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['rack'] ?? '')));
        $shelf = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['shelf'] ?? '')));
        $scope = sanitize_key((string) wp_unslash($_POST['scope'] ?? ''));
        if ($warehouse_id <= 0 || $room === '' || $scope === '') {
            wp_send_json_error(array('message' => __('Missing data', 'mg-warehouse-stock')), 400);
        }

        $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Warehouse is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $res = MGWS_DB::unlink_location_from_warehouse($warehouse_id, $room, $rack, $shelf, $scope);
        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 400);
        }
        wp_send_json_success(array('wh_tree' => MGWS_DB::get_warehouse_location_tree($warehouse_id)));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_delete_warehouse() {
        $this->require_admin_delete_caps();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_admin_delete_caps(), which exits on failure.

        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        if ($warehouse_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid warehouse', 'mg-warehouse-stock')), 400);
        }

        $site_id = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Warehouse is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $levels = MGWS_DB::count_levels_for_warehouse($warehouse_id);
        $moves = MGWS_DB::count_moves_for_warehouse($warehouse_id);
        if ($levels > 0 || $moves > 0) {
            wp_send_json_error(array(
                'code' => 'mgws_not_deletable',
                'message' => sprintf(
                    /* translators: 1: number of stock levels, 2: number of stock movements. */
                    __('Cannot delete: warehouse is in use (levels=%1$d, movements=%2$d)', 'mg-warehouse-stock'),
                    (int) $levels,
                    (int) $moves
                ),
            ), 409);
        }

        $res = wp_delete_post($warehouse_id, true);
        if (!$res) {
            wp_send_json_error(array('message' => __('Could not delete the warehouse', 'mg-warehouse-stock')), 500);
        }
        wp_send_json_success(array('ok' => true));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_delete_site() {
        $this->require_admin_delete_caps();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was just verified by require_admin_delete_caps(), which exits on failure.

        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        if ($site_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid site', 'mg-warehouse-stock')), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Site is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $warehouses = get_posts(array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => 'mg_site_id',
                    'value' => $site_id,
                    'compare' => '=',
                ),
            ),
            'fields' => 'ids',
        ));
        if (!empty($warehouses)) {
            wp_send_json_error(array(
                'code' => 'mgws_not_deletable',
                'message' => __('Cannot delete: the site still has warehouses', 'mg-warehouse-stock'),
            ), 409);
        }

        $levels = MGWS_DB::count_levels_for_site($site_id);
        $moves = MGWS_DB::count_moves_for_site($site_id);
        if ($levels > 0 || $moves > 0) {
            wp_send_json_error(array(
                'code' => 'mgws_not_deletable',
                'message' => sprintf(
                    /* translators: 1: number of stock levels, 2: number of stock movements. */
                    __('Cannot delete: site is in use (levels=%1$d, movements=%2$d)', 'mg-warehouse-stock'),
                    (int) $levels,
                    (int) $moves
                ),
            ), 409);
        }

        $res = wp_delete_post($site_id, true);
        if (!$res) {
            wp_send_json_error(array('message' => __('Could not delete the site', 'mg-warehouse-stock')), 500);
        }
        wp_send_json_success(array('ok' => true));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function ajax_apply_inventory_op() {
        if (!$this->can_move_stock_ajax()) {
            wp_send_json_error(array('message' => __('You do not have permission to do that', 'mg-warehouse-stock')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mgws_product_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'mg-warehouse-stock')), 400);
        }

        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'adjust';
        $warehouse_id = isset($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 0;
        $to_warehouse_id = isset($_POST['to_warehouse_id']) ? (int) $_POST['to_warehouse_id'] : 0;
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        $qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;

        $room = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['room'] ?? '')));
        $rack = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['rack'] ?? '')));
        $shelf = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['shelf'] ?? '')));

        $to_room = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['to_room'] ?? '')));
        $to_rack = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['to_rack'] ?? '')));
        $to_shelf = MGWS_DB::sanitize_loc(sanitize_text_field(wp_unslash($_POST['to_shelf'] ?? '')));

        $item_ok = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($item_ok)) {
            wp_send_json_error(array('message' => $item_ok->get_error_message()), 400);
        }

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        if ($warehouse_id <= 0 || $wh_site <= 0) {
            wp_send_json_error(array('message' => __('Invalid warehouse', 'mg-warehouse-stock')), 400);
        }
        if ($site_limit > 0 && (int) $wh_site !== (int) $site_limit) {
            wp_send_json_error(array('message' => __('Warehouse is outside your assigned site', 'mg-warehouse-stock')), 403);
        }

        $user_id = get_current_user_id();

        if ($operation === 'adjust') {
            if ($qty < 0) {
                wp_send_json_error(array('message' => __('Quantity must be 0 or more', 'mg-warehouse-stock')), 400);
            }
            $old = MGWS_DB::get_level_row($warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
            $old_qty = (int) ($old['qty'] ?? 0);
            $res = MGWS_DB::upsert_level($warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 400);
            }
            $delta = (int) $qty - $old_qty;
            MGWS_DB::insert_move('adjust', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $delta, $room, $rack, $shelf, $user_id, 0, 'Product adjust');
            $this->sync_woo_stock($product_id, $variation_id);
            wp_send_json_success(array('message' => __('Updated', 'mg-warehouse-stock')));
        }

        if ($operation === 'in') {
            if ($qty <= 0) {
                wp_send_json_error(array('message' => __('Quantity must be greater than 0', 'mg-warehouse-stock')), 400);
            }
            $res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 409);
            }
            MGWS_DB::insert_move('in', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'Product in');
            $this->sync_woo_stock($product_id, $variation_id);
            wp_send_json_success(array('message' => __('Stock-in recorded', 'mg-warehouse-stock')));
        }

        if ($operation === 'out') {
            if ($qty <= 0) {
                wp_send_json_error(array('message' => __('Quantity must be greater than 0', 'mg-warehouse-stock')), 400);
            }
            $res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, -$qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                wp_send_json_error(array('message' => $res['message'] ?? __('Error', 'mg-warehouse-stock')), 409);
            }
            MGWS_DB::insert_move('out', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'Product out');
            $this->sync_woo_stock($product_id, $variation_id);
            wp_send_json_success(array('message' => __('Stock-out recorded', 'mg-warehouse-stock')));
        }

        if ($operation === 'transfer') {
            if ($qty <= 0) {
                wp_send_json_error(array('message' => __('Quantity must be greater than 0', 'mg-warehouse-stock')), 400);
            }
            if ($to_warehouse_id <= 0) {
                wp_send_json_error(array('message' => __('Select a destination warehouse', 'mg-warehouse-stock')), 400);
            }
            if ($to_warehouse_id === $warehouse_id) {
                wp_send_json_error(array('message' => __('Source and destination warehouse must be different', 'mg-warehouse-stock')), 400);
            }
            $to_site = MGWS_DB::get_site_id_for_warehouse($to_warehouse_id);
            if ($to_site <= 0) {
                wp_send_json_error(array('message' => __('Invalid destination', 'mg-warehouse-stock')), 400);
            }
            if ($site_limit > 0 && (int) $to_site !== (int) $site_limit) {
                wp_send_json_error(array('message' => __('Destination is outside your assigned site', 'mg-warehouse-stock')), 403);
            }

            global $wpdb;
            $wpdb->query('START TRANSACTION');
            $from_res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, -$qty, $room, $rack, $shelf);
            if (!$from_res['ok']) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array('message' => $from_res['message'] ?? __('Error', 'mg-warehouse-stock')), 409);
            }
            $to_res = MGWS_DB::apply_delta_level($to_warehouse_id, $product_id, $variation_id, $qty, $to_room, $to_rack, $to_shelf);
            if (!$to_res['ok']) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array('message' => $to_res['message'] ?? __('Error', 'mg-warehouse-stock')), 409);
            }

            MGWS_DB::insert_move('out', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'Transfer out', $warehouse_id, $to_warehouse_id);
            MGWS_DB::insert_move('in', (int) $to_site, $to_warehouse_id, $product_id, $variation_id, $qty, $to_room, $to_rack, $to_shelf, $user_id, 0, 'Transfer in', $warehouse_id, $to_warehouse_id);
            $wpdb->query('COMMIT');
            $this->sync_woo_stock($product_id, $variation_id);
            wp_send_json_success(array('message' => __('Transfer recorded', 'mg-warehouse-stock')));
        }

        wp_send_json_error(array('message' => __('Invalid operation', 'mg-warehouse-stock')), 400);
    }

    public function add_warehouse_metabox() {
        add_meta_box('mgws_warehouse_site', __('Site', 'mg-warehouse-stock'), array($this, 'render_warehouse_metabox'), 'mg_warehouse', 'side', 'high');
    }

    public function render_warehouse_metabox($post) {
        $current = (int) get_post_meta($post->ID, 'mg_site_id', true);
        wp_nonce_field('mgws_wh_site_nonce', 'mgws_wh_site_nonce');
        $sites = get_posts(array('post_type' => 'mg_site', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        echo '<select name="mgws_site_id">';
        echo '<option value="0">' . esc_html__('-- select --', 'mg-warehouse-stock') . '</option>';
        foreach ($sites as $s) {
            echo '<option value="' . esc_attr((int) $s->ID) . '"' . selected($current, (int) $s->ID, false) . '>' . esc_html($s->post_title) . '</option>';
        }
        echo '</select>';
    }

    public function save_warehouse_metabox($post_id, $post) {
        if (!isset($_POST['mgws_wh_site_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mgws_wh_site_nonce'])), 'mgws_wh_site_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $site_id = isset($_POST['mgws_site_id']) ? (int) $_POST['mgws_site_id'] : 0;
        update_post_meta($post_id, 'mg_site_id', $site_id);
        MGWS_DB::reassign_levels_site_id($post_id, $site_id);
    }

    public function render_user_site_field($user) {
        $can_edit = $this->can_edit_user_site($user->ID);
        if (!$can_edit) {
            return;
        }
        if ($can_edit) {
            $current = (int) get_user_meta($user->ID, 'mg_default_site_id', true);
            $sites = get_posts(array('post_type' => 'mg_site', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
            echo '<h3>' . esc_html__('Warehouse', 'mg-warehouse-stock') . '</h3>';
            echo '<table class="form-table" role="presentation">';
            echo '<tr><th><label for="mg_default_site_id">' . esc_html__('Default site', 'mg-warehouse-stock') . '</label></th><td>';
            echo '<select name="mg_default_site_id" id="mg_default_site_id">';
            echo '<option value="0">' . esc_html__('(All)', 'mg-warehouse-stock') . '</option>';
            foreach ($sites as $s) {
                echo '<option value="' . esc_attr((int) $s->ID) . '"' . selected($current, (int) $s->ID, false) . '>' . esc_html($s->post_title) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('When set, limits the user to a single site.', 'mg-warehouse-stock') . '</p>';
            echo '</td></tr></table>';
        }
    }

    public function save_user_site_field($user_id) {
        $can_site = $this->can_edit_user_site($user_id);
        // This runs on personal_options_update / edit_user_profile_update, which core fires only after
        // check_admin_referer('update-user_' . $user_id) in wp-admin/user-edit.php.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ($can_site && isset($_POST['mg_default_site_id'])) {
            $new_site = (int) $_POST['mg_default_site_id'];
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            if (!current_user_can('manage_options')) {
                $my_site = (int) get_user_meta(get_current_user_id(), 'mg_default_site_id', true);
                if ($my_site <= 0) {
                    return;
                }
                $new_site = $my_site;
            }
            update_user_meta($user_id, 'mg_default_site_id', $new_site);
        }
    }

    private function can_edit_user_site($target_user_id) {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!current_user_can('edit_users')) {
            return false;
        }
        $my_site = (int) get_user_meta(get_current_user_id(), 'mg_default_site_id', true);
        $target_site = (int) get_user_meta($target_user_id, 'mg_default_site_id', true);
        if ($my_site <= 0 || $target_site <= 0) {
            return false;
        }
        return $my_site === $target_site;
    }
}
