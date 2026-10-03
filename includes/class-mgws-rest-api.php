<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal REST API for MGWS.
 *
 * Authentication is expected to be provided by the site (e.g. JWT plugin).
 * This class only checks that a user is logged in and has the required capabilities.
 */
class MGWS_REST_API {
    private static $instance = null;
    private const POS_TURNO_OBBLIGATORIO_OPTION = 'mgws_pos_turno_obbligatorio';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('mgws', '/health', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_health'),
        ));

        register_rest_route('mgws', '/stock/levels', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_stock_levels'),
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'variation_id' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                ),
            ),
        ));

        register_rest_route('mgws', '/stock/move', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_stock_move'),
            'callback' => array($this, 'route_stock_move'),
        ));

        register_rest_route('mgws', '/orders/(?P<order_id>\\d+)/accept', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_order_accept'),
            'callback' => array($this, 'route_order_accept'),
            'args' => array(
                'order_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('mgws', '/resolve/barcode/(?P<code>[^/]+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_resolve_barcode'),
            'args' => array(
                'code' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));

        register_rest_route('mgws/v1', '/me/settings', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_logged_in'),
                'callback' => array($this, 'route_me_settings_get'),
                'args' => $this->empty_route_args(),
            ),
            array(
                'methods' => 'PATCH',
                'permission_callback' => array($this, 'perm_logged_in'),
                'callback' => array($this, 'route_me_settings_patch'),
                'args' => $this->empty_route_args(),
            ),
        ));

        register_rest_route('mgws/v1', '/employees', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_manage_employees'),
                'callback' => array($this, 'route_employees_list'),
                'args' => array(
                    'search' => $this->inventory_text_arg(),
                    'include_inactive' => array('required' => false, 'type' => 'boolean', 'sanitize_callback' => array($this, 'sanitize_pos_boolean'), 'validate_callback' => array($this, 'validate_pos_boolean'), 'default' => false),
                    'limit' => $this->inventory_non_negative_int_arg(100),
                ),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array($this, 'perm_manage_employees'),
                'callback' => array($this, 'route_employee_create'),
                'args' => $this->empty_route_args(),
            ),
        ));

        register_rest_route('mgws/v1', '/employees/(?P<employee_id>\d+)', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_manage_employees'),
                'callback' => array($this, 'route_employee_get'),
                'args' => array('employee_id' => $this->inventory_positive_int_arg()),
            ),
            array(
                'methods' => 'PATCH',
                'permission_callback' => array($this, 'perm_manage_employees'),
                'callback' => array($this, 'route_employee_update'),
                'args' => array('employee_id' => $this->inventory_positive_int_arg()),
            ),
            array(
                'methods' => 'DELETE',
                'permission_callback' => array($this, 'perm_manage_employees'),
                'callback' => array($this, 'route_employee_delete'),
                'args' => array('employee_id' => $this->inventory_positive_int_arg()),
            ),
        ));

        register_rest_route('mgws/v1', '/users/(?P<user_id>\d+)/permissions', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_manage_user_permissions'),
                'callback' => array($this, 'route_get_user_permissions'),
            ),
            array(
                'methods' => 'PATCH',
                'permission_callback' => array($this, 'perm_manage_user_permissions'),
                'callback' => array($this, 'route_patch_user_permissions'),
            ),
        ));

        register_rest_route('mgws/v1', '/users/(?P<user_id>\d+)/app-passwords', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_manage_credentials'),
                'callback' => array($this, 'route_list_app_passwords'),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array($this, 'perm_manage_credentials'),
                'callback' => array($this, 'route_create_app_password'),
            ),
        ));

        register_rest_route('mgws/v1', '/users/(?P<user_id>\d+)/app-passwords/(?P<uuid>[A-Za-z0-9\-]+)', array(
            'methods' => 'DELETE',
            'permission_callback' => array($this, 'perm_manage_credentials'),
            'callback' => array($this, 'route_delete_app_password'),
        ));

        register_rest_route('mgws/v1', '/users/(?P<user_id>\d+)/woo-keys', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_manage_credentials'),
                'callback' => array($this, 'route_list_woo_keys'),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array($this, 'perm_manage_credentials'),
                'callback' => array($this, 'route_create_woo_key'),
            ),
        ));

        register_rest_route('mgws/v1', '/users/(?P<user_id>\d+)/woo-keys/(?P<key_id>\d+)', array(
            'methods' => 'DELETE',
            'permission_callback' => array($this, 'perm_manage_credentials'),
            'callback' => array($this, 'route_delete_woo_key'),
        ));

        register_rest_route('mgws/v1', '/pos/checkout', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_pos_checkout'),
            'callback' => array($this, 'route_pos_checkout'),
            'args' => $this->pos_checkout_args(),
        ));

        register_rest_route('mgws/v1', '/pos/shifts', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_pos_checkout'),
            'callback' => array($this, 'route_pos_shift_open'),
            'args' => $this->pos_shift_open_args(),
        ));

        register_rest_route('mgws/v1', '/pos/shifts', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_pos_shift_list'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/pos/shifts/(?P<shift_ident>[^/]+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_pos_shift_get'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/pos/shifts/(?P<shift_ident>[^/]+)/close', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_pos_checkout'),
            'callback' => array($this, 'route_pos_shift_close'),
            'args' => $this->pos_shift_close_args(),
        ));

        register_rest_route('mgws/v1', '/pos/receipts', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_pos_receipt_list'),
            'args' => array(
                'business_day_id' => $this->inventory_text_arg(),
                'register_name' => $this->inventory_text_arg(),
                'operation_type' => $this->inventory_text_arg(),
                'payment_method' => $this->inventory_text_arg(),
                'status' => $this->inventory_text_arg(),
                'customer_id' => $this->inventory_non_negative_int_arg(0),
                'query' => $this->inventory_text_arg(),
                'limit' => $this->inventory_non_negative_int_arg(100),
                'offset' => $this->inventory_non_negative_int_arg(0),
            ),
        ));

        register_rest_route('mgws/v1', '/pos/receipts/(?P<receipt_ident>[^/]+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_pos_receipt_get'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/pos/receipts/(?P<receipt_ident>[^/]+)/status', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_pos_checkout'),
            'callback' => array($this, 'route_pos_receipt_status'),
            'args' => array(
                'status' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'validate_callback' => array($this, 'validate_pos_receipt_status')),
                'note' => $this->inventory_text_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/pos/closings', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_stock_read'),
                'callback' => array($this, 'route_pos_closing_list'),
                'args' => array(
                    'business_day_id' => $this->inventory_text_arg(),
                    'register_name' => $this->inventory_text_arg(),
                    'limit' => $this->inventory_non_negative_int_arg(100),
                ),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array($this, 'perm_pos_checkout'),
                'callback' => array($this, 'route_pos_closing_create'),
                'args' => $this->pos_closing_args(),
            ),
        ));

        register_rest_route('mgws/v1', '/pos/closings/(?P<closing_ident>[^/]+)/corrections', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_pos_checkout'),
            'callback' => array($this, 'route_pos_closing_corrections'),
            'args' => array(
                'note' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'validate_callback' => array($this, 'validate_inventory_text')),
            ),
        ));

        register_rest_route('mgws/v1', '/pos/settings', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'perm_pos_checkout'),
                'callback' => array($this, 'route_pos_settings_get'),
                'args' => $this->empty_route_args(),
            ),
            array(
                'methods' => 'PUT',
                'permission_callback' => array($this, 'perm_manage_pos_settings'),
                'callback' => array($this, 'route_pos_settings_put'),
                'args' => $this->empty_route_args(),
            ),
        ));

        register_rest_route('mgws/v1', '/inventory/status', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_inventory_status'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/inventory/stock/product/(?P<product_id>\d+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_inventory_product_stock'),
            'args' => array(
                'product_id' => $this->inventory_positive_int_arg(),
                'variation_id' => $this->inventory_non_negative_int_arg(0),
            ),
        ));

        register_rest_route('mgws/v1', '/inventory/stock/all', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_inventory_stock_all'),
            'args' => $this->inventory_read_args(),
        ));

        register_rest_route('mgws/v1', '/inventory/statistics', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_inventory_statistics'),
            'args' => $this->inventory_read_args(),
        ));

        register_rest_route('mgws/v1', '/inventory/low-stock', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_stock_read'),
            'callback' => array($this, 'route_inventory_low_stock'),
            'args' => array_merge($this->inventory_read_args(), array(
                'threshold' => $this->inventory_non_negative_int_arg(5),
            )),
        ));

        register_rest_route('mgws/v1', '/inventory/stock/sync', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_stock_move'),
            'callback' => array($this, 'route_inventory_stock_sync'),
            'args' => array(
                'product_id' => $this->inventory_positive_int_arg(),
                'variation_id' => $this->inventory_non_negative_int_arg(0),
                'woo_stock' => $this->inventory_non_negative_int_arg(),
                // Facoltativa per la stessa regola della rettifica: con una sola
                // sede il numero di WooCommerce *e'* il totale di quella sede, con
                // piu' sedi va detto a quale dei due numeri si riferisce.
                'site_id' => $this->inventory_non_negative_int_arg(0),
                'sync_type' => $this->inventory_text_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/inventory/stock/reconcile', array(
            'methods' => 'PUT',
            'permission_callback' => array($this, 'perm_stock_move'),
            'callback' => array($this, 'route_inventory_stock_reconcile'),
            'args' => array(
                'product_id' => $this->inventory_positive_int_arg(),
                'variation_id' => $this->inventory_non_negative_int_arg(0),
                'correct_stock' => $this->inventory_non_negative_int_arg(),
                // Facoltativa, ma obbligatoria in fatto quando il negozio ha
                // piu' di una sede: lo zero vale "una sola sede", dove il totale
                // del prodotto coincide con il totale della sede. Dichiararla
                // richiesta qui senza condizioni farebbe fallire le installazioni
                // mono-sede per un campo che li' non distingue nulla.
                'site_id' => $this->inventory_non_negative_int_arg(0),
                'reason' => $this->inventory_text_arg(),
                // Facoltativa. Serve a tenere insieme le righe di una
                // rettifica multi-prodotto: l'app invia una richiesta per
                // prodotto e questa e' l'unica cosa che le identifica come
                // un'operazione sola. Senza, ogni riga resta un movimento a se'.
                'movement_key' => $this->inventory_movement_key_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/inventory/stock/move', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_stock_move'),
            'callback' => array($this, 'route_inventory_stock_move'),
            'args' => array(
                'product_id' => $this->inventory_positive_int_arg(),
                'variation_id' => $this->inventory_non_negative_int_arg(0),
                'from_warehouse_id' => $this->inventory_positive_int_arg(),
                'to_warehouse_id' => $this->inventory_positive_int_arg(),
                'quantity' => $this->inventory_positive_int_arg(),
                'reason' => $this->inventory_text_arg(),
                'movement_key' => $this->inventory_movement_key_arg(),
                'note' => $this->inventory_note_arg(),
                // La stanza e' per meta'. Se il client le manda gia' separate si
                // rispettano, altrimenti `inventory_move_level` prende quella
                // che il magazzino ha gia' per il prodotto. Dichiarare i due
                // prefissi e non un campo solo `room` evita che una meta' finisca
                // sull'altra: la partenza e l'arrivo sono posti diversi.
                'from_room' => $this->inventory_short_text_arg(),
                'from_rack' => $this->inventory_short_text_arg(),
                'from_shelf' => $this->inventory_short_text_arg(),
                'to_room' => $this->inventory_short_text_arg(),
                'to_rack' => $this->inventory_short_text_arg(),
                'to_shelf' => $this->inventory_short_text_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/inventory/rfid/scan', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_stock_move'),
            'callback' => array($this, 'route_inventory_rfid_scan'),
            'args' => array(
                'tags' => array(
                    'required' => true,
                    'type' => 'array',
                    'sanitize_callback' => array($this, 'sanitize_inventory_tags'),
                    'validate_callback' => array($this, 'validate_inventory_tags'),
                ),
            ),
        ));

        $this->register_inventory_restock_contract_routes();

        register_rest_route('mgws/v1', '/loyalty/status', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_status'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/loyalty/cards', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_cards_list'),
            'args' => $this->empty_route_args(),
        ));

        register_rest_route('mgws/v1', '/loyalty/customers/(?P<customer_id>\d+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_customer'),
            'args' => $this->loyalty_customer_args(),
        ));

        register_rest_route('mgws/v1', '/loyalty/lookup/card/(?P<card_number>[^/]+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_lookup_card'),
            'args' => array(
                'card_number' => $this->loyalty_card_number_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/loyalty/lookup/email/(?P<email>[^/]+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_lookup_email'),
            'args' => array(
                'email' => $this->loyalty_email_arg(),
            ),
        ));

        register_rest_route('mgws/v1', '/loyalty/customers/(?P<customer_id>\d+)/card', array(
            array(
                'methods' => 'PUT',
                'permission_callback' => array($this, 'perm_loyalty_mutate'),
                'callback' => array($this, 'route_loyalty_card_put'),
                'args' => array_merge($this->loyalty_customer_args(), array(
                    'card_number' => $this->loyalty_card_number_arg(),
                    'tier' => $this->loyalty_tier_arg(),
                )),
            ),
            array(
                'methods' => 'DELETE',
                'permission_callback' => array($this, 'perm_loyalty_mutate'),
                'callback' => array($this, 'route_loyalty_card_delete'),
                'args' => $this->loyalty_customer_args(),
            ),
        ));

        register_rest_route('mgws/v1', '/loyalty/customers/(?P<customer_id>\d+)/points/add', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_loyalty_mutate'),
            'callback' => array($this, 'route_loyalty_points_add'),
            'args' => $this->loyalty_points_args(),
        ));

        register_rest_route('mgws/v1', '/loyalty/customers/(?P<customer_id>\d+)/points/deduct', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'perm_loyalty_mutate'),
            'callback' => array($this, 'route_loyalty_points_deduct'),
            'args' => $this->loyalty_points_args(),
        ));

        register_rest_route('mgws/v1', '/loyalty/customers/(?P<customer_id>\d+)/history', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_history'),
            'args' => array_merge($this->loyalty_customer_args(), array(
                'page' => array(
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => array($this, 'sanitize_loyalty_positive_int'),
                    'validate_callback' => array($this, 'validate_loyalty_positive_int'),
                ),
                'per_page' => array(
                    'type' => 'integer',
                    'default' => 20,
                    'sanitize_callback' => array($this, 'sanitize_loyalty_positive_int'),
                    'validate_callback' => array($this, 'validate_loyalty_per_page'),
                ),
            )),
        ));

        register_rest_route('mgws/v1', '/loyalty/stats', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'perm_loyalty_read'),
            'callback' => array($this, 'route_loyalty_stats'),
            'args' => $this->empty_route_args(),
        ));
    }

    public static function inventory_restock_route_contract() {
        return array(
            '/inventory/quick-load' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/sites' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/warehouses' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/locations' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/suppliers' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/suppliers/(?P<supplier_id>\\d+)' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'PATCH', 'permission' => 'mutate'), array('methods' => 'DELETE', 'permission' => 'mutate')),
            '/inventory/reorder-rules' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/reorder-rules/(?P<rule_id>\\d+)' => array(array('methods' => 'PATCH', 'permission' => 'mutate'), array('methods' => 'DELETE', 'permission' => 'mutate')),
            '/inventory/reorder-suggestions' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/purchase-orders' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'PATCH', 'permission' => 'mutate')),
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/lines' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/status' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/verify' => array(array('methods' => 'POST', 'permission' => 'approve')),
            '/inventory/receipts' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/receipts/(?P<receipt_id>\\d+)' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'PATCH', 'permission' => 'mutate')),
            '/inventory/receipts/(?P<receipt_id>\\d+)/convalida' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/backorders' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/count-sessions' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/count-sessions/(?P<session_id>\\d+)' => array(array('methods' => 'GET', 'permission' => 'read'), array('methods' => 'PATCH', 'permission' => 'mutate')),
            '/inventory/count-sessions/(?P<session_id>\\d+)/lines' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/count-sessions/(?P<session_id>\\d+)/approve' => array(array('methods' => 'POST', 'permission' => 'mutate')),
            '/inventory/movements' => array(array('methods' => 'GET', 'permission' => 'read')),
            '/inventory/movements/(?P<movement_id>\\d+)' => array(array('methods' => 'GET', 'permission' => 'read')),
        );
    }

    private function register_inventory_restock_contract_routes() {
        $callbacks = array(
            '/inventory/quick-load POST' => 'route_inventory_quick_load',
            '/inventory/sites GET' => 'route_inventory_sites_list',
            '/inventory/warehouses GET' => 'route_inventory_warehouses_list',
            '/inventory/locations GET' => 'route_inventory_locations_tree',
            '/inventory/count-sessions GET' => 'route_inventory_count_sessions_list',
            '/inventory/count-sessions POST' => 'route_inventory_count_session_create',
            '/inventory/count-sessions/(?P<session_id>\\d+) GET' => 'route_inventory_count_session_detail',
            '/inventory/count-sessions/(?P<session_id>\\d+) PATCH' => 'route_inventory_count_session_patch',
            '/inventory/count-sessions/(?P<session_id>\\d+)/lines POST' => 'route_inventory_count_session_line_create',
            '/inventory/count-sessions/(?P<session_id>\\d+)/approve POST' => 'route_inventory_count_session_approve',
            '/inventory/suppliers GET' => 'route_inventory_suppliers_list',
            '/inventory/suppliers POST' => 'route_inventory_suppliers_create',
            '/inventory/suppliers/(?P<supplier_id>\\d+) GET' => 'route_inventory_supplier_get',
            '/inventory/suppliers/(?P<supplier_id>\\d+) PATCH' => 'route_inventory_supplier_update',
            '/inventory/suppliers/(?P<supplier_id>\\d+) DELETE' => 'route_inventory_supplier_delete',
            '/inventory/reorder-rules GET' => 'route_inventory_reorder_rules_list',
            '/inventory/reorder-rules POST' => 'route_inventory_reorder_rules_create',
            '/inventory/reorder-rules/(?P<rule_id>\\d+) PATCH' => 'route_inventory_reorder_rule_update',
            '/inventory/reorder-rules/(?P<rule_id>\\d+) DELETE' => 'route_inventory_reorder_rule_delete',
            '/inventory/reorder-suggestions GET' => 'route_inventory_reorder_suggestions',
            '/inventory/purchase-orders GET' => 'route_inventory_purchase_orders_list',
            '/inventory/purchase-orders POST' => 'route_inventory_purchase_orders_create',
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+) GET' => 'route_inventory_purchase_order_get',
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+) PATCH' => 'route_inventory_purchase_order_update',
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/lines POST' => 'route_inventory_purchase_order_line_upsert',
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/status POST' => 'route_inventory_purchase_order_status',
            '/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/verify POST' => 'route_inventory_purchase_order_verify',
            '/inventory/receipts GET' => 'route_inventory_receipts_list',
            '/inventory/receipts POST' => 'route_inventory_receipts_create',
            '/inventory/receipts/(?P<receipt_id>\\d+) GET' => 'route_inventory_receipt_get',
            '/inventory/receipts/(?P<receipt_id>\\d+) PATCH' => 'route_inventory_receipt_patch',
            '/inventory/receipts/(?P<receipt_id>\\d+)/convalida POST' => 'route_inventory_receipt_convalida',
            '/inventory/backorders GET' => 'route_inventory_backorders_list',
            '/inventory/movements GET' => 'route_inventory_movements_list',
            '/inventory/movements/(?P<movement_id>\\d+) GET' => 'route_inventory_movement_get',
        );
        foreach (self::inventory_restock_route_contract() as $route => $operations) {
            $definitions = array();
            foreach ($operations as $operation) {
                $definitions[] = array(
                    'methods' => $operation['methods'],
                    'permission_callback' => array($this, $operation['permission'] === 'read' ? 'perm_inventory_restock_read' : ($operation['permission'] === 'approve' ? 'perm_inventory_purchase_approve' : 'perm_inventory_restock_mutate')),
                    'callback' => array($this, $callbacks[$route . ' ' . $operation['methods']] ?? 'route_inventory_restock_not_implemented'),
                    'args' => $this->inventory_count_route_args($route, $operation['methods']),
                );
            }
            register_rest_route('mgws/v1', $route, count($definitions) === 1 ? $definitions[0] : $definitions);
        }
    }

    public function route_inventory_restock_not_implemented(WP_REST_Request $request) {
        return new WP_Error('mgws_not_implemented', 'This MGWS inventory route is not implemented yet', array('status' => 501));
    }

    public function route_inventory_sites_list(WP_REST_Request $request) {
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $args = array(
            'post_type' => 'mg_site',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        );
        if ($site_limit > 0) {
            $args['post__in'] = array($site_limit);
        }
        $ids = get_posts($args);
        return rest_ensure_response(array_map(function ($site_id) {
            $site_id = (int) $site_id;
            return array(
                'id' => $site_id,
                'name' => function_exists('get_the_title') ? (string) get_the_title($site_id) : (__('Site', 'mg-warehouse-stock') . ' ' . $site_id),
                'active' => true,
            );
        }, is_array($ids) ? $ids : array()));
    }

    public function route_inventory_warehouses_list(WP_REST_Request $request) {
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        if (is_wp_error($site_id)) { return $site_id; }
        $args = array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        );
        if ($site_id > 0) {
            $args['meta_query'] = array(array('key' => 'mg_site_id', 'value' => $site_id, 'compare' => '='));
        }
        $ids = get_posts($args);
        return rest_ensure_response(array_map(function ($warehouse_id) {
            $warehouse_id = (int) $warehouse_id;
            return array(
                'id' => $warehouse_id,
                'site_id' => (int) get_post_meta($warehouse_id, 'mg_site_id', true),
                'name' => function_exists('get_the_title') ? (string) get_the_title($warehouse_id) : (__('Warehouse', 'mg-warehouse-stock') . ' ' . $warehouse_id),
                'active' => true,
            );
        }, is_array($ids) ? $ids : array()));
    }

    public function route_inventory_locations_tree(WP_REST_Request $request) {
        $site_id = $this->inventory_restock_site($request->get_param('site_id'), true);
        if (is_wp_error($site_id)) { return $site_id; }
        $tree = MGWS_DB::get_site_location_tree($site_id);
        return rest_ensure_response(array_merge(array('site_id' => $site_id), is_array($tree) ? $tree : array('rooms' => array())));
    }

    public function route_inventory_purchase_order_verify(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) { return $storage; }
        $purchase_order = $this->inventory_restock_purchase_order($request->get_param('purchase_order_id'));
        if (is_wp_error($purchase_order)) { return $purchase_order; }
        $current = (string) ($purchase_order['status'] ?? '');
        if (!in_array($current, array('draft', 'pending'), true)) {
            return new WP_Error('mgws_purchase_order_invalid_transition', 'Purchase order is not waiting for verification', array('status' => 409));
        }
        $updated = MGWS_DB::update_purchase_order((int) $purchase_order['id'], array(
            'status' => 'ordered',
            'ordered_at_gmt' => gmdate('Y-m-d H:i:s'),
            'updated_by_user_id' => get_current_user_id(),
        ));
        return is_array($updated)
            ? rest_ensure_response($this->inventory_purchase_order_response($updated))
            : new WP_Error('mgws_error', 'Purchase order could not be verified', array('status' => 500));
    }

    public function route_inventory_movements_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $filters = $this->inventory_movement_filters($request);
        if (is_wp_error($filters)) {
            return $filters;
        }
        $page = $this->inventory_positive($request->get_param('page') ?? 1, 'page');
        $per_page = $this->inventory_positive($request->get_param('per_page') ?? 50, 'per_page');
        if (is_wp_error($page) || is_wp_error($per_page)) {
            return is_wp_error($page) ? $page : $per_page;
        }
        if ($per_page > 100) {
            return new WP_Error('mgws_bad_request', 'per_page must not exceed 100', array('status' => 400));
        }
        $total = MGWS_DB::count_moves($filters);
        $rows = MGWS_DB::list_moves($filters, $per_page, ($page - 1) * $per_page);
        return rest_ensure_response(array(
            'items' => array_map(array($this, 'inventory_movement_response'), $rows),
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $per_page),
        ));
    }

    public function route_inventory_movement_get(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $movement_id = $this->inventory_positive($request->get_param('movement_id'), 'movement_id');
        if (is_wp_error($movement_id)) {
            return $movement_id;
        }
        $movement = MGWS_DB::get_move($movement_id);
        if (!is_array($movement)) {
            return new WP_Error('mgws_movement_not_found', 'Movement not found', array('status' => 404));
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && (int) ($movement['site_id'] ?? 0) !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Site outside allowed scope', array('status' => 403));
        }
        return rest_ensure_response($this->inventory_movement_response($movement));
    }

    private function inventory_movement_filters(WP_REST_Request $request) {
        $filters = array();
        $product_value = $request->get_param('product_id');
        if ($product_value !== null) {
            $product_id = $this->inventory_positive($product_value, 'product_id');
            if (is_wp_error($product_id)) {
                return $product_id;
            }
            $filters['product_id'] = $product_id;
        }
        $variation_value = $request->get_param('variation_id');
        if ($variation_value !== null) {
            $variation_id = $this->inventory_non_negative($variation_value, 'variation_id');
            if (is_wp_error($variation_id)) {
                return $variation_id;
            }
            $filters['variation_id'] = $variation_id;
        }
        $operator = $this->inventory_movement_alias_int($request, 'operator', 'user_id');
        if (is_wp_error($operator)) {
            return $operator;
        }
        if ($operator > 0) {
            $filters['user_id'] = $operator;
        }
        // Filtro per operazione: senza, la schermata che mostra un movimento per
        // riga puo' solo ricevere la prima pagina del libro e dire che un'operazione
        // da trenta prodotti finisce dove finisce la pagina.
        $movement_value = $request->get_param('movement_id');
        if ($movement_value !== null) {
            $movement_id = $this->inventory_non_negative($movement_value, 'movement_id');
            if (is_wp_error($movement_id)) {
                return $movement_id;
            }
            if ($movement_id > 0) {
                $filters['movement_id'] = $movement_id;
            }
        }
        foreach (array('source' => 'source_type', 'reason' => 'reason_code') as $alias => $column) {
            $value = $this->inventory_movement_alias_code($request, $alias, $column);
            if (is_wp_error($value)) {
                return $value;
            }
            if ($value !== '') {
                $filters[$column] = $value;
            }
        }
        $stock_effect = $this->inventory_movement_code($request->get_param('stock_effect'), 'stock_effect');
        if (is_wp_error($stock_effect)) {
            return $stock_effect;
        }
        if ($stock_effect !== '') {
            $filters['stock_effect'] = $stock_effect;
        }
        foreach (array('date_from', 'date_to') as $field) {
            $value = $this->inventory_restock_datetime($request->get_param($field), $field);
            if (is_wp_error($value)) {
                return $value;
            }
            if ($value !== null) {
                $filters[$field] = $value;
            }
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            return new WP_Error('mgws_bad_request', 'date_from must not be after date_to', array('status' => 400));
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0) {
            $filters['site_id'] = $site_limit;
        }
        return $filters;
    }

    private function inventory_movement_alias_int(WP_REST_Request $request, $alias, $field) {
        $alias_value = $this->inventory_non_negative($request->get_param($alias) ?? 0, $alias);
        $field_value = $this->inventory_non_negative($request->get_param($field) ?? 0, $field);
        if (is_wp_error($alias_value) || is_wp_error($field_value)) {
            return is_wp_error($alias_value) ? $alias_value : $field_value;
        }
        if ($alias_value > 0 && $field_value > 0 && $alias_value !== $field_value) {
            return new WP_Error('mgws_bad_request', $alias . ' and ' . $field . ' must match', array('status' => 400));
        }
        return max($alias_value, $field_value);
    }

    private function inventory_movement_alias_code(WP_REST_Request $request, $alias, $field) {
        $alias_value = $this->inventory_movement_code($request->get_param($alias), $alias);
        $field_value = $this->inventory_movement_code($request->get_param($field), $field);
        if (is_wp_error($alias_value) || is_wp_error($field_value)) {
            return is_wp_error($alias_value) ? $alias_value : $field_value;
        }
        if ($alias_value !== '' && $field_value !== '' && $alias_value !== $field_value) {
            return new WP_Error('mgws_bad_request', $alias . ' and ' . $field . ' must match', array('status' => 400));
        }
        return $alias_value !== '' ? $alias_value : $field_value;
    }

    private function inventory_movement_code($value, $field) {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_scalar($value)) {
            return new WP_Error('mgws_bad_request', $field . ' must be a valid code', array('status' => 400));
        }
        $code = (string) $value;
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            return new WP_Error('mgws_bad_request', $field . ' must be a valid code', array('status' => 400));
        }
        return $code;
    }

    private function inventory_movement_response($movement) {
        $note = (string) ($movement['note'] ?? '');
        // Stock prima e dopo stanno in colonne e si leggono da li'. La regex
        // sulla nota resta come ripiego per le righe scritte prima che le colonne
        // esistessero: meglio un numero forse vecchio che nessun numero, e non
        // vale la pena perdere lo storico per un campo aggiunto dopo. La nota
        // della rettifica scrive "total", quella del carico "stock": senza il
        // secondo termine le rettifiche storiche resterebbero senza numeri.
        $stock_before = array_key_exists('stock_before', $movement) ? $movement['stock_before'] : null;
        $stock_after = array_key_exists('stock_after', $movement) ? $movement['stock_after'] : null;
        if ($stock_before === null || $stock_after === null) {
            if (preg_match('/\\b(?:stock|total)\\s+(-?[0-9]+)\\s+->\\s+(-?[0-9]+)/i', $note, $matches) === 1) {
                $stock_before = $stock_before === null ? (int) $matches[1] : $stock_before;
                $stock_after = $stock_after === null ? (int) $matches[2] : $stock_after;
            }
        }
        $stock_before = $stock_before === null ? null : (int) $stock_before;
        $stock_after = $stock_after === null ? null : (int) $stock_after;
        $source_type = (string) ($movement['source_type'] ?? '');
        $source_id = (int) ($movement['source_id'] ?? 0);
        $source_line_id = (int) ($movement['source_line_id'] ?? 0);
        $order_id = (int) ($movement['ref_order_id'] ?? 0);
        return array(
            'id' => (int) ($movement['id'] ?? 0),
            'movement_id' => (int) ($movement['movement_id'] ?? 0),
            'occurred_at_gmt' => (string) ($movement['ts_gmt'] ?? ''),
            'type' => (string) ($movement['type'] ?? ''),
            'stock_effect' => (string) ($movement['stock_effect'] ?? ''),
            'product_id' => (int) ($movement['product_id'] ?? 0),
            'variation_id' => (int) ($movement['variation_id'] ?? 0),
            'quantity_delta' => (int) ($movement['qty'] ?? 0),
            'stock_before' => $stock_before,
            'stock_after' => $stock_after,
            'location' => array('site_id' => (int) ($movement['site_id'] ?? 0), 'warehouse_id' => (int) ($movement['warehouse_id'] ?? 0), 'room' => (string) ($movement['room'] ?? ''), 'rack' => (string) ($movement['rack'] ?? ''), 'shelf' => (string) ($movement['shelf'] ?? '')),
            // Lo spostamento scrive due righe per prodotto, una di uscita e una
            // di entrata. Senza questi due campi la rotta si perde: la riga sa
            // solo dove e finita la merce, non da dove e partita.
            'warehouse_from' => (int) ($movement['warehouse_from'] ?? 0),
            'warehouse_to' => (int) ($movement['warehouse_to'] ?? 0),
            'operator_user_id' => (int) ($movement['user_id'] ?? 0),
            'reason_code' => (string) ($movement['reason_code'] ?? ''),
            'note' => $note,
            'source' => array(
                'type' => $source_type,
                'id' => $source_id,
                'line_id' => $source_line_id,
                'links' => array(
                    'receipt_id' => $source_type === 'receipt' ? $source_id : 0,
                    'receipt_line_id' => $source_type === 'receipt' ? $source_line_id : 0,
                    'inventory_count_session_id' => $source_type === 'inventory_count' ? $source_id : 0,
                    'inventory_count_line_id' => $source_type === 'inventory_count' ? $source_line_id : 0,
                    'order_id' => $order_id,
                ),
            ),
        );
    }

    private function inventory_idempotency_response($idempotency_key, $payload_hash, $record, $operation) {
        if (!is_array($record) || !hash_equals((string) ($record['payload_hash'] ?? ''), (string) $payload_hash)) {
            return new WP_Error('mgws_idempotency_conflict', 'Idempotency key is already associated with a different inventory operation', array('status' => 409));
        }
        $status = (string) ($record['status'] ?? '');
        if ($status === 'succeeded') {
            $response = json_decode((string) ($record['response_json'] ?? ''), true);
            if (!is_array($response)) {
                return new WP_Error('mgws_idempotency_recovery_required', 'Stored inventory result is unavailable', array('status' => 409));
            }
            $response['idempotency'] = array('key' => $idempotency_key, 'replayed' => true);
            return rest_ensure_response($response);
        }
        if ($status === 'failed') {
            return new WP_Error('mgws_' . sanitize_key($operation) . '_failed', 'Previous inventory operation failed', array('status' => 409));
        }
        return new WP_Error('mgws_idempotency_in_progress', 'Inventory operation with this idempotency key is already in progress', array('status' => 409));
    }

    public function route_inventory_quick_load(WP_REST_Request $request) {
        global $wpdb;

        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $idempotency_key = $this->inventory_restock_text($body['idempotency_key'] ?? null, 'idempotency_key', 191, true);
        $product_id = $this->inventory_positive($body['product_id'] ?? null, 'product_id');
        $variation_id = $this->inventory_non_negative($body['variation_id'] ?? 0, 'variation_id');
        $quantity_delta = $this->inventory_positive($body['quantity_delta'] ?? null, 'quantity_delta');
        $reason = $this->inventory_restock_text($body['reason'] ?? null, 'reason', 4000, true, true);
        $note = $this->inventory_restock_text($body['note'] ?? '', 'note', 4000, false, true);
        $barcode = $this->inventory_restock_text($body['barcode'] ?? '', 'barcode', 191);
        if (is_wp_error($idempotency_key) || is_wp_error($product_id) || is_wp_error($variation_id) || is_wp_error($quantity_delta) || is_wp_error($reason) || is_wp_error($note) || is_wp_error($barcode)) {
            return is_wp_error($idempotency_key) ? $idempotency_key : (is_wp_error($product_id) ? $product_id : (is_wp_error($variation_id) ? $variation_id : (is_wp_error($quantity_delta) ? $quantity_delta : (is_wp_error($reason) ? $reason : (is_wp_error($note) ? $note : $barcode)))));
        }
        $product = $this->inventory_product($product_id, $variation_id);
        if (is_wp_error($product)) {
            return $product;
        }
        if ($product === null) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }
        $location = $this->inventory_quick_load_location($body, $product_id, $variation_id);
        if (is_wp_error($location)) {
            return $location;
        }

        $payload_hash = hash('sha256', (string) wp_json_encode($body));
        $existing = MGWS_DB::get_pos_idempotency($idempotency_key);
        if (is_array($existing)) {
            return $this->inventory_idempotency_response($idempotency_key, $payload_hash, $existing, 'quick_load');
        }
        $reservation = MGWS_DB::reserve_pos_idempotency($idempotency_key, $payload_hash, get_current_user_id());
        if (($reservation['state'] ?? '') !== 'reserved') {
            return $this->inventory_idempotency_response($idempotency_key, $payload_hash, $reservation['record'] ?? array(), 'quick_load');
        }
        $fail = function (WP_Error $error) use ($idempotency_key, $payload_hash): WP_Error {
            MGWS_DB::mark_pos_idempotency_failed($idempotency_key, $payload_hash, 0, (int) ($error->get_error_data()['status'] ?? 409), $error->get_error_code(), $error->get_error_message(), 'retry_blocked');
            return $error;
        };

        $wpdb->query('START TRANSACTION');
        $previous_stock = MGWS_DB::sum_qty_for_item($product_id, $variation_id);
        $applied = MGWS_DB::apply_delta_level((int) $location['warehouse_id'], $product_id, $variation_id, $quantity_delta, (string) $location['room'], (string) $location['rack'], (string) $location['shelf']);
        if (empty($applied['ok'])) {
            $wpdb->query('ROLLBACK');
            return $fail(new WP_Error('mgws_conflict', 'Unable to apply quick load', array('status' => 409)));
        }
        $current_stock = MGWS_DB::sum_qty_for_item($product_id, $variation_id);
        $audit_note = 'Quick load: ' . $reason . '; stock ' . $previous_stock . ' -> ' . $current_stock . ' (delta ' . $quantity_delta . ')';
        if ($note !== '') {
            $audit_note .= '; note: ' . $note;
        }
        if ($barcode !== '') {
            $audit_note .= '; barcode: ' . $barcode;
        }
        // Un carico e' un'operazione sola anche se l'app la invia riga per riga:
        // la stessa chiave su ogni riga e' cio' che la tiene insieme.
        $operation_key = $this->inventory_movement_key($body);
        if (is_wp_error($operation_key)) {
            $wpdb->query('ROLLBACK');
            return $fail($operation_key);
        }
        $operation_id = $operation_key === ''
            ? 0
            : MGWS_DB::ensure_movement($operation_key, 'quick_load', get_current_user_id(), (int) $location['site_id'], 'quick_load', $note !== '' ? $note : null);
        $row_id = MGWS_DB::insert_quick_load_move((int) $location['site_id'], (int) $location['warehouse_id'], $product_id, $variation_id, $quantity_delta, (string) $location['room'], (string) $location['rack'], (string) $location['shelf'], get_current_user_id(), $audit_note, $operation_id, (int) $previous_stock, (int) $current_stock);
        if ($row_id <= 0) {
            $wpdb->query('ROLLBACK');
            return $fail(new WP_Error('mgws_error', 'Unable to record quick load movement', array('status' => 500)));
        }
        $wpdb->query('COMMIT');
        $this->sync_woo_stock($product_id, $variation_id);

        $response = array(
            'ok' => true,
            'operation' => 'quick_load',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'quantity_delta' => $quantity_delta,
            'previous_stock' => $previous_stock,
            'current_stock' => $current_stock,
            'reason' => $reason,
            'movement_id' => (int) $operation_id,
            'ledger_movement_id' => $row_id,
            'location' => $location,
        );
        if (!MGWS_DB::mark_pos_idempotency_succeeded($idempotency_key, $payload_hash, $row_id, 200, $response)) {
            return new WP_Error('mgws_idempotency_recovery_required', 'Inventory operation result could not be saved', array('status' => 409));
        }
        return rest_ensure_response($response);
    }

    private function inventory_quick_load_location($body, $product_id, $variation_id) {
        $requested_site_id = $this->inventory_non_negative($body['site_id'] ?? 0, 'site_id');
        if (is_wp_error($requested_site_id)) {
            return $requested_site_id;
        }
        $warehouse_id = $this->inventory_non_negative($body['warehouse_id'] ?? 0, 'warehouse_id');
        if (is_wp_error($warehouse_id)) {
            return $warehouse_id;
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && $requested_site_id > 0 && $requested_site_id !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Site outside allowed scope', array('status' => 403));
        }
        $effective_site = $site_limit > 0 ? $site_limit : (int) $requested_site_id;
        $location = $this->resolve_pos_return_location($product_id, $variation_id, $effective_site);
        if ($warehouse_id > 0) {
            $location['warehouse_id'] = $warehouse_id;
            $location['site_id'] = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
            foreach (MGWS_DB::get_levels_for_item($product_id, $variation_id, $effective_site) as $level) {
                if ((int) ($level['warehouse_id'] ?? 0) === $warehouse_id) {
                    $location = array(
                        'site_id' => (int) ($level['site_id'] ?? 0),
                        'warehouse_id' => $warehouse_id,
                        'room' => (string) ($level['room'] ?? ''),
                        'rack' => (string) ($level['rack'] ?? ''),
                        'shelf' => (string) ($level['shelf'] ?? ''),
                    );
                    break;
                }
            }
        }
        foreach (array('room', 'rack', 'shelf') as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $this->inventory_restock_text($body[$field], $field, 80);
            if (is_wp_error($value)) {
                return $value;
            }
            $location[$field] = $value;
        }
        $site_id = (int) ($location['site_id'] ?? 0);
        $warehouse_id = (int) ($location['warehouse_id'] ?? 0);
        if ($warehouse_id <= 0 || $site_id <= 0) {
            return new WP_Error('mgws_conflict', 'Unable to resolve quick-load location', array('status' => 409));
        }
        if ($requested_site_id > 0 && $site_id !== (int) $requested_site_id) {
            return new WP_Error('mgws_conflict', 'Warehouse outside requested site', array('status' => 409));
        }
        if ($site_limit > 0 && $site_id !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Warehouse outside allowed site', array('status' => 403));
        }
        return array(
            'site_id' => $site_id,
            'warehouse_id' => $warehouse_id,
            'room' => (string) ($location['room'] ?? ''),
            'rack' => (string) ($location['rack'] ?? ''),
            'shelf' => (string) ($location['shelf'] ?? ''),
        );
    }

    private function inventory_count_route_args($route, $method) {
        if ($route === '/inventory/quick-load') {
            return array(
                'product_id' => $this->inventory_positive_int_arg(),
                'variation_id' => $this->inventory_non_negative_int_arg(0),
                'quantity_delta' => $this->inventory_positive_int_arg(),
                'site_id' => $this->inventory_positive_int_arg(),
                'idempotency_key' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => array($this, 'validate_inventory_text')),
                'reason' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'validate_callback' => array($this, 'validate_inventory_text')),
                'note' => array('required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'validate_callback' => array($this, 'validate_inventory_text')),
                'barcode' => $this->inventory_text_arg(),
                'warehouse_id' => $this->inventory_non_negative_int_arg(0),
                'room' => $this->inventory_text_arg(),
                'rack' => $this->inventory_text_arg(),
                'shelf' => $this->inventory_text_arg(),
                // Facoltativa, come nelle altre due rotte di scrittura: tiene
                // insieme le righe di un carico multi-prodotto. Un carico senza
                // chiave resta un movimento per riga, che e' il comportamento di
                // prima e resta valido per i flussi interni del plugin.
                'movement_key' => $this->inventory_movement_key_arg(),
            );
        }
        if ($route === '/inventory/count-sessions') {
            return $method === 'GET' ? $this->inventory_read_args() : array(
                'site_id' => $this->inventory_positive_int_arg(),
                'warehouse_id' => $this->inventory_positive_int_arg(),
                'document_number' => $this->inventory_text_arg(),
                'notes' => $this->inventory_text_arg(),
            );
        }
        if ($route === '/inventory/receipts') {
            return $method === 'GET' ? $this->inventory_read_args() : array(
                'site_id' => $this->inventory_positive_int_arg(),
                'purchase_order_id' => $this->inventory_positive_int_arg(),
                'document_number' => $this->inventory_text_arg(),
                'idempotency_key' => $this->inventory_text_arg(),
                'lines' => array('required' => true, 'type' => 'array'),
            );
        }
        if ($route === '/inventory/backorders') {
            return $this->inventory_read_args();
        }
        if ($route === '/inventory/movements') {
            return array(
                'product_id' => array('required' => false, 'type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_inventory_integer'), 'validate_callback' => array($this, 'validate_inventory_positive_int')),
                'variation_id' => array('required' => false, 'type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_inventory_integer'), 'validate_callback' => array($this, 'validate_inventory_non_negative_int')),
                'date_from' => $this->inventory_text_arg(),
                'date_to' => $this->inventory_text_arg(),
                'source' => $this->inventory_text_arg(),
                'source_type' => $this->inventory_text_arg(),
                'operator' => $this->inventory_non_negative_int_arg(0),
                'user_id' => $this->inventory_non_negative_int_arg(0),
                'reason' => $this->inventory_text_arg(),
                'reason_code' => $this->inventory_text_arg(),
                'stock_effect' => $this->inventory_text_arg(),
                'page' => $this->inventory_positive_int_arg(1),
                'per_page' => $this->inventory_positive_int_arg(50),
            );
        }
        if ($route === '/inventory/movements/(?P<movement_id>\\d+)') {
            return array('movement_id' => $this->inventory_positive_int_arg());
        }
        if (str_starts_with($route, '/inventory/receipts/')) {
            return array('receipt_id' => $this->inventory_positive_int_arg());
        }
        if (!str_starts_with($route, '/inventory/count-sessions/')) {
            return $this->empty_route_args();
        }
        $args = array('session_id' => $this->inventory_positive_int_arg());
        if ($method === 'PATCH') {
            return array_merge($args, array('document_number' => $this->inventory_text_arg(), 'notes' => $this->inventory_text_arg()));
        }
        if (str_ends_with($route, '/lines')) {
            return array_merge($args, array(
                'physical_qty' => $this->inventory_non_negative_int_arg(),
            ));
        }
        return $args;
    }

    private function require_inventory_restock_storage() {
        if (!MGWS_DB::inventory_restock_tables_exist()) {
            return new WP_Error('mgws_unavailable', 'Inventory restock storage is unavailable', array('status' => 503));
        }
        return true;
    }

    private function inventory_restock_site($value, $required = false) {
        $site_id = $this->inventory_non_negative($value ?? 0, 'site_id');
        if (is_wp_error($site_id)) {
            return $site_id;
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && $site_id > 0 && $site_id !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Site outside allowed scope', array('status' => 403));
        }
        $site_id = $site_id > 0 ? $site_id : $site_limit;
        if ($required && $site_id <= 0) {
            return new WP_Error('mgws_bad_request', 'site_id must be a positive integer', array('status' => 400));
        }
        return $site_id;
    }

    private function inventory_restock_row_scope($row) {
        if (!is_array($row)) {
            return new WP_Error('mgws_not_found', 'Record not found', array('status' => 404));
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && array_key_exists('site_id', $row) && (int) ($row['site_id'] ?? 0) !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Site outside allowed scope', array('status' => 403));
        }
        return true;
    }

    private function inventory_restock_text($value, $field, $max_length, $required = false, $textarea = false) {
        if ($value === null && !$required) {
            return '';
        }
        if (!is_scalar($value)) {
            return new WP_Error('mgws_bad_request', $field . ' must be valid text', array('status' => 400));
        }
        $text = trim($textarea ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value));
        if (($required && $text === '') || strlen($text) > $max_length) {
            return new WP_Error('mgws_bad_request', $field . ' must be valid text', array('status' => 400));
        }
        return $text;
    }

    private function inventory_restock_code($value, $field, $max_length = 64) {
        $code = $this->inventory_restock_text($value, $field, $max_length, true);
        if (is_wp_error($code)) {
            return $code;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $code) !== 1) {
            return new WP_Error('mgws_bad_request', $field . ' must be a valid code', array('status' => 400));
        }
        return $code;
    }

    /**
     * Valida la chiave che raggruppa le righe di una stessa operazione.
     *
     * La app invia una richiesta per riga di prodotto e ognuna porta la stessa
     * chiave: e' cosi' che il backend ricava l'operazione senza pretendere un
     * invio in blocco. Opzionale perche' i flussi interni del plugin scrivono il
     * libro senza passare da qui, e in quel caso la riga resta senza movimento.
     */
    private function inventory_movement_key($body) {
        $key = $this->inventory_restock_text($body['movement_key'] ?? '', 'movement_key', 64);
        if (is_wp_error($key)) {
            return $key;
        }
        if ($key === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $key) !== 1) {
            return new WP_Error('mgws_bad_request', 'movement_key must be alphanumeric', array('status' => 400));
        }
        return $key;
    }

    private function inventory_restock_boolean($value, $field) {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }
        return new WP_Error('mgws_bad_request', $field . ' must be boolean', array('status' => 400));
    }

    private function inventory_restock_datetime($value, $field) {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            return new WP_Error('mgws_bad_request', $field . ' must be a UTC date-time', array('status' => 400));
        }
        $raw = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return new WP_Error('mgws_bad_request', $field . ' must be a UTC date-time', array('status' => 400));
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function inventory_restock_unit_cost($value) {
        if (!is_scalar($value) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/', (string) $value) !== 1) {
            return new WP_Error('mgws_bad_request', 'unit_cost must be a non-negative decimal', array('status' => 400));
        }
        return number_format((float) $value, 4, '.', '');
    }

    private function inventory_supplier_response($row) {
        return array(
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'tax_id' => (string) ($row['tax_id'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'active' => !empty($row['active']),
            'notes' => (string) ($row['notes'] ?? ''),
            'payment_terms_days' => (int) ($row['payment_terms_days'] ?? 0),
            'iban' => (string) ($row['iban'] ?? ''),
            'lead_time_days' => (int) ($row['lead_time_days'] ?? 0),
            'created_by_user_id' => (int) ($row['created_by_user_id'] ?? 0),
            'updated_by_user_id' => (int) ($row['updated_by_user_id'] ?? 0),
            'created_at_gmt' => (string) ($row['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($row['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_supplier_payload($body, $partial = false) {
        $data = array();
        foreach (array('name' => 191, 'tax_id' => 64, 'phone' => 64, 'iban' => 64) as $field => $max_length) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $this->inventory_restock_text($body[$field], $field, $max_length, !$partial && $field === 'name');
            if (is_wp_error($value)) {
                return $value;
            }
            $data[$field] = $value;
        }
        if (!$partial && !array_key_exists('name', $data)) {
            return new WP_Error('mgws_bad_request', 'name is required', array('status' => 400));
        }
        if (array_key_exists('email', $body)) {
            $email = trim((string) $body['email']);
            // L'email e' facoltativa, ma se arriva deve essere un indirizzo:
            // `sanitize_email` ripulisce senza validare, quindi da solo
            // accetterebbe anche spazzatura tipo "not-an-email".
            if ($email !== '' && !is_email($email)) {
                return new WP_Error('mgws_bad_request', 'email must be valid', array('status' => 400));
            }
            $data['email'] = $email;
        }
        if (array_key_exists('notes', $body)) {
            $notes = $this->inventory_restock_text($body['notes'], 'notes', 4000, false, true);
            if (is_wp_error($notes)) {
                return $notes;
            }
            $data['notes'] = $notes;
        }
        foreach (array('payment_terms_days', 'lead_time_days') as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $this->inventory_non_negative($body[$field], $field);
            if (is_wp_error($value)) {
                return $value;
            }
            $data[$field] = $value;
        }
        if (array_key_exists('active', $body)) {
            $active = $this->inventory_restock_boolean($body['active'], 'active');
            if (is_wp_error($active)) {
                return $active;
            }
            $data['active'] = $active ? 1 : 0;
        }
        return $data;
    }

    private function inventory_reorder_rule_response($row) {
        return array(
            'id' => (int) ($row['id'] ?? 0),
            'site_id' => (int) ($row['site_id'] ?? 0),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'variation_id' => (int) ($row['variation_id'] ?? 0),
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'reorder_point' => (int) ($row['reorder_point'] ?? 0),
            'target_stock' => (int) ($row['target_stock'] ?? 0),
            'reorder_quantity' => (int) ($row['reorder_quantity'] ?? 0),
            'lead_time_days' => (int) ($row['lead_time_days'] ?? 0),
            'safety_days' => (int) ($row['safety_days'] ?? 0),
            'active' => !empty($row['active']),
            'created_at_gmt' => (string) ($row['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($row['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_purchase_order_line_response($row) {
        return array(
            'id' => (int) ($row['id'] ?? 0),
            'purchase_order_id' => (int) ($row['purchase_order_id'] ?? 0),
            'line_number' => (int) ($row['line_number'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'variation_id' => (int) ($row['variation_id'] ?? 0),
            'ordered_qty' => (int) ($row['ordered_qty'] ?? 0),
            'received_qty' => (int) ($row['received_qty'] ?? 0),
            'cancelled_qty' => (int) ($row['cancelled_qty'] ?? 0),
            'unit_cost' => number_format((float) ($row['unit_cost'] ?? 0), 4, '.', ''),
            'supplier_sku' => (string) ($row['supplier_sku'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'expected_at_gmt' => $row['expected_at_gmt'] === null || ($row['expected_at_gmt'] ?? '') === '' ? null : (string) $row['expected_at_gmt'],
            'stock_effect' => 'incoming',
            'created_at_gmt' => (string) ($row['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($row['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_purchase_order_response($row, $include_lines = true) {
        $response = array(
            'id' => (int) ($row['id'] ?? 0),
            'site_id' => (int) ($row['site_id'] ?? 0),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'document_number' => (string) ($row['document_number'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'ordered_at_gmt' => $row['ordered_at_gmt'] === null || ($row['ordered_at_gmt'] ?? '') === '' ? null : (string) $row['ordered_at_gmt'],
            'expected_at_gmt' => $row['expected_at_gmt'] === null || ($row['expected_at_gmt'] ?? '') === '' ? null : (string) $row['expected_at_gmt'],
            'currency' => (string) ($row['currency'] ?? 'EUR'),
            'notes' => (string) ($row['notes'] ?? ''),
            'created_by_user_id' => (int) ($row['created_by_user_id'] ?? 0),
            'updated_by_user_id' => (int) ($row['updated_by_user_id'] ?? 0),
            'created_at_gmt' => (string) ($row['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($row['updated_at_gmt'] ?? ''),
        );
        if ($include_lines) {
            $response['lines'] = array_map(array($this, 'inventory_purchase_order_line_response'), MGWS_DB::list_purchase_order_lines((int) $response['id']));
        }
        return $response;
    }

    public function route_inventory_suppliers_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        // I fornitori sono globali: nessuna sede, quindi nessuno scope da
        // applicare e nessun 403 per un operatore limitato a una sola sede.
        return rest_ensure_response(array_map(array($this, 'inventory_supplier_response'), MGWS_DB::list_suppliers()));
    }

    public function route_inventory_suppliers_create(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $data = $this->inventory_supplier_payload($body, false);
        if (is_wp_error($data)) {
            return $data;
        }
        $supplier = MGWS_DB::create_supplier(array_merge($data, array(
            'created_by_user_id' => (int) get_current_user_id(),
            'updated_by_user_id' => (int) get_current_user_id(),
        )));
        if (!is_array($supplier)) {
            return new WP_Error('mgws_supplier_conflict', 'Supplier could not be created', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_supplier_response($supplier));
    }

    public function route_inventory_supplier_get(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $supplier = MGWS_DB::get_supplier((int) $request->get_param('supplier_id'));
        if (!is_array($supplier)) {
            return new WP_Error('mgws_supplier_not_found', 'Supplier not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($supplier);
        return is_wp_error($scope) ? $scope : rest_ensure_response($this->inventory_supplier_response($supplier));
    }

    public function route_inventory_supplier_update(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $supplier = MGWS_DB::get_supplier((int) $request->get_param('supplier_id'));
        if (!is_array($supplier)) {
            return new WP_Error('mgws_supplier_not_found', 'Supplier not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($supplier);
        if (is_wp_error($scope)) {
            return $scope;
        }
        $body = $this->inventory_body($request);
        $data = $this->inventory_supplier_payload($body, true);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data)) {
            return new WP_Error('mgws_bad_request', 'No supplier changes provided', array('status' => 400));
        }
        $data['updated_by_user_id'] = (int) get_current_user_id();
        $updated = MGWS_DB::update_supplier((int) $supplier['id'], $data);
        if (!is_array($updated)) {
            return new WP_Error('mgws_supplier_conflict', 'Supplier could not be updated', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_supplier_response($updated));
    }

    public function route_inventory_supplier_delete(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $supplier = MGWS_DB::get_supplier((int) $request->get_param('supplier_id'));
        if (!is_array($supplier)) {
            return new WP_Error('mgws_supplier_not_found', 'Supplier not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($supplier);
        if (is_wp_error($scope)) {
            return $scope;
        }
        if (MGWS_DB::supplier_in_use((int) $supplier['id'])) {
            return new WP_Error('mgws_supplier_in_use', 'Supplier is referenced by inventory records and cannot be deleted', array('status' => 409));
        }
        if (!MGWS_DB::delete_supplier((int) $supplier['id'])) {
            return new WP_Error('mgws_error', 'Supplier could not be deleted', array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'deleted' => false, 'supplier_id' => (int) $supplier['id']));
    }

    private function inventory_restock_supplier($supplier_id, $site_id, $require_active = true) {
        $supplier_id = $this->inventory_non_negative($supplier_id, 'supplier_id');
        if (is_wp_error($supplier_id)) {
            return $supplier_id;
        }
        if ($supplier_id === 0) {
            return null;
        }
        $supplier = MGWS_DB::get_supplier($supplier_id);
        if (!is_array($supplier)) {
            return new WP_Error('mgws_supplier_not_found', 'Supplier not found', array('status' => 404));
        }
        if ($require_active && empty($supplier['active'])) {
            return new WP_Error('mgws_supplier_inactive', 'Supplier is inactive', array('status' => 409));
        }
        return $supplier;
    }

    private function inventory_restock_reorder_data($body, $existing = null) {
        $base = is_array($existing) ? $existing : array();
        $required = !is_array($existing);
        $data = array();
        foreach (array('warehouse_id', 'product_id', 'variation_id', 'supplier_id', 'reorder_point', 'target_stock', 'reorder_quantity', 'lead_time_days', 'safety_days') as $field) {
            if (!array_key_exists($field, $body) && !$required) {
                continue;
            }
            $value = array_key_exists($field, $body) ? $body[$field] : ($base[$field] ?? null);
            $validated = $field === 'product_id' ? $this->inventory_positive($value, $field) : $this->inventory_non_negative($value, $field);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $data[$field] = $validated;
        }
        if (array_key_exists('active', $body)) {
            $active = $this->inventory_restock_boolean($body['active'], 'active');
            if (is_wp_error($active)) {
                return $active;
            }
            $data['active'] = $active ? 1 : 0;
        } elseif ($required) {
            $data['active'] = 1;
        }
        $merged = array_merge($base, $data);
        if ((int) ($merged['target_stock'] ?? 0) < (int) ($merged['reorder_point'] ?? 0)) {
            return new WP_Error('mgws_bad_request', 'target_stock must be at least reorder_point', array('status' => 400));
        }
        if ((int) ($merged['reorder_quantity'] ?? 0) === 0 && (int) ($merged['target_stock'] ?? 0) <= (int) ($merged['reorder_point'] ?? 0)) {
            return new WP_Error('mgws_bad_request', 'Reorder rule must define an actionable quantity', array('status' => 400));
        }
        if ($required || array_key_exists('product_id', $data) || array_key_exists('variation_id', $data)) {
            $product = $this->inventory_product((int) $merged['product_id'], (int) $merged['variation_id']);
            if (is_wp_error($product)) {
                return $product;
            }
        }
        return $data;
    }

    public function route_inventory_reorder_rules_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        $warehouse_id = $this->inventory_non_negative($request->get_param('warehouse_id') ?? 0, 'warehouse_id');
        if (is_wp_error($site_id) || is_wp_error($warehouse_id)) {
            return is_wp_error($site_id) ? $site_id : $warehouse_id;
        }
        return rest_ensure_response(array_map(array($this, 'inventory_reorder_rule_response'), MGWS_DB::list_reorder_rules($site_id, $warehouse_id)));
    }

    public function route_inventory_reorder_rules_create(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $site_id = $this->inventory_restock_site($body['site_id'] ?? null, true);
        if (is_wp_error($site_id)) {
            return $site_id;
        }
        $data = $this->inventory_restock_reorder_data($body);
        if (is_wp_error($data)) {
            return $data;
        }
        $supplier = $this->inventory_restock_supplier($data['supplier_id'], $site_id);
        if (is_wp_error($supplier)) {
            return $supplier;
        }
        $rule = MGWS_DB::create_reorder_rule(array_merge($data, array('site_id' => $site_id)));
        if (!is_array($rule)) {
            return new WP_Error('mgws_reorder_rule_conflict', 'A reorder rule already exists for this item', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_reorder_rule_response($rule));
    }

    public function route_inventory_reorder_rule_update(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $rule = MGWS_DB::get_reorder_rule((int) $request->get_param('rule_id'));
        if (!is_array($rule)) {
            return new WP_Error('mgws_reorder_rule_not_found', 'Reorder rule not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($rule);
        if (is_wp_error($scope)) {
            return $scope;
        }
        $data = $this->inventory_restock_reorder_data($this->inventory_body($request), $rule);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data)) {
            return new WP_Error('mgws_bad_request', 'No reorder rule changes provided', array('status' => 400));
        }
        $supplier_id = array_key_exists('supplier_id', $data) ? $data['supplier_id'] : (int) $rule['supplier_id'];
        $supplier = $this->inventory_restock_supplier($supplier_id, (int) $rule['site_id']);
        if (is_wp_error($supplier)) {
            return $supplier;
        }
        $updated = MGWS_DB::update_reorder_rule((int) $rule['id'], $data);
        if (!is_array($updated)) {
            return new WP_Error('mgws_reorder_rule_conflict', 'Reorder rule could not be updated', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_reorder_rule_response($updated));
    }

    public function route_inventory_reorder_rule_delete(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $rule = MGWS_DB::get_reorder_rule((int) $request->get_param('rule_id'));
        if (!is_array($rule)) {
            return new WP_Error('mgws_reorder_rule_not_found', 'Reorder rule not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($rule);
        if (is_wp_error($scope)) {
            return $scope;
        }
        if (!MGWS_DB::delete_reorder_rule((int) $rule['id'])) {
            return new WP_Error('mgws_error', 'Reorder rule could not be deleted', array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'deleted' => true, 'rule_id' => (int) $rule['id']));
    }

    public function route_inventory_reorder_suggestions(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        $warehouse_id = $this->inventory_non_negative($request->get_param('warehouse_id') ?? 0, 'warehouse_id');
        if (is_wp_error($site_id) || is_wp_error($warehouse_id)) {
            return is_wp_error($site_id) ? $site_id : $warehouse_id;
        }
        $suggestions = array();
        foreach (MGWS_DB::list_reorder_rules($site_id, $warehouse_id) as $rule) {
            if (empty($rule['active'])) {
                continue;
            }
            $current_stock = MGWS_DB::reorder_rule_current_stock($rule);
            $reorder_point = (int) $rule['reorder_point'];
            if ($current_stock > $reorder_point) {
                continue;
            }
            $suggested_qty = (int) $rule['reorder_quantity'] > 0 ? (int) $rule['reorder_quantity'] : (int) $rule['target_stock'] - $current_stock;
            if ($suggested_qty <= 0) {
                continue;
            }
            $suggestions[] = array(
                'rule_id' => (int) $rule['id'],
                'site_id' => (int) $rule['site_id'],
                'warehouse_id' => (int) $rule['warehouse_id'],
                'supplier_id' => (int) $rule['supplier_id'],
                'product_id' => (int) $rule['product_id'],
                'variation_id' => (int) $rule['variation_id'],
                'current_stock' => $current_stock,
                'reorder_point' => $reorder_point,
                'target_stock' => (int) $rule['target_stock'],
                'reorder_quantity' => (int) $rule['reorder_quantity'],
                'suggested_qty' => $suggested_qty,
                'lead_time_days' => (int) $rule['lead_time_days'],
                'safety_days' => (int) $rule['safety_days'],
            );
        }
        usort($suggestions, static function ($left, $right) {
            return array($left['current_stock'], $left['product_id'], $left['variation_id'], $left['rule_id']) <=> array($right['current_stock'], $right['product_id'], $right['variation_id'], $right['rule_id']);
        });
        return rest_ensure_response($suggestions);
    }

    private function inventory_restock_purchase_order($purchase_order_id) {
        $purchase_order = MGWS_DB::get_purchase_order((int) $purchase_order_id);
        if (!is_array($purchase_order)) {
            return new WP_Error('mgws_purchase_order_not_found', 'Purchase order not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($purchase_order);
        return is_wp_error($scope) ? $scope : $purchase_order;
    }

    private function inventory_restock_purchase_order_header($body, $existing = null) {
        $base = is_array($existing) ? $existing : array();
        $required = !is_array($existing);
        $data = array();
        if ($required || array_key_exists('warehouse_id', $body)) {
            $warehouse_id = $this->inventory_non_negative($body['warehouse_id'] ?? ($base['warehouse_id'] ?? 0), 'warehouse_id');
            if (is_wp_error($warehouse_id)) {
                return $warehouse_id;
            }
            $data['warehouse_id'] = $warehouse_id;
        }
        if ($required || array_key_exists('supplier_id', $body)) {
            $supplier_id = $this->inventory_positive($body['supplier_id'] ?? ($base['supplier_id'] ?? null), 'supplier_id');
            if (is_wp_error($supplier_id)) {
                return $supplier_id;
            }
            $data['supplier_id'] = $supplier_id;
        }
        if ($required || array_key_exists('document_number', $body)) {
            $document_number = $this->inventory_restock_code($body['document_number'] ?? ($base['document_number'] ?? null), 'document_number');
            if (is_wp_error($document_number)) {
                return $document_number;
            }
            $data['document_number'] = $document_number;
        }
        if (array_key_exists('expected_at_gmt', $body)) {
            $expected_at_gmt = $this->inventory_restock_datetime($body['expected_at_gmt'], 'expected_at_gmt');
            if (is_wp_error($expected_at_gmt)) {
                return $expected_at_gmt;
            }
            $data['expected_at_gmt'] = $expected_at_gmt;
        } elseif ($required) {
            $data['expected_at_gmt'] = null;
        }
        if (array_key_exists('currency', $body) || $required) {
            $currency = strtoupper((string) ($body['currency'] ?? ($base['currency'] ?? (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR'))));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                return new WP_Error('mgws_bad_request', 'currency must be a three-letter code', array('status' => 400));
            }
            $data['currency'] = $currency;
        }
        if (array_key_exists('notes', $body)) {
            $notes = $this->inventory_restock_text($body['notes'], 'notes', 4000, false, true);
            if (is_wp_error($notes)) {
                return $notes;
            }
            $data['notes'] = $notes;
        } elseif ($required) {
            $data['notes'] = '';
        }
        return $data;
    }

    private function inventory_restock_purchase_order_line($body, $existing = null) {
        $base = is_array($existing) ? $existing : array();
        $required = !is_array($existing);
        $data = array();
        foreach (array('product_id', 'variation_id', 'ordered_qty') as $field) {
            if (!array_key_exists($field, $body) && !$required) {
                continue;
            }
            $value = $body[$field] ?? ($base[$field] ?? null);
            $validated = $field === 'variation_id' ? $this->inventory_non_negative($value, $field) : $this->inventory_positive($value, $field);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $data[$field] = $validated;
        }
        $merged = array_merge($base, $data);
        if ($required || array_key_exists('product_id', $data) || array_key_exists('variation_id', $data)) {
            $product = $this->inventory_product((int) $merged['product_id'], (int) $merged['variation_id']);
            if (is_wp_error($product)) {
                return $product;
            }
        }
        if ($required || array_key_exists('unit_cost', $body)) {
            $unit_cost = $this->inventory_restock_unit_cost($body['unit_cost'] ?? ($base['unit_cost'] ?? 0));
            if (is_wp_error($unit_cost)) {
                return $unit_cost;
            }
            $data['unit_cost'] = $unit_cost;
        }
        foreach (array('supplier_sku', 'barcode') as $field) {
            if (!array_key_exists($field, $body) && !$required) {
                continue;
            }
            $value = $this->inventory_restock_text($body[$field] ?? ($base[$field] ?? ''), $field, 191);
            if (is_wp_error($value)) {
                return $value;
            }
            $data[$field] = $value;
        }
        if (array_key_exists('expected_at_gmt', $body) || $required) {
            $expected_at_gmt = $this->inventory_restock_datetime($body['expected_at_gmt'] ?? ($base['expected_at_gmt'] ?? null), 'expected_at_gmt');
            if (is_wp_error($expected_at_gmt)) {
                return $expected_at_gmt;
            }
            $data['expected_at_gmt'] = $expected_at_gmt;
        }
        return $data;
    }

    public function route_inventory_purchase_orders_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        $supplier_id = $this->inventory_non_negative($request->get_param('supplier_id') ?? 0, 'supplier_id');
        $status = $request->get_param('status') ?? '';
        if (is_wp_error($site_id) || is_wp_error($supplier_id) || (!is_scalar($status))) {
            return is_wp_error($site_id) ? $site_id : (is_wp_error($supplier_id) ? $supplier_id : new WP_Error('mgws_bad_request', 'status must be valid text', array('status' => 400)));
        }
        $status = trim(sanitize_text_field((string) $status));
        if ($status !== '' && !in_array($status, array('draft', 'ordered', 'cancelled'), true)) {
            return new WP_Error('mgws_bad_request', 'status must be valid', array('status' => 400));
        }
        return rest_ensure_response(array_map(array($this, 'inventory_purchase_order_response'), MGWS_DB::list_purchase_orders($site_id, $supplier_id, $status)));
    }

    public function route_inventory_purchase_orders_create(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $site_id = $this->inventory_restock_site($body['site_id'] ?? null, true);
        if (is_wp_error($site_id)) {
            return $site_id;
        }
        $data = $this->inventory_restock_purchase_order_header($body);
        if (is_wp_error($data)) {
            return $data;
        }
        $supplier = $this->inventory_restock_supplier($data['supplier_id'], $site_id);
        if (is_wp_error($supplier)) {
            return $supplier;
        }
        $purchase_order = MGWS_DB::create_purchase_order(array_merge($data, array(
            'site_id' => $site_id,
            'status' => 'draft',
            'ordered_at_gmt' => null,
            'created_by_user_id' => get_current_user_id(),
            'updated_by_user_id' => get_current_user_id(),
        )));
        if (!is_array($purchase_order)) {
            return new WP_Error('mgws_purchase_order_conflict', 'Purchase order number already exists for this site', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_purchase_order_response($purchase_order));
    }

    public function route_inventory_purchase_order_get(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $purchase_order = $this->inventory_restock_purchase_order($request->get_param('purchase_order_id'));
        return is_wp_error($purchase_order) ? $purchase_order : rest_ensure_response($this->inventory_purchase_order_response($purchase_order));
    }

    public function route_inventory_purchase_order_update(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $purchase_order = $this->inventory_restock_purchase_order($request->get_param('purchase_order_id'));
        if (is_wp_error($purchase_order)) {
            return $purchase_order;
        }
        if (($purchase_order['status'] ?? '') !== 'draft') {
            return new WP_Error('mgws_purchase_order_not_editable', 'Purchase order is not editable', array('status' => 409));
        }
        $data = $this->inventory_restock_purchase_order_header($this->inventory_body($request), $purchase_order);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data)) {
            return new WP_Error('mgws_bad_request', 'No purchase order changes provided', array('status' => 400));
        }
        $supplier_id = $data['supplier_id'] ?? (int) $purchase_order['supplier_id'];
        $supplier = $this->inventory_restock_supplier($supplier_id, (int) $purchase_order['site_id']);
        if (is_wp_error($supplier)) {
            return $supplier;
        }
        $data['updated_by_user_id'] = get_current_user_id();
        $updated = MGWS_DB::update_purchase_order((int) $purchase_order['id'], $data);
        if (!is_array($updated)) {
            return new WP_Error('mgws_purchase_order_conflict', 'Purchase order could not be updated', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_purchase_order_response($updated));
    }

    public function route_inventory_purchase_order_line_upsert(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $purchase_order = $this->inventory_restock_purchase_order($request->get_param('purchase_order_id'));
        if (is_wp_error($purchase_order)) {
            return $purchase_order;
        }
        $body = $this->inventory_body($request);
        $action = trim(sanitize_text_field((string) ($body['action'] ?? '')));
        $line_id = array_key_exists('line_id', $body) ? $this->inventory_positive($body['line_id'], 'line_id') : 0;
        if (is_wp_error($line_id)) {
            return $line_id;
        }
        if ($action === 'cancel') {
            if (!in_array($purchase_order['status'] ?? '', array('draft', 'ordered'), true)) {
                return new WP_Error('mgws_purchase_order_not_editable', 'Purchase order is not editable', array('status' => 409));
            }
            if ($line_id <= 0) {
                return new WP_Error('mgws_bad_request', 'line_id must be a positive integer', array('status' => 400));
            }
            $line = MGWS_DB::get_purchase_order_line((int) $purchase_order['id'], $line_id);
            if (!is_array($line)) {
                return new WP_Error('mgws_purchase_order_line_not_found', 'Purchase order line not found', array('status' => 404));
            }
            $updated = MGWS_DB::update_purchase_order_line((int) $purchase_order['id'], $line_id, array('cancelled_qty' => max(0, (int) $line['ordered_qty'] - (int) $line['received_qty'])));
            return is_array($updated) ? rest_ensure_response($this->inventory_purchase_order_line_response($updated)) : new WP_Error('mgws_error', 'Purchase order line could not be updated', array('status' => 500));
        }
        if (($purchase_order['status'] ?? '') !== 'draft') {
            return new WP_Error('mgws_purchase_order_not_editable', 'Purchase order is not editable', array('status' => 409));
        }
        $existing = $line_id > 0 ? MGWS_DB::get_purchase_order_line((int) $purchase_order['id'], $line_id) : null;
        if ($line_id > 0 && !is_array($existing)) {
            return new WP_Error('mgws_purchase_order_line_not_found', 'Purchase order line not found', array('status' => 404));
        }
        $data = $this->inventory_restock_purchase_order_line($body, $existing);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!$existing && empty($data)) {
            return new WP_Error('mgws_bad_request', 'Purchase order line is required', array('status' => 400));
        }
        $line = $existing ? MGWS_DB::update_purchase_order_line((int) $purchase_order['id'], $line_id, $data) : MGWS_DB::create_purchase_order_line((int) $purchase_order['id'], $data);
        if (!is_array($line)) {
            return new WP_Error('mgws_error', 'Purchase order line could not be saved', array('status' => 500));
        }
        return rest_ensure_response($this->inventory_purchase_order_line_response($line));
    }

    public function route_inventory_purchase_order_status(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $purchase_order = $this->inventory_restock_purchase_order($request->get_param('purchase_order_id'));
        if (is_wp_error($purchase_order)) {
            return $purchase_order;
        }
        $body = $this->inventory_body($request);
        $status = is_scalar($body['status'] ?? null) ? sanitize_key((string) $body['status']) : '';
        $current = (string) ($purchase_order['status'] ?? '');
        $allowed = array(
            'draft' => array('pending', 'ordered', 'cancelled'),
            'pending' => array('draft', 'ordered', 'cancelled'),
            'ordered' => array('partially_received', 'closed', 'cancelled'),
            'partially_received' => array('closed', 'cancelled'),
            'closed' => array(),
            'cancelled' => array(),
        );
        if (!isset($allowed[$current]) || !in_array($status, $allowed[$current], true)) {
            return new WP_Error('mgws_purchase_order_invalid_transition', 'Purchase order status transition is not allowed', array('status' => 409));
        }
        $data = array('status' => $status, 'updated_by_user_id' => get_current_user_id());
        if ($status === 'ordered') {
            $data['ordered_at_gmt'] = gmdate('Y-m-d H:i:s');
        }
        $updated = MGWS_DB::update_purchase_order((int) $purchase_order['id'], $data);
        if (!is_array($updated)) {
            return new WP_Error('mgws_error', 'Purchase order status could not be updated', array('status' => 500));
        }
        return rest_ensure_response($this->inventory_purchase_order_response($updated));
    }

    private function inventory_receipt_response($receipt, $include_lines = true) {
        $response = array(
            'id' => (int) ($receipt['id'] ?? 0),
            'site_id' => (int) ($receipt['site_id'] ?? 0),
            'warehouse_id' => (int) ($receipt['warehouse_id'] ?? 0),
            'purchase_order_id' => (int) ($receipt['purchase_order_id'] ?? 0),
            'supplier_id' => (int) ($receipt['supplier_id'] ?? 0),
            'document_number' => (string) ($receipt['document_number'] ?? ''),
            'status' => (string) ($receipt['status'] ?? 'draft'),
            'received_at_gmt' => $receipt['received_at_gmt'] ?? null,
            'validated_at_gmt' => $receipt['validated_at_gmt'] ?? null,
            'posted_at_gmt' => $receipt['posted_at_gmt'] ?? null,
            'validated_by_user_id' => (int) ($receipt['validated_by_user_id'] ?? 0),
            'posted_by_user_id' => (int) ($receipt['posted_by_user_id'] ?? 0),
            'notes' => (string) ($receipt['notes'] ?? ''),
            'created_at_gmt' => (string) ($receipt['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($receipt['updated_at_gmt'] ?? ''),
        );
        if ($include_lines) {
            $response['lines'] = array_map(array($this, 'inventory_receipt_line_response'), MGWS_DB::list_receipt_lines($response['id']));
        }
        return $response;
    }

    private function inventory_receipt_line_response($line) {
        return array(
            'id' => (int) ($line['id'] ?? 0),
            'receipt_id' => (int) ($line['receipt_id'] ?? 0),
            'line_number' => (int) ($line['line_number'] ?? 0),
            'purchase_order_line_id' => (int) ($line['purchase_order_line_id'] ?? 0),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'variation_id' => (int) ($line['variation_id'] ?? 0),
            'expected_qty' => (int) ($line['expected_qty'] ?? 0),
            'received_qty' => (int) ($line['received_qty'] ?? 0),
            'rejected_qty' => (int) ($line['rejected_qty'] ?? 0),
            'backorder_qty' => (int) ($line['backorder_qty'] ?? 0),
            'unit_cost' => number_format((float) ($line['unit_cost'] ?? 0), 4, '.', ''),
            'stock_effect' => 'load',
            'reason_code' => (string) ($line['reason_code'] ?? ''),
            'created_at_gmt' => (string) ($line['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($line['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_backorder_response($backorder) {
        return array(
            'id' => (int) ($backorder['id'] ?? 0),
            'receipt_line_id' => (int) ($backorder['receipt_line_id'] ?? 0),
            'purchase_order_line_id' => (int) ($backorder['purchase_order_line_id'] ?? 0),
            'product_id' => (int) ($backorder['product_id'] ?? 0),
            'variation_id' => (int) ($backorder['variation_id'] ?? 0),
            'remaining_qty' => (int) ($backorder['remaining_qty'] ?? 0),
            'status' => (string) ($backorder['status'] ?? 'open'),
            'expected_at_gmt' => $backorder['expected_at_gmt'] ?? null,
            'resolved_at_gmt' => $backorder['resolved_at_gmt'] ?? null,
            'created_at_gmt' => (string) ($backorder['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($backorder['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_receipt_from_request(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) { return $storage; }
        $receipt_id = $this->inventory_positive($request->get_param('receipt_id'), 'receipt_id');
        if (is_wp_error($receipt_id)) { return $receipt_id; }
        $receipt = MGWS_DB::get_receipt($receipt_id);
        if (!is_array($receipt)) { return new WP_Error('mgws_receipt_not_found', 'Receipt not found', array('status' => 404)); }
        $scope = $this->inventory_restock_row_scope($receipt);
        return is_wp_error($scope) ? $scope : $receipt;
    }

    private function inventory_receipt_line_data($body, $purchase_order, $existing = null) {
        if (!is_array($body)) { return new WP_Error('mgws_bad_request', 'Receipt line must be an object', array('status' => 400)); }
        $base = is_array($existing) ? $existing : array();
        $purchase_order_line_id = $this->inventory_positive($body['purchase_order_line_id'] ?? ($base['purchase_order_line_id'] ?? null), 'purchase_order_line_id');
        if (is_wp_error($purchase_order_line_id)) { return $purchase_order_line_id; }
        $purchase_order_line = MGWS_DB::get_purchase_order_line((int) $purchase_order['id'], $purchase_order_line_id);
        if (!is_array($purchase_order_line)) { return new WP_Error('mgws_purchase_order_line_not_found', 'Purchase order line not found', array('status' => 404)); }
        $values = array();
        foreach (array('expected_qty', 'received_qty', 'rejected_qty', 'backorder_qty') as $field) {
            $value = $this->inventory_non_negative($body[$field] ?? ($base[$field] ?? null), $field);
            if (is_wp_error($value)) { return $value; }
            $values[$field] = $value;
        }
        if ($values['expected_qty'] <= 0 || $values['received_qty'] + $values['rejected_qty'] + $values['backorder_qty'] !== $values['expected_qty']) {
            return new WP_Error('mgws_bad_request', 'Receipt line quantities must balance', array('status' => 400));
        }
        $available = max(0, (int) $purchase_order_line['ordered_qty'] - (int) $purchase_order_line['cancelled_qty'] - (int) $purchase_order_line['received_qty']);
        if ($values['expected_qty'] > $available) {
            return new WP_Error('mgws_bad_request', 'Receipt line exceeds remaining purchase order quantity', array('status' => 400));
        }
        $reason_code = $this->inventory_restock_text($body['reason_code'] ?? ($base['reason_code'] ?? ''), 'reason_code', 64);
        if (is_wp_error($reason_code)) { return $reason_code; }
        return array_merge($values, array(
            'purchase_order_line_id' => $purchase_order_line_id,
            'product_id' => (int) $purchase_order_line['product_id'],
            'variation_id' => (int) $purchase_order_line['variation_id'],
            'unit_cost' => number_format((float) $purchase_order_line['unit_cost'], 4, '.', ''),
            'reason_code' => $reason_code,
        ));
    }

    private function inventory_receipt_location($receipt, $line) {
        $warehouse_id = (int) ($receipt['warehouse_id'] ?? 0);
        if ($warehouse_id <= 0 || (int) MGWS_DB::get_site_id_for_warehouse($warehouse_id) !== (int) $receipt['site_id']) {
            return new WP_Error('mgws_conflict', 'Receipt warehouse is unavailable', array('status' => 409));
        }
        foreach (MGWS_DB::get_levels_for_item((int) $line['product_id'], (int) $line['variation_id'], (int) $receipt['site_id']) as $level) {
            if ((int) ($level['warehouse_id'] ?? 0) === $warehouse_id) {
                return array('warehouse_id' => $warehouse_id, 'room' => (string) ($level['room'] ?? ''), 'rack' => (string) ($level['rack'] ?? ''), 'shelf' => (string) ($level['shelf'] ?? ''));
            }
        }
        return array('warehouse_id' => $warehouse_id, 'room' => '', 'rack' => '', 'shelf' => '');
    }

    public function route_inventory_receipts_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) { return $storage; }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        $purchase_order_id = $this->inventory_non_negative($request->get_param('purchase_order_id') ?? 0, 'purchase_order_id');
        $status = $request->get_param('status') ?? '';
        if (is_wp_error($site_id) || is_wp_error($purchase_order_id) || !is_scalar($status)) { return is_wp_error($site_id) ? $site_id : (is_wp_error($purchase_order_id) ? $purchase_order_id : new WP_Error('mgws_bad_request', 'status must be valid text', array('status' => 400))); }
        $status = trim(sanitize_key((string) $status));
        if ($status !== '' && !in_array($status, array('draft', 'pending_verification', 'to_receive', 'qc_hold', 'posted', 'cancelled'), true)) { return new WP_Error('mgws_bad_request', 'status must be valid', array('status' => 400)); }
        return rest_ensure_response(array_map(function ($receipt) { return $this->inventory_receipt_response($receipt, false); }, MGWS_DB::list_receipts($site_id, $purchase_order_id, $status)));
    }

    public function route_inventory_receipts_create(WP_REST_Request $request) {
        global $wpdb;
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) { return $storage; }
        $body = $this->inventory_body($request);
        $idempotency_key = $this->inventory_restock_text($body['idempotency_key'] ?? '', 'idempotency_key', 191);
        if (is_wp_error($idempotency_key)) { return $idempotency_key; }
        if ($idempotency_key !== '') {
            $existing = MGWS_DB::get_receipt_by_idempotency_key($idempotency_key);
            if (is_array($existing)) {
                $scope = $this->inventory_restock_row_scope($existing);
                return is_wp_error($scope) ? $scope : rest_ensure_response($this->inventory_receipt_response($existing));
            }
        }
        $site_id = $this->inventory_restock_site($body['site_id'] ?? null, true);
        $purchase_order_id = $this->inventory_positive($body['purchase_order_id'] ?? null, 'purchase_order_id');
        $document_number = $this->inventory_restock_code($body['document_number'] ?? null, 'document_number');
        $notes = $this->inventory_restock_text($body['notes'] ?? '', 'notes', 4000, false, true);
        if (is_wp_error($site_id) || is_wp_error($purchase_order_id) || is_wp_error($document_number) || is_wp_error($notes)) { return is_wp_error($site_id) ? $site_id : (is_wp_error($purchase_order_id) ? $purchase_order_id : (is_wp_error($document_number) ? $document_number : $notes)); }
        $purchase_order = $this->inventory_restock_purchase_order($purchase_order_id);
        if (is_wp_error($purchase_order)) { return $purchase_order; }
        if ((int) $purchase_order['site_id'] !== $site_id || !in_array(($purchase_order['status'] ?? ''), array('pending', 'ordered'), true) || (int) $purchase_order['warehouse_id'] <= 0) { return new WP_Error('mgws_bad_request', 'Receipt must reference a pending or ordered purchase order at the requested site', array('status' => 400)); }
        if (!is_array($body['lines'] ?? null) || $body['lines'] === array()) { return new WP_Error('mgws_bad_request', 'Receipt must include at least one line', array('status' => 400)); }
        $line_data = array();
        $seen_purchase_order_lines = array();
        foreach ($body['lines'] as $line) {
            $data = $this->inventory_receipt_line_data($line, $purchase_order);
            if (is_wp_error($data)) { return $data; }
            if (isset($seen_purchase_order_lines[$data['purchase_order_line_id']])) { return new WP_Error('mgws_bad_request', 'Receipt cannot repeat a purchase order line', array('status' => 400)); }
            $seen_purchase_order_lines[$data['purchase_order_line_id']] = true;
            $line_data[] = $data;
        }
        $wpdb->query('START TRANSACTION');
        $requested_status = sanitize_key((string) ($body['status'] ?? 'draft'));
        if (!in_array($requested_status, array('draft', 'pending_verification', 'to_receive'), true)) { $requested_status = 'draft'; }
        $receipt = MGWS_DB::create_receipt(array('site_id' => $site_id, 'warehouse_id' => (int) $purchase_order['warehouse_id'], 'purchase_order_id' => (int) $purchase_order['id'], 'supplier_id' => (int) $purchase_order['supplier_id'], 'document_number' => $document_number, 'idempotency_key' => $idempotency_key === '' ? null : $idempotency_key, 'status' => $requested_status, 'notes' => $notes));
        if (!is_array($receipt)) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_conflict', 'Receipt document already exists', array('status' => 409)); }
        foreach ($line_data as $data) {
            if (!is_array(MGWS_DB::create_receipt_line((int) $receipt['id'], $data))) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_error', 'Unable to save receipt line', array('status' => 500)); }
        }
        $wpdb->query('COMMIT');
        return rest_ensure_response($this->inventory_receipt_response($receipt));
    }

    public function route_inventory_receipt_get(WP_REST_Request $request) {
        $receipt = $this->inventory_receipt_from_request($request);
        return is_wp_error($receipt) ? $receipt : rest_ensure_response($this->inventory_receipt_response($receipt));
    }

    public function route_inventory_receipt_patch(WP_REST_Request $request) {
        $receipt = $this->inventory_receipt_from_request($request);
        if (is_wp_error($receipt)) { return $receipt; }
        if (($receipt['status'] ?? '') === 'posted') { return new WP_Error('mgws_conflict', 'Posted receipts are immutable', array('status' => 409)); }
        $body = $this->inventory_body($request);
        $changes = array();
        if (array_key_exists('notes', $body)) {
            $notes = $this->inventory_restock_text($body['notes'], 'notes', 4000, false, true);
            if (is_wp_error($notes)) { return $notes; }
            $changes['notes'] = $notes;
        }
        if (array_key_exists('status', $body)) {
            $status = is_scalar($body['status']) ? sanitize_key((string) $body['status']) : '';
            if (!in_array($status, array('draft', 'pending_verification', 'to_receive', 'qc_hold', 'cancelled'), true)) { return new WP_Error('mgws_bad_request', 'Receipt status must be editable', array('status' => 400)); }
            $changes['status'] = $status;
        }
        if ($changes === array()) { return new WP_Error('mgws_bad_request', 'No editable receipt fields supplied', array('status' => 400)); }
        $updated = MGWS_DB::update_receipt((int) $receipt['id'], $changes);
        return is_array($updated) ? rest_ensure_response($this->inventory_receipt_response($updated)) : new WP_Error('mgws_conflict', 'Unable to update receipt', array('status' => 409));
    }

    public function route_inventory_receipt_convalida(WP_REST_Request $request) {
        global $wpdb;
        $receipt = $this->inventory_receipt_from_request($request);
        if (is_wp_error($receipt)) { return $receipt; }
        if (($receipt['status'] ?? '') === 'posted') { return rest_ensure_response($this->inventory_receipt_response($receipt)); }
        if (($receipt['status'] ?? '') === 'pending_verification' && !current_user_can('mgws_purchase_approve') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) { return new WP_Error('mgws_forbidden', 'Purchase approval is required', array('status' => 403)); }
        if (!in_array($receipt['status'] ?? '', array('draft', 'pending_verification', 'to_receive'), true)) { return new WP_Error('mgws_conflict', 'Receipt is not ready to post', array('status' => 409)); }
        $lines = MGWS_DB::list_receipt_lines((int) $receipt['id']);
        if ($lines === array()) { return new WP_Error('mgws_bad_request', 'Receipt has no lines', array('status' => 400)); }
        $purchase_order = $this->inventory_restock_purchase_order((int) $receipt['purchase_order_id']);
        if (is_wp_error($purchase_order) || !in_array(($purchase_order['status'] ?? ''), array('pending', 'ordered'), true)) { return is_wp_error($purchase_order) ? $purchase_order : new WP_Error('mgws_conflict', 'Purchase order is not available for receipt posting', array('status' => 409)); }
        if (($purchase_order['status'] ?? '') === 'pending') { $purchase_order = MGWS_DB::update_purchase_order((int) $purchase_order['id'], array('status' => 'ordered', 'ordered_at_gmt' => gmdate('Y-m-d H:i:s'), 'updated_by_user_id' => get_current_user_id())); }
        $wpdb->query('START TRANSACTION');
        $affected_items = array();
        foreach ($lines as $line) {
            $purchase_order_line = MGWS_DB::get_purchase_order_line((int) $purchase_order['id'], (int) $line['purchase_order_line_id']);
            $balanced = (int) $line['received_qty'] + (int) $line['rejected_qty'] + (int) $line['backorder_qty'] === (int) $line['expected_qty'];
            $available = is_array($purchase_order_line) ? max(0, (int) $purchase_order_line['ordered_qty'] - (int) $purchase_order_line['cancelled_qty'] - (int) $purchase_order_line['received_qty']) : -1;
            if (!$balanced || !is_array($purchase_order_line) || (int) $line['received_qty'] > $available) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_conflict', 'Receipt line exceeds remaining purchase order quantity', array('status' => 409)); }
            if ((int) $line['received_qty'] > 0) {
                $location = $this->inventory_receipt_location($receipt, $line);
                if (is_wp_error($location)) { $wpdb->query('ROLLBACK'); return $location; }
                $before = MGWS_DB::sum_qty_for_item((int) $line['product_id'], (int) $line['variation_id']);
                $applied = MGWS_DB::apply_delta_level((int) $location['warehouse_id'], (int) $line['product_id'], (int) $line['variation_id'], (int) $line['received_qty'], (string) $location['room'], (string) $location['rack'], (string) $location['shelf']);
                if (empty($applied['ok'])) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_conflict', 'Unable to apply receipt stock', array('status' => 409)); }
                $after = MGWS_DB::sum_qty_for_item((int) $line['product_id'], (int) $line['variation_id']);
                $note = 'Receipt ' . (string) $receipt['document_number'] . '; stock ' . $before . ' -> ' . $after . ' (accepted ' . (int) $line['received_qty'] . ')';
                $move_id = MGWS_DB::insert_receipt_move((int) $receipt['site_id'], (int) $location['warehouse_id'], (int) $line['product_id'], (int) $line['variation_id'], (int) $line['received_qty'], (string) $location['room'], (string) $location['rack'], (string) $location['shelf'], get_current_user_id(), (int) $receipt['id'], (int) $line['id'], (string) $line['reason_code'], $note);
                if ($move_id <= 0) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_error', 'Unable to record receipt movement', array('status' => 500)); }
                update_post_meta((int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'], '_purchase_cost', (string) $line['unit_cost']);
                $affected_items[(int) $line['product_id'] . ':' . (int) $line['variation_id']] = array((int) $line['product_id'], (int) $line['variation_id']);
            }
            if (!is_array(MGWS_DB::update_purchase_order_line((int) $purchase_order['id'], (int) $purchase_order_line['id'], array('received_qty' => (int) $purchase_order_line['received_qty'] + (int) $line['received_qty'])))) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_error', 'Unable to update purchase order receipt quantity', array('status' => 500)); }
            if ((int) $line['backorder_qty'] > 0 && !is_array(MGWS_DB::create_or_update_backorder((int) $line['id'], array('purchase_order_line_id' => (int) $line['purchase_order_line_id'], 'product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'remaining_qty' => (int) $line['backorder_qty'], 'status' => 'open', 'expected_at_gmt' => $purchase_order_line['expected_at_gmt'] ?? null, 'resolved_at_gmt' => null)))) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_error', 'Unable to save receipt backorder', array('status' => 500)); }
        }
        $now = gmdate('Y-m-d H:i:s');
        $posted = MGWS_DB::update_receipt((int) $receipt['id'], array('status' => 'posted', 'received_at_gmt' => $now, 'validated_at_gmt' => $now, 'posted_at_gmt' => $now, 'validated_by_user_id' => get_current_user_id(), 'posted_by_user_id' => get_current_user_id()));
        if (!is_array($posted)) { $wpdb->query('ROLLBACK'); return new WP_Error('mgws_conflict', 'Unable to post receipt', array('status' => 409)); }
        $wpdb->query('COMMIT');
        foreach ($affected_items as $item) { $this->sync_woo_stock($item[0], $item[1]); }
        return rest_ensure_response($this->inventory_receipt_response($posted));
    }

    public function route_inventory_backorders_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) { return $storage; }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        if (is_wp_error($site_id)) { return $site_id; }
        $backorders = array();
        foreach (MGWS_DB::list_backorders() as $backorder) {
            foreach (MGWS_DB::list_receipts($site_id) as $receipt) {
                foreach (MGWS_DB::list_receipt_lines((int) $receipt['id']) as $receipt_line) {
                    if ((int) $receipt_line['id'] === (int) $backorder['receipt_line_id']) { $backorders[] = $this->inventory_backorder_response($backorder); break 2; }
                }
            }
        }
        return rest_ensure_response($backorders);
    }

    private function inventory_count_session_response($session, $include_lines) {
        $response = array(
            'id' => (int) ($session['id'] ?? 0),
            'site_id' => (int) ($session['site_id'] ?? 0),
            'warehouse_id' => (int) ($session['warehouse_id'] ?? 0),
            'document_number' => (string) ($session['document_number'] ?? ''),
            'status' => (string) ($session['status'] ?? 'draft'),
            'started_by_user_id' => (int) ($session['started_by_user_id'] ?? 0),
            'approved_by_user_id' => (int) ($session['approved_by_user_id'] ?? 0),
            'started_at_gmt' => $session['started_at_gmt'] ?? null,
            'approved_at_gmt' => $session['approved_at_gmt'] ?? null,
            'posted_at_gmt' => $session['posted_at_gmt'] ?? null,
            'notes' => (string) ($session['notes'] ?? ''),
            'created_at_gmt' => (string) ($session['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($session['updated_at_gmt'] ?? ''),
        );
        if ($include_lines) {
            $response['lines'] = array_map(array($this, 'inventory_count_line_response'), MGWS_DB::list_inventory_count_lines($response['id']));
        }
        return $response;
    }

    private function inventory_count_line_response($line) {
        return array(
            'id' => (int) ($line['id'] ?? 0),
            'count_session_id' => (int) ($line['count_session_id'] ?? 0),
            'warehouse_id' => (int) ($line['warehouse_id'] ?? 0),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'variation_id' => (int) ($line['variation_id'] ?? 0),
            'room' => (string) ($line['room'] ?? ''),
            'rack' => (string) ($line['rack'] ?? ''),
            'shelf' => (string) ($line['shelf'] ?? ''),
            'book_qty' => (int) ($line['book_qty'] ?? 0),
            'physical_qty' => (int) ($line['physical_qty'] ?? 0),
            'discrepancy_qty' => (int) ($line['discrepancy_qty'] ?? 0),
            'reason_code' => (string) ($line['reason_code'] ?? ''),
            'stock_move_id' => (int) ($line['stock_move_id'] ?? 0),
            'counted_by_user_id' => (int) ($line['counted_by_user_id'] ?? 0),
            'counted_at_gmt' => $line['counted_at_gmt'] ?? null,
            'created_at_gmt' => (string) ($line['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($line['updated_at_gmt'] ?? ''),
        );
    }

    private function inventory_count_session_from_request(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $session_id = $this->inventory_positive($request->get_param('session_id'), 'session_id');
        if (is_wp_error($session_id)) {
            return $session_id;
        }
        $session = MGWS_DB::get_inventory_count_session($session_id);
        if (!is_array($session)) {
            return new WP_Error('mgws_not_found', 'Inventory count session not found', array('status' => 404));
        }
        $scope = $this->inventory_restock_row_scope($session);
        return is_wp_error($scope) ? $scope : $session;
    }

    private function inventory_count_validate_warehouse($site_id, $warehouse_id) {
        $warehouse_id = $this->inventory_positive($warehouse_id, 'warehouse_id');
        if (is_wp_error($warehouse_id)) {
            return $warehouse_id;
        }
        if ((int) MGWS_DB::get_site_id_for_warehouse($warehouse_id) !== (int) $site_id) {
            return new WP_Error('mgws_bad_request', 'Warehouse does not belong to the requested site', array('status' => 400));
        }
        return $warehouse_id;
    }

    private function inventory_count_location($body) {
        $location = array();
        foreach (array('room', 'rack', 'shelf') as $field) {
            $value = $this->inventory_restock_text($body[$field] ?? '', $field, 80);
            if (is_wp_error($value)) {
                return $value;
            }
            $location[$field] = $value;
        }
        return $location;
    }

    private function inventory_count_item($body) {
        if (array_key_exists('product_id', $body)) {
            $product_id = $this->inventory_positive($body['product_id'], 'product_id');
            $variation_id = $this->inventory_non_negative($body['variation_id'] ?? 0, 'variation_id');
            if (is_wp_error($product_id) || is_wp_error($variation_id)) {
                return is_wp_error($product_id) ? $product_id : $variation_id;
            }
        } else {
            $barcode = $body['barcode'] ?? ($body['tag'] ?? null);
            if (!is_scalar($barcode) || trim((string) $barcode) === '' || strlen((string) $barcode) > 191) {
                return new WP_Error('mgws_bad_request', 'product_id or barcode is required', array('status' => 400));
            }
            $resolved_id = $this->find_product_id_by_barcode(sanitize_text_field((string) $barcode));
            if ($resolved_id <= 0) {
                return new WP_Error('mgws_product_not_found', 'Product not found', array('status' => 404));
            }
            $product_id = $resolved_id;
            $variation_id = 0;
            if (function_exists('wc_get_product')) {
                $resolved_product = wc_get_product($resolved_id);
                if ($resolved_product && $resolved_product->is_type('variation')) {
                    $variation_id = $resolved_id;
                    $product_id = (int) $resolved_product->get_parent_id();
                }
            }
        }
        $product = $this->inventory_product($product_id, $variation_id);
        return is_wp_error($product) ? $product : array('product_id' => (int) $product_id, 'variation_id' => (int) $variation_id);
    }

    public function route_inventory_count_sessions_list(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $site_id = $this->inventory_restock_site($request->get_param('site_id'));
        $warehouse_id = $this->inventory_non_negative($request->get_param('warehouse_id') ?? 0, 'warehouse_id');
        if (is_wp_error($site_id) || is_wp_error($warehouse_id)) {
            return is_wp_error($site_id) ? $site_id : $warehouse_id;
        }
        return rest_ensure_response(array_map(function ($session) {
            return $this->inventory_count_session_response($session, false);
        }, MGWS_DB::list_inventory_count_sessions($site_id, $warehouse_id)));
    }

    public function route_inventory_count_session_create(WP_REST_Request $request) {
        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $site_id = $this->inventory_restock_site($body['site_id'] ?? null, true);
        $document_number = $this->inventory_restock_code($body['document_number'] ?? null, 'document_number');
        $notes = $this->inventory_restock_text($body['notes'] ?? '', 'notes', 4000, false, true);
        if (is_wp_error($site_id) || is_wp_error($document_number) || is_wp_error($notes)) {
            return is_wp_error($site_id) ? $site_id : (is_wp_error($document_number) ? $document_number : $notes);
        }
        $warehouse_id = $this->inventory_count_validate_warehouse($site_id, $body['warehouse_id'] ?? null);
        if (is_wp_error($warehouse_id)) {
            return $warehouse_id;
        }
        $session = MGWS_DB::create_inventory_count_session(array(
            'site_id' => $site_id,
            'warehouse_id' => $warehouse_id,
            'document_number' => $document_number,
            'started_by_user_id' => get_current_user_id(),
            'notes' => $notes,
        ));
        if (!is_array($session)) {
            return new WP_Error('mgws_conflict', 'Unable to create inventory count session', array('status' => 409));
        }
        return rest_ensure_response($this->inventory_count_session_response($session, true));
    }

    public function route_inventory_count_session_detail(WP_REST_Request $request) {
        $session = $this->inventory_count_session_from_request($request);
        return is_wp_error($session) ? $session : rest_ensure_response($this->inventory_count_session_response($session, true));
    }

    public function route_inventory_count_session_patch(WP_REST_Request $request) {
        $session = $this->inventory_count_session_from_request($request);
        if (is_wp_error($session)) {
            return $session;
        }
        if (($session['status'] ?? '') === 'posted') {
            return new WP_Error('mgws_conflict', 'Posted inventory count sessions are immutable', array('status' => 409));
        }
        $body = $this->inventory_body($request);
        $changes = array();
        if (array_key_exists('document_number', $body)) {
            $document_number = $this->inventory_restock_code($body['document_number'], 'document_number');
            if (is_wp_error($document_number)) {
                return $document_number;
            }
            $changes['document_number'] = $document_number;
        }
        if (array_key_exists('notes', $body)) {
            $notes = $this->inventory_restock_text($body['notes'], 'notes', 4000, false, true);
            if (is_wp_error($notes)) {
                return $notes;
            }
            $changes['notes'] = $notes;
        }
        if ($changes === array()) {
            return new WP_Error('mgws_bad_request', 'No editable inventory count fields supplied', array('status' => 400));
        }
        $updated = MGWS_DB::update_inventory_count_session((int) $session['id'], $changes);
        return is_array($updated) ? rest_ensure_response($this->inventory_count_session_response($updated, true)) : new WP_Error('mgws_conflict', 'Unable to update inventory count session', array('status' => 409));
    }

    public function route_inventory_count_session_line_create(WP_REST_Request $request) {
        $session = $this->inventory_count_session_from_request($request);
        if (is_wp_error($session)) {
            return $session;
        }
        if (($session['status'] ?? '') === 'posted') {
            return new WP_Error('mgws_conflict', 'Posted inventory count sessions are immutable', array('status' => 409));
        }
        $body = $this->inventory_body($request);
        $item = $this->inventory_count_item($body);
        $physical_qty = $this->inventory_non_negative($body['physical_qty'] ?? null, 'physical_qty');
        $location = $this->inventory_count_location($body);
        $reason_code = $this->inventory_restock_text($body['reason_code'] ?? '', 'reason_code', 64);
        if (is_wp_error($item) || is_wp_error($physical_qty) || is_wp_error($location) || is_wp_error($reason_code)) {
            return is_wp_error($item) ? $item : (is_wp_error($physical_qty) ? $physical_qty : (is_wp_error($location) ? $location : $reason_code));
        }
        $warehouse_id = $this->inventory_count_validate_warehouse((int) $session['site_id'], $body['warehouse_id'] ?? $session['warehouse_id']);
        if (is_wp_error($warehouse_id)) {
            return $warehouse_id;
        }
        if ((int) $warehouse_id !== (int) $session['warehouse_id']) {
            return new WP_Error('mgws_bad_request', 'Count line warehouse must match the session warehouse', array('status' => 400));
        }
        $level = MGWS_DB::get_level_row($warehouse_id, $item['product_id'], $item['variation_id'], $location['room'], $location['rack'], $location['shelf']);
        $book_qty = (int) ($level['qty'] ?? 0);
        $data = array(
            'warehouse_id' => $warehouse_id,
            'product_id' => $item['product_id'],
            'variation_id' => $item['variation_id'],
            'room' => $location['room'],
            'rack' => $location['rack'],
            'shelf' => $location['shelf'],
            'book_qty' => $book_qty,
            'physical_qty' => $physical_qty,
            'discrepancy_qty' => $physical_qty - $book_qty,
            'reason_code' => $reason_code,
            'counted_by_user_id' => get_current_user_id(),
        );
        $existing = MGWS_DB::find_inventory_count_line((int) $session['id'], $warehouse_id, $item['product_id'], $item['variation_id'], $location['room'], $location['rack'], $location['shelf']);
        $line = is_array($existing) ? MGWS_DB::update_inventory_count_line((int) $session['id'], (int) $existing['id'], $data) : MGWS_DB::create_inventory_count_line((int) $session['id'], $data);
        return is_array($line) ? rest_ensure_response($this->inventory_count_line_response($line)) : new WP_Error('mgws_conflict', 'Unable to save inventory count line', array('status' => 409));
    }

    public function route_inventory_count_session_approve(WP_REST_Request $request) {
        global $wpdb;
        $session = $this->inventory_count_session_from_request($request);
        if (is_wp_error($session)) {
            return $session;
        }
        if (($session['status'] ?? '') === 'posted') {
            return new WP_Error('mgws_conflict', 'Inventory count session is already posted', array('status' => 409));
        }
        $lines = MGWS_DB::list_inventory_count_lines((int) $session['id']);
        if ($lines === array()) {
            return new WP_Error('mgws_bad_request', 'Inventory count session has no lines', array('status' => 400));
        }
        $wpdb->query('START TRANSACTION');
        $affected_items = array();
        foreach ($lines as $line) {
            $discrepancy = (int) $line['physical_qty'] - (int) $line['book_qty'];
            if ($discrepancy === 0) {
                continue;
            }
            $current = MGWS_DB::get_level_row((int) $line['warehouse_id'], (int) $line['product_id'], (int) $line['variation_id'], (string) $line['room'], (string) $line['rack'], (string) $line['shelf']);
            if ((int) ($current['qty'] ?? 0) !== (int) $line['book_qty']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', 'Stock changed since this inventory count was recorded', array('status' => 409));
            }
            $applied = MGWS_DB::apply_delta_level((int) $line['warehouse_id'], (int) $line['product_id'], (int) $line['variation_id'], $discrepancy, (string) $line['room'], (string) $line['rack'], (string) $line['shelf']);
            if (empty($applied['ok'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', 'Unable to apply inventory count adjustment', array('status' => 409));
            }
            $move_id = MGWS_DB::insert_inventory_count_move((int) $session['site_id'], (int) $line['warehouse_id'], (int) $line['product_id'], (int) $line['variation_id'], $discrepancy, (string) $line['room'], (string) $line['rack'], (string) $line['shelf'], get_current_user_id(), (int) $session['id'], (int) $line['id'], (string) $line['reason_code'], 'Inventory count adjustment');
            if ($move_id <= 0 || !is_array(MGWS_DB::update_inventory_count_line((int) $session['id'], (int) $line['id'], array('stock_move_id' => $move_id, 'discrepancy_qty' => $discrepancy)))) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_error', 'Unable to record inventory count movement', array('status' => 500));
            }
            $affected_items[(int) $line['product_id'] . ':' . (int) $line['variation_id']] = array((int) $line['product_id'], (int) $line['variation_id']);
        }
        $now = gmdate('Y-m-d H:i:s');
        $posted = MGWS_DB::update_inventory_count_session((int) $session['id'], array('status' => 'posted', 'approved_by_user_id' => get_current_user_id(), 'approved_at_gmt' => $now, 'posted_at_gmt' => $now));
        if (!is_array($posted)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mgws_conflict', 'Unable to post inventory count session', array('status' => 409));
        }
        $wpdb->query('COMMIT');
        foreach ($affected_items as $item) {
            $this->sync_woo_stock($item[0], $item[1]);
        }
        return rest_ensure_response($this->inventory_count_session_response($posted, true));
    }

    // ---- Permissions

    private function require_logged_in() {
        if (!is_user_logged_in()) {
            return new WP_Error('mgws_not_logged_in', 'Authentication required', array('status' => 401));
        }
        return true;
    }

    public function perm_logged_in() {
        return $this->require_logged_in();
    }

    public function perm_manage_employees() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_move') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_stock_read() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_read')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_stock_move() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_move')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_inventory_restock_read() {
        return $this->perm_stock_read();
    }

    public function perm_inventory_restock_mutate() {
        return $this->perm_stock_move();
    }

    public function perm_inventory_purchase_approve() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_purchase_approve') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_order_accept() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_order_accept')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_manage_credentials() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_manage_credentials') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_manage_user_permissions() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_manage_user_permissions') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_pos_checkout() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_move') && !current_user_can('mgws_order_accept') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_manage_pos_settings() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_loyalty_read() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_read') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    public function perm_loyalty_mutate() {
        $ok = $this->require_logged_in();
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!current_user_can('mgws_stock_move') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Insufficient permissions', array('status' => 403));
        }
        return true;
    }

    private function loyalty_customer_args() {
        return array(
            'customer_id' => array(
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => array($this, 'sanitize_loyalty_positive_int'),
                'validate_callback' => array($this, 'validate_loyalty_positive_int'),
            ),
        );
    }

    private function loyalty_card_number_arg() {
        return array(
            'required' => true,
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_loyalty_card_number'),
            'validate_callback' => array($this, 'validate_loyalty_card_number'),
        );
    }

    private function loyalty_email_arg() {
        return array(
            'required' => true,
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_loyalty_email'),
            'validate_callback' => array($this, 'validate_loyalty_email'),
        );
    }

    private function loyalty_tier_arg() {
        return array(
            'required' => false,
            'type' => 'string',
            'default' => 'bronze',
            'sanitize_callback' => array($this, 'sanitize_loyalty_tier'),
            'validate_callback' => array($this, 'validate_loyalty_tier'),
        );
    }

    private function loyalty_points_args() {
        return array_merge($this->loyalty_customer_args(), array(
            'points' => array(
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => array($this, 'sanitize_loyalty_positive_int'),
                'validate_callback' => array($this, 'validate_loyalty_positive_int'),
            ),
            'reference' => array(
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array($this, 'validate_loyalty_reference'),
            ),
            'note' => array(
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'validate_callback' => array($this, 'validate_loyalty_note'),
            ),
        ));
    }

    private function inventory_read_args() {
        return array(
            'site_id' => $this->inventory_non_negative_int_arg(0),
            'warehouse_id' => $this->inventory_non_negative_int_arg(0),
        );
    }

    private function empty_route_args() {
        return array();
    }

    private function inventory_positive_int_arg($default = null) {
        $arg = array('required' => $default === null, 'type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_inventory_integer'), 'validate_callback' => array($this, 'validate_inventory_positive_int'));
        if ($default !== null) {
            $arg['default'] = $default;
        }
        return $arg;
    }

    private function inventory_non_negative_int_arg($default = null) {
        $arg = array('required' => $default === null, 'type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_inventory_integer'), 'validate_callback' => array($this, 'validate_inventory_non_negative_int'));
        if ($default !== null) {
            $arg['default'] = $default;
        }
        return $arg;
    }

    private function inventory_text_arg() {
        return array('required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => array($this, 'validate_inventory_text'));
    }

    /**
     * Dichiarazione per la chiave che tiene insieme le righe di una operazione.
     *
     * Formato e lunghezza non sono un dettaglio del client: la colonna e' unica e
     * l'indice del ledger ci si appoggia, quindi una chiave con spazi o con un
     * punto si accorerebbe con un'altra senza che nessuno se ne accorga. La
     * validazione la fa anche `inventory_movement_key` nel corpo, perche' questa
     * dichiarazione si fermerebbe ai parametri dichiarati e la chiave può
     * arrivare nel JSON.
     */
    private function inventory_movement_key_arg() {
        return array(
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => array($this, 'validate_inventory_movement_key_arg'),
        );
    }

    public function validate_inventory_movement_key_arg($value) {
        if (!is_scalar($value)) {
            return false;
        }
        $key = sanitize_text_field((string) $value);
        return $key === '' || (strlen($key) <= 64 && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $key));
    }

    /**
     * Nota lunga: il tetto e' 4000 come in `inventory_restock_text`, non i 1000
     * di `validate_inventory_text`.
     *
     * Dichiarare la nota con il validatore corto la renderebbe inutilizzabile
     * sopra mille caratteri, e il rifiuto arriverebbe dal validatore dichiarato
     * invece che dal controllo nel corpo, che conosce il tetto giusto. Le due
     * cose devono dire la stessa sennò il comportamento dipende da come e'
     * stata scritta la richiesta.
     */
    private function inventory_note_arg() {
        return array(
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'validate_callback' => array($this, 'validate_inventory_note'),
        );
    }

    public function validate_inventory_note($value) {
        return is_scalar($value) && strlen((string) $value) <= 4000;
    }

    /**
     * Stanza, scaffale o ripiano: testo breve come il motivo, e con lo stesso
     * validatore, perche' condividono lo stesso tetto e lo stesso tipo di rischio.
     */
    private function inventory_short_text_arg() {
        return array('required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => array($this, 'validate_inventory_text'));
    }

    public function sanitize_inventory_integer($value) { return (int) $value; }
    public function validate_inventory_positive_int($value) { return $this->is_positive_integer($value); }
    public function validate_inventory_non_negative_int($value) { return $this->is_non_negative_integer($value); }
    public function validate_inventory_text($value) { return is_scalar($value) && strlen((string) $value) <= 1000; }

    public function sanitize_inventory_tags($value) {
        return is_array($value) ? array_values(array_map('sanitize_text_field', $value)) : array();
    }

    public function validate_inventory_tags($value) {
        if (!is_array($value)) { return false; }
        foreach ($value as $tag) {
            if (!is_scalar($tag) || strlen((string) $tag) > 191) { return false; }
        }
        return true;
    }

    private function is_positive_integer($value) {
        return is_int($value) ? $value > 0 : (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1);
    }

    private function is_non_negative_integer($value) {
        return is_int($value) ? $value >= 0 : (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1);
    }

    private function pos_checkout_args() {
        return array(
            'idempotency_key' => array('required' => false, 'type' => 'string', 'sanitize_callback' => array($this, 'sanitize_pos_idempotency_key'), 'validate_callback' => array($this, 'validate_pos_idempotency_key')),
            'sale_items' => $this->pos_items_arg(),
            'return_items' => $this->pos_items_arg(),
            'customer' => $this->pos_object_arg(),
            'totals' => $this->pos_object_arg(),
            'meta_data' => array('required' => false, 'type' => 'array', 'sanitize_callback' => array($this, 'sanitize_pos_array'), 'validate_callback' => array($this, 'validate_pos_array')),
            'payment_method' => $this->inventory_text_arg(),
            'payment_method_title' => $this->inventory_text_arg(),
            'operation_type' => $this->inventory_text_arg(),
            'effective_operation_type' => $this->inventory_text_arg(),
            'note' => array('required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'validate_callback' => array($this, 'validate_inventory_text')),
            'set_paid' => array('required' => false, 'type' => 'boolean', 'sanitize_callback' => array($this, 'sanitize_pos_boolean'), 'validate_callback' => array($this, 'validate_pos_boolean')),
        );
    }

    private function pos_items_arg() {
        return array('required' => false, 'type' => 'array', 'sanitize_callback' => array($this, 'sanitize_pos_array'), 'validate_callback' => array($this, 'validate_pos_items'));
    }

    private function pos_object_arg() {
        return array('required' => false, 'type' => 'object', 'sanitize_callback' => array($this, 'sanitize_pos_array'), 'validate_callback' => array($this, 'validate_pos_object'));
    }

    public function sanitize_pos_idempotency_key($value) { return trim(sanitize_text_field((string) $value)); }

    public function validate_pos_idempotency_key($value) {
        if (!is_scalar($value)) { return false; }
        $key = trim((string) $value);
        return $key === '' || (strlen($key) <= 191 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $key) === 1);
    }

    private function pos_shift_open_args() {
        return array(
            'shift_key' => array('required' => true, 'type' => 'string', 'sanitize_callback' => array($this, 'sanitize_pos_shift_key'), 'validate_callback' => array($this, 'validate_pos_shift_key')),
            'giornata_id' => $this->inventory_text_arg(),
            'cassa_name' => $this->inventory_text_arg(),
            'sede' => $this->inventory_text_arg(),
            'operator_id' => $this->inventory_non_negative_int_arg(0),
            'operator_name' => $this->inventory_text_arg(),
            'fondo_iniziale' => $this->pos_float_arg(0),
        );
    }

    private function pos_shift_close_args() {
        return array(
            'contante_contato' => $this->pos_float_arg(0),
            'carta_contato' => $this->pos_float_arg(0),
            'causale_differenza' => $this->inventory_text_arg(),
            'note' => $this->inventory_text_arg(),
        );
    }

    private function pos_float_arg($default = null) {
        $arg = array('required' => $default === null, 'type' => 'number', 'sanitize_callback' => array($this, 'sanitize_pos_float'), 'validate_callback' => array($this, 'validate_pos_float'));
        if ($default !== null) {
            $arg['default'] = $default;
        }
        return $arg;
    }

    public function sanitize_pos_shift_key($value) { return trim(sanitize_text_field((string) $value)); }

    public function validate_pos_shift_key($value) {
        if (!is_scalar($value)) { return false; }
        $key = trim((string) $value);
        return $key !== '' && strlen($key) <= 191 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $key) === 1;
    }

    public function sanitize_pos_float($value) { return is_numeric($value) ? (float) $value : 0.0; }

    public function validate_pos_float($value) { return is_numeric($value) && (float) $value >= 0; }

    public function validate_pos_items($value) {
        if (!is_array($value)) { return false; }
        foreach ($value as $item) {
            if (!is_array($item) || !$this->is_positive_integer($item['product_id'] ?? null) || !$this->is_non_negative_integer($item['variation_id'] ?? 0) || !$this->is_positive_integer($item['quantity'] ?? null)) { return false; }
        }
        return true;
    }

    public function validate_pos_object($value) { return is_array($value); }
    public function validate_pos_array($value) { return is_array($value); }
    public function sanitize_pos_array($value) { return is_array($value) ? $value : array(); }
    public function sanitize_pos_boolean($value) { return filter_var($value, FILTER_VALIDATE_BOOLEAN); }
    public function validate_pos_boolean($value) {
        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1' || $value === 'true' || $value === 'false';
    }

    private function inventory_body(WP_REST_Request $request) {
        $body = $request->get_json_params();
        return is_array($body) ? $body : array();
    }

    private function inventory_positive($value, $field) {
        if (!$this->is_positive_integer($value)) {
            return new WP_Error('mgws_bad_request', $field . ' must be a positive integer', array('status' => 400));
        }
        return (int) $value;
    }

    private function inventory_non_negative($value, $field) {
        if (!$this->is_non_negative_integer($value)) {
            return new WP_Error('mgws_bad_request', $field . ' must be a non-negative integer', array('status' => 400));
        }
        return (int) $value;
    }

    private function inventory_product($product_id, $variation_id) {
        if ($product_id <= 0 || $variation_id < 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product identifier', array('status' => 400));
        }
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        if (!$product || ($variation_id === 0 && $product->is_type('variation'))) {
            return new WP_Error('mgws_product_not_found', 'Product not found', array('status' => 404));
        }
        if ($variation_id > 0 && (!$product->is_type('variation') || (int) $product->get_parent_id() !== $product_id)) {
            return new WP_Error('mgws_bad_request', 'Invalid variation identifier', array('status' => 400));
        }
        return $product;
    }

    private function inventory_levels(WP_REST_Request $request) {
        global $wpdb;
        $site_id = $this->inventory_non_negative($request->get_param('site_id') ?? 0, 'site_id');
        $warehouse_id = $this->inventory_non_negative($request->get_param('warehouse_id') ?? 0, 'warehouse_id');
        if (is_wp_error($site_id) || is_wp_error($warehouse_id)) {
            return is_wp_error($site_id) ? $site_id : $warehouse_id;
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && $site_id > 0 && $site_id !== $site_limit) {
            return new WP_Error('mgws_forbidden', 'Site outside allowed scope', array('status' => 403));
        }
        $site_id = $site_id > 0 ? $site_id : $site_limit;
        $sql = 'SELECT site_id, warehouse_id, product_id, variation_id, qty, room, rack, shelf FROM ' . MGWS_DB::table_levels() . ' WHERE 1=1';
        $params = array();
        if ($site_id > 0) { $sql .= ' AND site_id=%d'; $params[] = $site_id; }
        if ($warehouse_id > 0) { $sql .= ' AND warehouse_id=%d'; $params[] = $warehouse_id; }
        $sql .= ' ORDER BY product_id ASC, variation_id ASC, site_id ASC, warehouse_id ASC, room ASC, rack ASC, shelf ASC';
        $query = empty($params) ? $sql : $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($query, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private function inventory_records($levels, $products = array()) {
        $records = array();
        foreach ($levels as $level) {
            $product_id = (int) ($level['product_id'] ?? 0);
            $variation_id = (int) ($level['variation_id'] ?? 0);
            $key = $product_id . ':' . $variation_id;
            if (!isset($records[$key])) { $records[$key] = $this->inventory_empty_record($product_id, $variation_id, $products[$key] ?? null); }
            $records[$key]['current_stock'] += (int) ($level['qty'] ?? 0);
            $records[$key]['locations'][] = array('site_id' => (int) ($level['site_id'] ?? 0), 'warehouse_id' => (int) ($level['warehouse_id'] ?? 0), 'qty' => (int) ($level['qty'] ?? 0), 'room' => (string) ($level['room'] ?? ''), 'rack' => (string) ($level['rack'] ?? ''), 'shelf' => (string) ($level['shelf'] ?? ''), 'location_label' => MGWS_DB::format_location_label($level['room'] ?? '', $level['rack'] ?? '', $level['shelf'] ?? ''));
        }
        return array_values($records);
    }

    private function inventory_empty_record($product_id, $variation_id, $product) {
        return array('product_id' => $product_id, 'variation_id' => $variation_id, 'product_name' => $product && method_exists($product, 'get_name') ? $product->get_name() : '', 'current_stock' => 0, 'locations' => array());
    }

    // ---- Routes

    private function app_settings_meta_key() {
        return '_mgws_app_settings';
    }

    private function pos_turno_obbligatorio() {
        return (string) get_option(self::POS_TURNO_OBBLIGATORIO_OPTION, '1') !== '0';
    }

    public function route_pos_settings_get(WP_REST_Request $request) {
        return rest_ensure_response(array(
            'ok' => true,
            'turno_obbligatorio' => $this->pos_turno_obbligatorio(),
        ));
    }

    public function route_pos_settings_put(WP_REST_Request $request) {
        $body = $this->inventory_body($request);
        if (!array_key_exists('turno_obbligatorio', $body)) {
            return new WP_Error('mgws_bad_request', 'turno_obbligatorio is required', array('status' => 400));
        }
        $turno_obbligatorio = filter_var($body['turno_obbligatorio'], FILTER_VALIDATE_BOOLEAN);
        update_option(self::POS_TURNO_OBBLIGATORIO_OPTION, $turno_obbligatorio ? '1' : '0', false);
        return rest_ensure_response(array(
            'ok' => true,
            'turno_obbligatorio' => $turno_obbligatorio,
        ));
    }

    public function route_me_settings_get(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $settings = get_user_meta($user_id, $this->app_settings_meta_key(), true);
        if (!is_array($settings)) {
            $settings = array();
        }
        return rest_ensure_response(array(
            'ok' => true,
            'user_id' => (int) $user_id,
            'settings' => $settings,
            'updated_at_gmt' => (string) get_user_meta($user_id, $this->app_settings_meta_key() . '_updated_at_gmt', true),
        ));
    }

    public function route_me_settings_patch(WP_REST_Request $request) {
        $body = $this->inventory_body($request);
        $incoming = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;
        if (!is_array($incoming)) {
            return new WP_Error('mgws_bad_request', 'settings must be an object', array('status' => 400));
        }
        $encoded = wp_json_encode($incoming);
        if (is_string($encoded) && strlen($encoded) > 262144) {
            return new WP_Error('mgws_bad_request', 'settings payload too large', array('status' => 400));
        }
        $user_id = get_current_user_id();
        $current = get_user_meta($user_id, $this->app_settings_meta_key(), true);
        if (!is_array($current)) {
            $current = array();
        }
        $settings = $this->sanitize_settings_recursive(array_merge($current, $incoming));
        $now = gmdate('Y-m-d H:i:s');
        update_user_meta($user_id, $this->app_settings_meta_key(), $settings);
        update_user_meta($user_id, $this->app_settings_meta_key() . '_updated_at_gmt', $now);
        return rest_ensure_response(array('ok' => true, 'user_id' => (int) $user_id, 'settings' => $settings, 'updated_at_gmt' => $now));
    }

    private function sanitize_settings_recursive($value) {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $child) {
                $clean_key = sanitize_key((string) $key);
                if ($clean_key === '') {
                    continue;
                }
                $out[$clean_key] = $this->sanitize_settings_recursive($child);
            }
            return $out;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return sanitize_text_field((string) $value);
    }

    public function route_employees_list(WP_REST_Request $request) {
        $rows = MGWS_DB::list_employees(
            (string) $request->get_param('search'),
            (bool) $request->get_param('include_inactive'),
            (int) $request->get_param('limit')
        );
        return rest_ensure_response(array_map(array($this, 'employee_response'), is_array($rows) ? $rows : array()));
    }

    public function route_employee_get(WP_REST_Request $request) {
        $row = MGWS_DB::get_employee((int) $request->get_param('employee_id'));
        if (!is_array($row)) {
            return new WP_Error('mgws_employee_not_found', 'Employee not found', array('status' => 404));
        }
        return rest_ensure_response($this->employee_response($row));
    }

    public function route_employee_create(WP_REST_Request $request) {
        $data = $this->employee_payload($request);
        if (is_wp_error($data)) {
            return $data;
        }
        $row = MGWS_DB::insert_employee($data);
        if (!is_array($row)) {
            return new WP_Error('mgws_employee_error', 'Employee could not be created', array('status' => 500));
        }
        return rest_ensure_response($this->employee_response($row));
    }

    public function route_employee_update(WP_REST_Request $request) {
        $employee_id = (int) $request->get_param('employee_id');
        if (!is_array(MGWS_DB::get_employee($employee_id))) {
            return new WP_Error('mgws_employee_not_found', 'Employee not found', array('status' => 404));
        }
        $data = $this->employee_payload($request, true);
        if (is_wp_error($data)) {
            return $data;
        }
        $row = MGWS_DB::update_employee($employee_id, $data);
        if (!is_array($row)) {
            return new WP_Error('mgws_employee_error', 'Employee could not be updated', array('status' => 500));
        }
        return rest_ensure_response($this->employee_response($row));
    }

    public function route_employee_delete(WP_REST_Request $request) {
        $employee_id = (int) $request->get_param('employee_id');
        $row = MGWS_DB::deactivate_employee($employee_id);
        if (!is_array($row)) {
            return new WP_Error('mgws_employee_not_found', 'Employee not found', array('status' => 404));
        }
        return rest_ensure_response($this->employee_response($row));
    }

    private function employee_payload(WP_REST_Request $request, $partial = false) {
        $body = $this->inventory_body($request);
        $data = array();
        $fields = array('wp_user_id', 'first_name', 'last_name', 'email', 'phone', 'role_label', 'status', 'active', 'salary_cents', 'salary_currency', 'notes');
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        if (!$partial) {
            if (trim((string) ($data['first_name'] ?? '')) === '' || trim((string) ($data['last_name'] ?? '')) === '') {
                return new WP_Error('mgws_bad_request', 'first_name and last_name are required', array('status' => 400));
            }
        }
        if (isset($data['email']) && (string) $data['email'] !== '' && !is_email((string) $data['email'])) {
            return new WP_Error('mgws_bad_request', 'Invalid email', array('status' => 400));
        }
        if (isset($data['wp_user_id']) && (int) $data['wp_user_id'] > 0 && !get_user_by('id', (int) $data['wp_user_id'])) {
            return new WP_Error('mgws_bad_request', 'wp_user_id does not exist', array('status' => 400));
        }
        if (isset($data['salary_cents']) && (int) $data['salary_cents'] < 0) {
            return new WP_Error('mgws_bad_request', 'salary_cents must be a non-negative integer', array('status' => 400));
        }
        if (isset($data['salary_currency']) && !preg_match('/^[A-Za-z]{3}$/', (string) $data['salary_currency'])) {
            return new WP_Error('mgws_bad_request', 'salary_currency must be an ISO-4217 code', array('status' => 400));
        }
        return $data;
    }

    private function employee_response($row) {
        return array(
            'id' => (int) $row['id'],
            'wp_user_id' => (int) ($row['wp_user_id'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'role_label' => (string) ($row['role_label'] ?? ''),
            'status' => (string) ($row['status'] ?? (((int) ($row['active'] ?? 1)) === 1 ? 'active' : 'inactive')),
            'active' => ((int) ($row['active'] ?? 1)) === 1,
            'salary_cents' => (int) ($row['salary_cents'] ?? 0),
            'salary_currency' => (string) ($row['salary_currency'] ?? 'EUR'),
            'notes' => (string) ($row['notes'] ?? ''),
            'created_at_gmt' => (string) ($row['created_at_gmt'] ?? ''),
            'updated_at_gmt' => (string) ($row['updated_at_gmt'] ?? ''),
        );
    }

    public function route_health(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $site_limit = $this->get_user_site_limit($user_id);
        return rest_ensure_response(array(
            'ok' => true,
            'user_id' => (int) $user_id,
            'site_limit' => (int) $site_limit,
        ));
    }

    public function route_loyalty_status(WP_REST_Request $request) {
        $enabled = MGWS_DB::loyalty_tables_exist();
        return rest_ensure_response(array(
            'ok' => $enabled,
            'service' => 'loyalty',
            'enabled' => $enabled,
            'configured' => $enabled,
        ));
    }

    public function route_loyalty_cards_list(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $cards = MGWS_DB::get_loyalty_cards_all();
        if (!is_array($cards)) {
            return rest_ensure_response(array());
        }
        $result = array_map(function($row) {
            return array(
                'id' => (int) $row['id'],
                'user_id' => (int) $row['customer_id'],
                'customer_id' => (int) $row['customer_id'],
                'card_number' => is_string($row['card_number']) && $row['card_number'] !== ''
                    ? (string) $row['card_number']
                    : null,
                'tier' => is_string($row['tier']) ? (string) $row['tier'] : 'bronze',
                'first_name' => is_string($row['first_name']) ? (string) $row['first_name'] : '',
                'last_name' => is_string($row['last_name']) ? (string) $row['last_name'] : '',
                'email' => is_string($row['user_email']) ? (string) $row['user_email'] : '',
                'points' => (int) ($row['points_balance'] ?? 0),
                'enabled' => (int) ($row['enabled'] ?? 1) === 1,
            );
        }, $cards);
        return rest_ensure_response($result);
    }

    public function route_loyalty_customer(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $customer = $this->loyalty_customer_from_request($request);
        if (is_wp_error($customer)) {
            return $customer;
        }
        return rest_ensure_response($this->loyalty_customer_response($customer));
    }

    public function route_loyalty_lookup_card(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $card_number = $this->sanitize_loyalty_card_number($request->get_param('card_number'));
        if (!$this->validate_loyalty_card_number($card_number)) {
            return new WP_Error('mgws_bad_request', 'Invalid card number', array('status' => 400));
        }
        $account = MGWS_DB::get_loyalty_account_by_card($card_number);
        if (!is_array($account)) {
            return new WP_Error('mgws_loyalty_card_not_found', 'Loyalty card not found', array('status' => 404));
        }
        $customer = $this->loyalty_customer_by_id((int) $account['customer_id']);
        if (is_wp_error($customer)) {
            return $customer;
        }
        return rest_ensure_response($this->loyalty_customer_response($customer, $account));
    }

    public function route_loyalty_lookup_email(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $email = $this->sanitize_loyalty_email($request->get_param('email'));
        if (!$this->validate_loyalty_email($email)) {
            return new WP_Error('mgws_bad_request', 'Invalid email', array('status' => 400));
        }
        $customer = get_user_by('email', $email);
        if (!$customer) {
            return new WP_Error('mgws_loyalty_customer_not_found', 'Loyalty customer not found', array('status' => 404));
        }
        $account = MGWS_DB::get_loyalty_account((int) $customer->ID);
        if (!is_array($account)) {
            return new WP_Error('mgws_loyalty_customer_not_found', 'Loyalty customer not found', array('status' => 404));
        }
        return rest_ensure_response($this->loyalty_customer_response($customer, $account));
    }

    public function route_loyalty_card_put(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $customer = $this->loyalty_customer_from_request($request);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $body = $this->loyalty_request_body($request);
        $card_number = $this->sanitize_loyalty_card_number($body['card_number'] ?? '');
        if (!$this->validate_loyalty_card_number($card_number)) {
            return new WP_Error('mgws_bad_request', 'Invalid card number', array('status' => 400));
        }
        $tier = $this->sanitize_loyalty_tier($body['tier'] ?? 'bronze');
        if (!$this->validate_loyalty_tier($tier)) {
            return new WP_Error('mgws_bad_request', 'Invalid loyalty tier', array('status' => 400));
        }
        $result = MGWS_DB::upsert_loyalty_card((int) $customer->ID, $card_number, $tier);
        if (empty($result['ok'])) {
            if (($result['state'] ?? '') === 'conflict') {
                return new WP_Error('mgws_loyalty_card_conflict', 'Loyalty card already belongs to another customer', array('status' => 409));
            }
            return new WP_Error('mgws_error', 'Unable to save loyalty card', array('status' => 500));
        }
        return rest_ensure_response($this->loyalty_customer_response($customer, $result['account'] ?? null));
    }

    public function route_loyalty_card_delete(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $customer = $this->loyalty_customer_from_request($request);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $result = MGWS_DB::remove_loyalty_card((int) $customer->ID);
        if (empty($result['ok'])) {
            if (($result['state'] ?? '') === 'not_found') {
                return new WP_Error('mgws_loyalty_card_not_found', 'Loyalty card not found', array('status' => 404));
            }
            return new WP_Error('mgws_error', 'Unable to remove loyalty card', array('status' => 500));
        }
        return rest_ensure_response(array(
            'ok' => true,
            'deleted' => true,
            'customer_id' => (int) $customer->ID,
        ));
    }

    public function route_loyalty_points_add(WP_REST_Request $request) {
        return $this->route_loyalty_points_change($request, 'add');
    }

    public function route_loyalty_points_deduct(WP_REST_Request $request) {
        return $this->route_loyalty_points_change($request, 'deduct');
    }

    public function route_inventory_status(WP_REST_Request $request) {
        global $wpdb;
        $table = MGWS_DB::table_levels();
        $enabled = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return rest_ensure_response(array('ok' => true, 'service' => 'inventory', 'enabled' => $enabled));
    }

    public function route_inventory_product_stock(WP_REST_Request $request) {
        $product_id = (int) $request->get_param('product_id');
        $variation_id = (int) $request->get_param('variation_id');
        $product = $this->inventory_product($product_id, $variation_id);
        if (is_wp_error($product)) {
            return $product;
        }
        $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, $this->get_user_site_limit(get_current_user_id()));
        $records = $this->inventory_records($levels, array($product_id . ':' . $variation_id => $product));
        return rest_ensure_response($records[0] ?? $this->inventory_empty_record($product_id, $variation_id, $product));
    }

    public function route_inventory_stock_all(WP_REST_Request $request) {
        $levels = $this->inventory_levels($request);
        return is_wp_error($levels) ? $levels : rest_ensure_response($this->inventory_records($levels));
    }

    public function route_inventory_statistics(WP_REST_Request $request) {
        $levels = $this->inventory_levels($request);
        if (is_wp_error($levels)) {
            return $levels;
        }
        $records = $this->inventory_records($levels);
        $quantity = array_sum(array_map(static function ($record) { return (int) $record['current_stock']; }, $records));
        return rest_ensure_response(array(
            'total_products' => count(array_unique(array_map(static function ($record) { return (int) $record['product_id']; }, $records))),
            'total_variations' => count($records),
            'total_quantity' => $quantity,
            'total_locations' => count($levels),
        ));
    }

    public function route_inventory_low_stock(WP_REST_Request $request) {
        $threshold = $this->inventory_non_negative($request->get_param('threshold') ?? 5, 'threshold');
        if (is_wp_error($threshold)) {
            return $threshold;
        }
        $levels = $this->inventory_levels($request);
        if (is_wp_error($levels)) {
            return $levels;
        }
        $records = array_values(array_filter($this->inventory_records($levels), static function ($record) use ($threshold) {
            return (int) $record['current_stock'] <= $threshold;
        }));
        usort($records, static function ($left, $right) {
            return array((int) $left['current_stock'], (int) $left['product_id'], (int) $left['variation_id']) <=> array((int) $right['current_stock'], (int) $right['product_id'], (int) $right['variation_id']);
        });
        return rest_ensure_response($records);
    }

    public function route_inventory_stock_sync(WP_REST_Request $request) {
        $body = $this->inventory_body($request);
        $product_id = $this->inventory_positive($body['product_id'] ?? null, 'product_id');
        $variation_id = $this->inventory_non_negative($body['variation_id'] ?? 0, 'variation_id');
        $woo_stock = $this->inventory_non_negative($body['woo_stock'] ?? null, 'woo_stock');
        if (is_wp_error($product_id) || is_wp_error($variation_id) || is_wp_error($woo_stock)) {
            return is_wp_error($product_id) ? $product_id : (is_wp_error($variation_id) ? $variation_id : $woo_stock);
        }

        $sync_type = $body['sync_type'] ?? '';
        if (!$this->validate_inventory_text($sync_type)) {
            return new WP_Error('mgws_bad_request', 'sync_type must be valid text', array('status' => 400));
        }
        $sync_type = trim(sanitize_text_field((string) $sync_type));
        $product = $this->inventory_product($product_id, $variation_id);
        if (is_wp_error($product)) {
            return $product;
        }
        if ($product === null) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        $note = 'Inventory Woo sync';
        if ($sync_type !== '') {
            $note .= ' (' . $sync_type . ')';
        }
        // Il totale che arriva da WooCommerce e' un numero di negozio, e su un
        // negozio con una sola sede coincide con il totale di quella sede: li' lo
        // zero e' la sede, non una scorciatoia. Su un negozio con piu' sedi i due
        // numeri divergono, e allora il numero di WooCommerce non sa quale sede
        // riscrivere. Per questo la sede si dichiara anche qui, e chi non la
        // dichiara su un negozio multi-sede riceve un errore che lo dice, invece di
        // trovarsi un totale di negozio riversato su una sede a caso.
        $site_id = $this->inventory_non_negative($body['site_id'] ?? 0, 'site_id');
        if (is_wp_error($site_id)) {
            return $site_id;
        }
        $result = $this->inventory_set_total($product_id, $variation_id, $woo_stock, $note, '', null, $site_id);
        if (is_wp_error($result)) {
            return $result;
        }
        $this->sync_woo_stock($product_id, $variation_id);

        return rest_ensure_response(array_merge(array(
            'ok' => true,
            'operation' => 'stock_sync',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'site_id' => $site_id,
            'woo_stock' => $woo_stock,
            'sync_type' => $sync_type,
        ), $result));
    }

    public function route_inventory_stock_reconcile(WP_REST_Request $request) {
        $body = $this->inventory_body($request);
        $product_id = $this->inventory_positive($body['product_id'] ?? null, 'product_id');
        $variation_id = $this->inventory_non_negative($body['variation_id'] ?? 0, 'variation_id');
        $correct_stock = $this->inventory_non_negative($body['correct_stock'] ?? null, 'correct_stock');
        // La sede e' dichiarata, non dedotta. Zero non e' "tutte": e' l'assenza di
        // una sede, e come tale e' accettata solo quando il negozio ha una sede
        // sola, dove il totale del prodotto e il totale della sede sono lo stesso
        // numero. Il controllo e' qui e non solo nella funzione che scrive, cosi'
        // l'errore arriva prima che venga aperta una transazione.
        $site_id = $this->inventory_non_negative($body['site_id'] ?? 0, 'site_id');
        if (is_wp_error($product_id) || is_wp_error($variation_id) || is_wp_error($correct_stock) || is_wp_error($site_id)) {
            if (is_wp_error($product_id)) {
                return $product_id;
            }
            if (is_wp_error($variation_id)) {
                return $variation_id;
            }
            if (is_wp_error($correct_stock)) {
                return $correct_stock;
            }
            return $site_id;
        }
        if ($site_id === 0 && $this->inventory_requires_site_scope()) {
            return new WP_Error('mgws_bad_request', 'site_id is required: this shop has more than one site, and a total belongs to one of them', array('status' => 400));
        }

        $reason = $body['reason'] ?? '';
        if (!$this->validate_inventory_text($reason)) {
            return new WP_Error('mgws_bad_request', 'reason must be valid text', array('status' => 400));
        }
        $reason = trim(sanitize_text_field((string) $reason));
        $product = $this->inventory_product($product_id, $variation_id);
        if (is_wp_error($product)) {
            return $product;
        }
        if ($product === null) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        $audit_reason = $reason !== '' ? $reason : 'No reason provided';
        $movement_key = $this->inventory_movement_key($body);
        if (is_wp_error($movement_key)) {
            return $movement_key;
        }
        $result = $this->inventory_set_total($product_id, $variation_id, $correct_stock, 'Inventory reconcile: ' . $audit_reason, $movement_key, $audit_reason, $site_id);
        if (is_wp_error($result)) {
            return $result;
        }
        $this->sync_woo_stock($product_id, $variation_id);

        return rest_ensure_response(array_merge(array(
            'ok' => true,
            'operation' => 'stock_reconcile',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            // `site_id` torna indietro perche' i numeri nella risposta sono di
            // quella sede e solo di quella. Senza questo campo l'app non
            // potrebbe dire se quello che le e' arrivato e' il totale del negozio
            // o quello di un magazzino, e i due si sommano a cose diverse.
            'site_id' => $site_id,
            'correct_stock' => $correct_stock,
            'reason' => $reason,
        ), $result));
    }

    /**
     * Sposta pezzi da un magazzino a un altro.
     *
     * Lo spostamento non tocca il totale: la merce esce da una sede e entra in
     * un'altra, quindi il totale di magazzino resta quello che e'. Per questo non
     * passa da `reconcile` e non puo' passare da li': una rettifica registrerebbe
     * lo spostamento come se la merce fosse sparita o comparsa dal nulla.
     *
     * Una operazione di spostamento produce due righe di libro per prodotto, una
     * di uscita e una di entrata, e le due devono restare legate: stesso
     * `movement_id`, stessa `warehouse_from`/`warehouse_to`. Separate, non si
     * saprebbe piu' se la merce e' uscita dal magazzino o e' semplicemente finita
     * in un ordine.
     */
    public function route_inventory_stock_move(WP_REST_Request $request) {
        global $wpdb;

        $storage = $this->require_inventory_restock_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $body = $this->inventory_body($request);
        $product_id = $this->inventory_positive($body['product_id'] ?? null, 'product_id');
        $variation_id = $this->inventory_non_negative($body['variation_id'] ?? 0, 'variation_id');
        $from_warehouse_id = $this->inventory_positive($body['from_warehouse_id'] ?? null, 'from_warehouse_id');
        $to_warehouse_id = $this->inventory_positive($body['to_warehouse_id'] ?? null, 'to_warehouse_id');
        $quantity = $this->inventory_positive($body['quantity'] ?? null, 'quantity');
        if (is_wp_error($product_id) || is_wp_error($variation_id) || is_wp_error($from_warehouse_id) || is_wp_error($to_warehouse_id) || is_wp_error($quantity)) {
            return is_wp_error($product_id) ? $product_id : (is_wp_error($variation_id) ? $variation_id : (is_wp_error($from_warehouse_id) ? $from_warehouse_id : (is_wp_error($to_warehouse_id) ? $to_warehouse_id : $quantity)));
        }
        if ($from_warehouse_id === $to_warehouse_id) {
            return new WP_Error('mgws_bad_request', 'Source and destination warehouses must be different', array('status' => 400));
        }
        // `from_site_id` e `to_site_id` arrivano dalla app ma non vengono usati:
        // il sito e' una proprieta' del magazzino, e accettare quello dichiarato
        // dal client aprirebbe la porta a spostare merce fuori dal perimetro
        // consentito dichiarando una partenza diversa da quella reale.

        $reason = $body['reason'] ?? '';
        if (!$this->validate_inventory_text($reason)) {
            return new WP_Error('mgws_bad_request', 'reason must be valid text', array('status' => 400));
        }
        $reason = trim(sanitize_text_field((string) $reason));
        $movement_key = $this->inventory_movement_key($body);
        if (is_wp_error($movement_key)) {
            return $movement_key;
        }
        $note = $this->inventory_restock_text($body['note'] ?? '', 'note', 4000, false, true);
        if (is_wp_error($note)) {
            return $note;
        }

        $product = $this->inventory_product($product_id, $variation_id);
        if (is_wp_error($product)) {
            return $product;
        }
        if ($product === null) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        // Il sito lo stabilisce il magazzino, non la richiesta: fidarsi del
        // `from_site_id` del client permetterebbe di spostare merce fuori dal
        // perimetro consentito dichiarando una partenza diversa da quella reale.
        $resolved_from_site = MGWS_DB::get_site_id_for_warehouse($from_warehouse_id);
        $resolved_to_site = MGWS_DB::get_site_id_for_warehouse($to_warehouse_id);
        if ($resolved_from_site <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid source warehouse', array('status' => 400));
        }
        if ($resolved_to_site <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid destination warehouse', array('status' => 400));
        }
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_limit > 0 && ((int) $resolved_from_site !== (int) $site_limit || (int) $resolved_to_site !== (int) $site_limit)) {
            return new WP_Error('mgws_forbidden', 'Warehouse outside allowed site', array('status' => 403));
        }

        $from_level = $this->inventory_move_level($body, 'from_', $product_id, $variation_id, (int) $resolved_from_site);
        if (is_wp_error($from_level)) {
            return $from_level;
        }
        $to_level = $this->inventory_move_level($body, 'to_', $product_id, $variation_id, (int) $resolved_to_site);
        if (is_wp_error($to_level)) {
            return $to_level;
        }

        $before = MGWS_DB::get_level_row($from_warehouse_id, $product_id, $variation_id, $from_level['room'], $from_level['rack'], $from_level['shelf']);
        $from_before = (int) ($before['qty'] ?? 0);
        $to_before_row = MGWS_DB::get_level_row($to_warehouse_id, $product_id, $variation_id, $to_level['room'], $to_level['rack'], $to_level['shelf']);
        $to_before = (int) ($to_before_row['qty'] ?? 0);

        $movement_id = $movement_key === ''
            ? 0
            : MGWS_DB::ensure_movement($movement_key, 'move', get_current_user_id(), (int) $resolved_from_site, 'move', $note !== '' ? $note : null);

        $wpdb->query('START TRANSACTION');
        $from_res = MGWS_DB::apply_delta_level($from_warehouse_id, $product_id, $variation_id, -$quantity, $from_level['room'], $from_level['rack'], $from_level['shelf']);
        if (empty($from_res['ok'])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mgws_conflict', (string) ($from_res['message'] ?? __('Not enough stock in the source warehouse', 'mg-warehouse-stock')), array('status' => 409));
        }
        $to_res = MGWS_DB::apply_delta_level($to_warehouse_id, $product_id, $variation_id, $quantity, $to_level['room'], $to_level['rack'], $to_level['shelf']);
        if (empty($to_res['ok'])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mgws_conflict', (string) ($to_res['message'] ?? __('Could not record the entry into the destination warehouse', 'mg-warehouse-stock')), array('status' => 409));
        }

        $audit_reason = $reason !== '' ? $reason : 'No reason provided';
        $audit_note = 'Inventory move: ' . $audit_reason
            . '; from ' . $from_warehouse_id . ' to ' . $to_warehouse_id
            . '; qty ' . $quantity;
        if ($note !== '') {
            $audit_note .= '; note: ' . $note;
        }
        $shared = array(
            'movement_id' => (int) $movement_id,
            'stock_effect' => 'move',
            'reason_code' => 'move',
            'source_type' => 'move',
        );
        $out_row = MGWS_DB::insert_move(
            'out',
            (int) $resolved_from_site,
            $from_warehouse_id,
            $product_id,
            $variation_id,
            $quantity,
            $from_level['room'],
            $from_level['rack'],
            $from_level['shelf'],
            get_current_user_id(),
            0,
            $audit_note,
            $from_warehouse_id,
            $to_warehouse_id,
            array_merge($shared, array('stock_before' => $from_before, 'stock_after' => $from_before - $quantity))
        );
        $in_row = MGWS_DB::insert_move(
            'in',
            (int) $resolved_to_site,
            $to_warehouse_id,
            $product_id,
            $variation_id,
            $quantity,
            $to_level['room'],
            $to_level['rack'],
            $to_level['shelf'],
            get_current_user_id(),
            0,
            $audit_note,
            $from_warehouse_id,
            $to_warehouse_id,
            array_merge($shared, array('stock_before' => $to_before, 'stock_after' => $to_before + $quantity))
        );
        if (!$out_row || !$in_row) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mgws_error', 'Unable to record inventory move', array('status' => 500));
        }
        $wpdb->query('COMMIT');

        return rest_ensure_response(array(
            'ok' => true,
            'operation' => 'stock_move',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'quantity' => $quantity,
            'from_site_id' => (int) $resolved_from_site,
            'from_warehouse_id' => $from_warehouse_id,
            'to_site_id' => (int) $resolved_to_site,
            'to_warehouse_id' => $to_warehouse_id,
            'reason' => $reason,
            'movement_id' => (int) $movement_id,
            'ledger_movement_ids' => array((int) $out_row, (int) $in_row),
            'from_location' => $from_level,
            'to_location' => $to_level,
        ));
    }

    /**
     * Livello di partenza o arrivo dello spostamento.
     *
     * Il prefisso distingue i due lati (`from_` / `to_`) cosi' la stessa funzione
     * vale per entrambi. Quando l'app non manda stanza, scaffale o mensola si
     * prende il posto dove il prodotto sta gia', cosi' la merce non cambia
     * scaffale solo perche' il campo e' rimasto vuoto.
     */
    private function inventory_move_level($body, $prefix, $product_id, $variation_id, $site_id) {
        $room = $this->inventory_restock_text($body[$prefix . 'room'] ?? '', $prefix . 'room', 80);
        $rack = $this->inventory_restock_text($body[$prefix . 'rack'] ?? '', $prefix . 'rack', 80);
        $shelf = $this->inventory_restock_text($body[$prefix . 'shelf'] ?? '', $prefix . 'shelf', 80);
        if (is_wp_error($room) || is_wp_error($rack) || is_wp_error($shelf)) {
            return is_wp_error($room) ? $room : (is_wp_error($rack) ? $rack : $shelf);
        }
        $warehouse_id = $prefix === 'to_' ? (int) $body['to_warehouse_id'] : (int) $body['from_warehouse_id'];

        if ($room === '' && $rack === '' && $shelf === '') {
            foreach (MGWS_DB::get_levels_for_item($product_id, $variation_id, $site_id) as $level) {
                if ((int) ($level['warehouse_id'] ?? 0) === $warehouse_id) {
                    $room = (string) ($level['room'] ?? '');
                    $rack = (string) ($level['rack'] ?? '');
                    $shelf = (string) ($level['shelf'] ?? '');
                    break;
                }
            }
        }

        return array('site_id' => (int) $site_id, 'warehouse_id' => $warehouse_id, 'room' => $room, 'rack' => $rack, 'shelf' => $shelf);
    }

    public function route_inventory_rfid_scan(WP_REST_Request $request) {
        $body = $this->inventory_body($request);
        if (!isset($body['tags']) || !$this->validate_inventory_tags($body['tags'])) {
            return new WP_Error('mgws_bad_request', 'tags must be an array', array('status' => 400));
        }
        $tags = $this->sanitize_inventory_tags($body['tags']);
        $resolved = array();
        $unresolved = array();

        foreach ($tags as $tag) {
            $product_id = $this->find_product_id_by_barcode($tag);
            if ($product_id <= 0) {
                $unresolved[] = array('tag' => $tag, 'reason' => 'tag_not_found');
                continue;
            }

            $variation_id = 0;
            $product_name = '';
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product && $product->is_type('variation')) {
                    $variation_id = $product_id;
                    $product_id = (int) $product->get_parent_id();
                }
                if ($product && method_exists($product, 'get_name')) {
                    $product_name = (string) $product->get_name();
                }
            }

            $resolved[] = array(
                'tag' => $tag,
                'product_id' => (int) $product_id,
                'variation_id' => $variation_id,
                'product_name' => $product_name,
            );
        }

        return rest_ensure_response(array(
            'ok' => true,
            'operation' => 'rfid_scan',
            'mode' => 'resolve_only',
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'summary' => array(
                'submitted' => count($tags),
                'resolved' => count($resolved),
                'unresolved' => count($unresolved),
                'stock_updates' => 0,
                'movement_count' => 0,
            ),
            'note' => 'RFID tags are resolved as stored barcodes only; no stock mutation is inferred from a scan.',
        ));
    }

    private function inventory_set_total($product_id, $variation_id, $target_stock, $audit_note, $movement_key = '', $movement_note = null, $site_id = 0) {
        global $wpdb;

        // La sede non si indovina: la dichiara chi corregge. Il totale di un
        // prodotto non e' un numero che sta a se' e' la somma di quello che c'e'
        // in un posto: se lo si scrive senza dire quale, si puo' correggere una
        // sede guardando il totale di un'altra, e l'operatore vede un numero che
        // non tornera' piu'.
        //
        // La dichiarazione e' obbligatoria quando il negozio ha piu' di una sede,
        // e non e' richiesta quando ne ha una sola. La differenza non e' una
        // formalita': con una sola sede "il totale del prodotto" e "il totale
        // della sede" sono lo stesso numero, e chiedere di dichiararlo non
        // aggiungerebbe sicurezza, toglierebbe solo un campo. Con piu' sedi i due
        // numeri divergono, e allora la sede dichiarata e' l'unica cosa che dice
        // quale dei due e' stato scritto.
        $site_id = (int) $site_id;
        $site_limit = $this->get_user_site_limit(get_current_user_id());
        if ($site_id > 0) {
            // Il limite dell'utente non serve a scegliere la sede, serve a vietare
            // quella che l'utente non puo' toccare. Chi ha una sede di riferimento
            // puo' correggere il totale di *quella* sede, che e' il senso di "sede
            // obbligatoria": il dato che corregge e' il suo. Chi non ha un limite
            // puo' correggere qualunque sede, ma deve comunque dire quale.
            if ($site_limit > 0 && $site_id !== $site_limit) {
                return new WP_Error('mgws_forbidden', 'Site outside allowed site', array('status' => 403));
            }
            // Il totale corrente si somma sugli stessi livelli filtrati per la sede.
            // `sum_qty_for_item` somma tutte le sedi e produrrebbe una differenza
            // calcolata su un numero che l'operatore non vede da nessuna parte: se
            // dichiarasse come totale quello che vede, la differenza sarebbe diversa
            // da zero e la rettifica azzererebbe una quantita' reale.
            $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, $site_id);
        } else {
            if ($site_limit > 0) {
                return new WP_Error('mgws_forbidden', 'A site-scoped operator cannot write a total that spans every site', array('status' => 403));
            }
            if ($this->inventory_requires_site_scope()) {
                // Difesa in profondita': la rotta gia' rifiuta lo zero quando le
                // sedi sono piu' di una, quindi qui non dovrebbe arrivare. Se
                // arrivasse, e' un chiamante interno che ha dimenticato di dire la
                // sede, e fallire e' meglio che scrivere un totale su tutte le sedi
                // senza che nessuno lo abbia chiesto.
                return new WP_Error('mgws_bad_request', 'site_id is required: this shop has more than one site, and a total belongs to one of them', array('status' => 400));
            }
            $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id);
        }
        $current_stock = 0;
        foreach ($levels as $level) {
            $current_stock += (int) ($level['qty'] ?? 0);
        }
        $delta = (int) $target_stock - $current_stock;
        $movements = 0;

        // Una rettifica e' un'operazione sola, anche se il backend la applica
        // su piu' magazzini e l'app la invia una riga per volta. L'intestazione
        // si crea qui, una volta, e ogni riga che ne discende la referenzia.
        //
        // La sede dell'intestazione e' quella dichiarata, non quella della prima
        // riga: se il prodotto non ha ancora livelli in quella sede l'aumento
        // crea il primo livello proprio li', quindi la riga ce l'avra' ma
        // l'intestazione, se leggesse i livelli, direbbe che l'operazione e'
        // avvenuta da un'altra parte.
        $movement_site_id = $site_id > 0
            ? $site_id
            : (int) ($levels[0]['site_id'] ?? 0);
        $movement_id = $movement_key === ''
            ? 0
            : MGWS_DB::ensure_movement($movement_key, 'reconcile', get_current_user_id(), $movement_site_id, 'reconcile', $movement_note);

        $wpdb->query('START TRANSACTION');
        if ($delta > 0) {
            // Se il prodotto non ha ancora un livello, i pezzi entrano da
            // qualche parte. Il magazzino e' una divisione virtuale dentro la sede
            // ("scarpe", "t-shirt"), quindi il primo magazzino per titolo non
            // dice niente di questo prodotto: un paio di scarpe finirebbe nel
            // magazzino delle magliette. La scelta che ha senso e' il magazzino
            // predefinito del prodotto, che e' gia' la sua divisione, e si usa solo
            // se sta nella sede dichiarata: e' la protezione che manca a
            // `resolve_pos_return_location`, che controlla la coerenza interna ma
            // non confronta mai con la sede. Se il prodotto non ha un magazzino o
            // il suo e' in un'altra sede, si ripiega sul primo magazzino della sede
            // stessa, che e' comunque dentro il perimetro.
            if (empty($levels)) {
                $preferred = $this->resolve_pos_preferred_location($product_id, $variation_id);
                $preferred_site = (int) ($preferred['site_id'] ?? 0);
                if ($site_id <= 0) {
                    $level = $preferred;
                } elseif ($preferred_site === $site_id) {
                    $level = $preferred;
                } else {
                    $level = $this->resolve_first_available_warehouse_location($site_id);
                }
            } else {
                $level = $levels[0];
            }
            $warehouse_id = (int) ($level['warehouse_id'] ?? 0);
            if ($warehouse_id <= 0) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', $site_id > 0
                    ? 'Unable to resolve a warehouse in the requested site for inventory total'
                    : 'Unable to resolve stock location for inventory total', array('status' => 409));
            }
            $applied = $this->inventory_apply_total_delta($product_id, $variation_id, $delta, $level, $audit_note, $current_stock, $target_stock, $movement_id);
            if (is_wp_error($applied)) {
                $wpdb->query('ROLLBACK');
                return $applied;
            }
            $movements++;
        } elseif ($delta < 0) {
            $remaining = abs($delta);
            foreach ($levels as $level) {
                if ($remaining <= 0) {
                    break;
                }
                $available = (int) ($level['qty'] ?? 0);
                if ($available <= 0) {
                    continue;
                }
                $applied_delta = -min($available, $remaining);
                $applied = $this->inventory_apply_total_delta($product_id, $variation_id, $applied_delta, $level, $audit_note, $current_stock, $target_stock, $movement_id);
                if (is_wp_error($applied)) {
                    $wpdb->query('ROLLBACK');
                    return $applied;
                }
                $remaining += $applied_delta;
                $movements++;
            }
            if ($remaining > 0) {
                $wpdb->query('ROLLBACK');
                // Qui non si puo' distinguere il motivo: la correzione richiesta
                // puo' superare cio' che la sede contiene, oppure il totale puo'
                // essere cambiato da un'altra operazione mentre questa scorreva. Il
                // solo caso in cui il totale scende piu' di quanto la sede abbia e'
                // chiedere un numero che non esiste, e quello va detto per primo
                // perche' e' quello che l'operatore deve correggere.
                return new WP_Error('mgws_conflict', 'Cannot lower the site total to the requested value: the site holds less, or stock changed while reconciling', array('status' => 409));
            }
        } elseif (!empty($levels)) {
            $applied = $this->inventory_apply_total_delta($product_id, $variation_id, 0, $levels[0], $audit_note, $current_stock, $target_stock, $movement_id);
            if (is_wp_error($applied)) {
                $wpdb->query('ROLLBACK');
                return $applied;
            }
            $movements++;
        }
        $wpdb->query('COMMIT');

        return array(
            'previous_stock' => $current_stock,
            'current_stock' => (int) $target_stock,
            'delta' => $delta,
            'movement_count' => $movements,
            'movement_id' => (int) $movement_id,
        );
    }

    private function inventory_apply_total_delta($product_id, $variation_id, $delta, $level, $audit_note, $previous_stock, $target_stock, $movement_id = 0) {
        $warehouse_id = (int) ($level['warehouse_id'] ?? 0);
        $site_id = (int) ($level['site_id'] ?? MGWS_DB::get_site_id_for_warehouse($warehouse_id));
        $room = (string) ($level['room'] ?? '');
        $rack = (string) ($level['rack'] ?? '');
        $shelf = (string) ($level['shelf'] ?? '');
        if ($warehouse_id <= 0 || $site_id <= 0) {
            return new WP_Error('mgws_conflict', 'Unable to resolve stock location for inventory total', array('status' => 409));
        }

        $updated = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, $delta, $room, $rack, $shelf);
        if (empty($updated['ok'])) {
            return new WP_Error('mgws_conflict', (string) ($updated['message'] ?? 'Unable to update stock level'), array('status' => 409));
        }

        $note = $audit_note . ': total ' . (int) $previous_stock . ' -> ' . (int) $target_stock . ' (delta ' . (int) $delta . ')';
        // Stock prima e dopo vanno in colonne, non solo nella nota: sono il dato
        // che serve a un contromovimento per sapere a cosa tornare, e rileggerlo
        // da una stringa che qualcuno puo' riscrivere e' mettere in piedi una
        // cancellazione su un numero indovinato.
        $written = MGWS_DB::insert_move(
            'adjust',
            $site_id,
            $warehouse_id,
            $product_id,
            $variation_id,
            $delta,
            $room,
            $rack,
            $shelf,
            get_current_user_id(),
            0,
            $note,
            0,
            0,
            array(
                'movement_id' => (int) $movement_id,
                'stock_before' => (int) $previous_stock,
                'stock_after' => (int) $target_stock,
                'stock_effect' => 'adjust',
                'reason_code' => 'reconcile',
            )
        );
        if (!$written) {
            return new WP_Error('mgws_error', 'Unable to record inventory movement', array('status' => 500));
        }

        return true;
    }

    public function route_loyalty_history(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $customer = $this->loyalty_customer_from_request($request);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $page = $this->sanitize_loyalty_positive_int($request->get_param('page') ?? 1);
        $per_page = $this->sanitize_loyalty_positive_int($request->get_param('per_page') ?? 20);
        if (!$this->validate_loyalty_positive_int($page) || !$this->validate_loyalty_per_page($per_page)) {
            return new WP_Error('mgws_bad_request', 'Invalid pagination', array('status' => 400));
        }
        return rest_ensure_response(MGWS_DB::get_loyalty_history((int) $customer->ID, $page, $per_page));
    }

    public function route_loyalty_stats(WP_REST_Request $request) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        return rest_ensure_response(array_merge(array('ok' => true), MGWS_DB::get_loyalty_stats()));
    }

    public function sanitize_loyalty_positive_int($value) {
        return max(0, (int) $value);
    }

    public function validate_loyalty_positive_int($value) {
        if (!is_scalar($value)) {
            return false;
        }
        $value = (string) $value;
        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    public function validate_loyalty_per_page($value) {
        return $this->validate_loyalty_positive_int($value) && (int) $value <= 100;
    }

    public function validate_loyalty_reference($value) {
        return is_scalar($value) && strlen((string) $value) <= 191;
    }

    public function validate_loyalty_note($value) {
        return is_scalar($value) && strlen((string) $value) <= 4000;
    }

    public function sanitize_loyalty_card_number($value) {
        return trim(sanitize_text_field(rawurldecode((string) $value)));
    }

    public function validate_loyalty_card_number($value) {
        $value = (string) $value;
        return strlen($value) <= 191 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1;
    }

    public function sanitize_loyalty_email($value) {
        return sanitize_email(rawurldecode((string) $value));
    }

    public function validate_loyalty_email($value) {
        $value = (string) $value;
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sanitize_loyalty_tier($value) {
        return sanitize_key((string) $value);
    }

    public function validate_loyalty_tier($value) {
        return preg_match('/^[a-z0-9_-]{1,32}$/', (string) $value) === 1;
    }

    private function require_loyalty_storage() {
        if (!MGWS_DB::loyalty_tables_exist()) {
            return new WP_Error('mgws_unavailable', 'Loyalty storage is not configured', array('status' => 503));
        }
        return true;
    }

    private function loyalty_customer_from_request(WP_REST_Request $request) {
        $customer_id = $this->sanitize_loyalty_positive_int($request->get_param('customer_id'));
        if (!$this->validate_loyalty_positive_int($customer_id)) {
            return new WP_Error('mgws_bad_request', 'Invalid customer identifier', array('status' => 400));
        }
        return $this->loyalty_customer_by_id($customer_id);
    }

    private function loyalty_customer_by_id($customer_id) {
        $customer_id = (int) $customer_id;
        $customer = $customer_id > 0 ? get_user_by('id', $customer_id) : false;
        if (!$customer) {
            return new WP_Error('mgws_loyalty_customer_not_found', 'Loyalty customer not found', array('status' => 404));
        }
        return $customer;
    }

    private function loyalty_customer_response($customer, $account = null) {
        if (!is_array($account)) {
            $account = MGWS_DB::get_loyalty_account((int) $customer->ID);
        }
        $first_name = (string) get_user_meta((int) $customer->ID, 'first_name', true);
        $last_name = (string) get_user_meta((int) $customer->ID, 'last_name', true);
        if ($first_name === '') {
            $first_name = (string) get_user_meta((int) $customer->ID, 'billing_first_name', true);
        }
        if ($last_name === '') {
            $last_name = (string) get_user_meta((int) $customer->ID, 'billing_last_name', true);
        }
        return array(
            'user_id' => (int) $customer->ID,
            'customer_id' => (int) $customer->ID,
            'card_number' => is_array($account) && !empty($account['card_number']) ? (string) $account['card_number'] : null,
            'tier' => is_array($account) ? (string) ($account['tier'] ?? 'bronze') : 'bronze',
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => (string) $customer->user_email,
            'points' => is_array($account) ? (int) ($account['points_balance'] ?? 0) : 0,
        );
    }

    private function loyalty_request_body(WP_REST_Request $request) {
        $body = $request->get_json_params();
        return is_array($body) ? $body : array();
    }

    private function route_loyalty_points_change(WP_REST_Request $request, $direction) {
        $storage = $this->require_loyalty_storage();
        if (is_wp_error($storage)) {
            return $storage;
        }
        $customer = $this->loyalty_customer_from_request($request);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $body = $this->loyalty_request_body($request);
        $raw_points = $body['points'] ?? null;
        if (!$this->validate_loyalty_positive_int($raw_points)) {
            return new WP_Error('mgws_bad_request', 'points must be a positive integer', array('status' => 400));
        }
        $result = MGWS_DB::change_loyalty_points(
            (int) $customer->ID,
            (int) $raw_points,
            $direction,
            (string) ($body['reference'] ?? ''),
            (string) ($body['note'] ?? ''),
            get_current_user_id()
        );
        if (empty($result['ok'])) {
            if (($result['state'] ?? '') === 'insufficient_points') {
                return new WP_Error('mgws_insufficient_points', 'Insufficient loyalty points', array('status' => 400));
            }
            return new WP_Error('mgws_error', 'Unable to update loyalty points', array('status' => 500));
        }
        return rest_ensure_response(array(
            'ok' => true,
            'customer_id' => (int) $customer->ID,
            'points' => (int) $result['points'],
            'movement_id' => (int) $result['movement_id'],
        ));
    }

    public function route_stock_levels(WP_REST_Request $request) {
        $product_id = (int) $request->get_param('product_id');
        $variation_id = (int) $request->get_param('variation_id');
        $item_ok = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($item_ok)) {
            return $item_ok;
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
        unset($lv);

        $moves = MGWS_DB::get_moves(array(
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'site_id' => $site_limit > 0 ? $site_limit : 0,
        ), 50);

        return rest_ensure_response(array(
            'levels' => $levels,
            'moves' => $moves,
        ));
    }

    public function route_stock_move(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $operation = isset($body['operation']) ? sanitize_key((string) $body['operation']) : 'adjust';
        $warehouse_id = (int) ($body['warehouse_id'] ?? 0);
        $to_warehouse_id = (int) ($body['to_warehouse_id'] ?? 0);
        $product_id = (int) ($body['product_id'] ?? 0);
        $variation_id = (int) ($body['variation_id'] ?? 0);
        $qty = (int) ($body['qty'] ?? 0);

        $item_ok = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($item_ok)) {
            return $item_ok;
        }

        $room = (string) ($body['room'] ?? '');
        $rack = (string) ($body['rack'] ?? '');
        $shelf = (string) ($body['shelf'] ?? '');
        $to_room = (string) ($body['to_room'] ?? '');
        $to_rack = (string) ($body['to_rack'] ?? '');
        $to_shelf = (string) ($body['to_shelf'] ?? '');

        $site_limit = $this->get_user_site_limit(get_current_user_id());
        $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
        if ($warehouse_id <= 0 || $wh_site <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid warehouse', array('status' => 400));
        }
        if ($site_limit > 0 && (int) $wh_site !== (int) $site_limit) {
            return new WP_Error('mgws_forbidden', 'Warehouse outside allowed site', array('status' => 403));
        }

        $user_id = get_current_user_id();

        if ($operation === 'adjust') {
            if ($qty < 0) {
                return new WP_Error('mgws_bad_request', 'qty must be >= 0 for adjust', array('status' => 400));
            }
            $old = MGWS_DB::get_level_row($warehouse_id, $product_id, $variation_id, $room, $rack, $shelf);
            $old_qty = (int) ($old['qty'] ?? 0);
            $res = MGWS_DB::upsert_level($warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                return new WP_Error('mgws_conflict', (string) ($res['message'] ?? 'Error'), array('status' => 409));
            }
            $delta = (int) $qty - $old_qty;
            MGWS_DB::insert_move('adjust', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $delta, $room, $rack, $shelf, $user_id, 0, 'API adjust');
            $this->sync_woo_stock($product_id, $variation_id);
            return rest_ensure_response(array('ok' => true, 'message' => 'Updated'));
        }

        if ($operation === 'in') {
            if ($qty <= 0) {
                return new WP_Error('mgws_bad_request', 'qty must be > 0', array('status' => 400));
            }
            $res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                return new WP_Error('mgws_conflict', (string) ($res['message'] ?? 'Error'), array('status' => 409));
            }
            MGWS_DB::insert_move('in', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'API in');
            $this->sync_woo_stock($product_id, $variation_id);
            return rest_ensure_response(array('ok' => true, 'message' => 'In registered'));
        }

        if ($operation === 'out') {
            if ($qty <= 0) {
                return new WP_Error('mgws_bad_request', 'qty must be > 0', array('status' => 400));
            }
            $res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, -$qty, $room, $rack, $shelf);
            if (!$res['ok']) {
                return new WP_Error('mgws_conflict', (string) ($res['message'] ?? 'Error'), array('status' => 409));
            }
            MGWS_DB::insert_move('out', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'API out');
            $this->sync_woo_stock($product_id, $variation_id);
            return rest_ensure_response(array('ok' => true, 'message' => 'Out registered'));
        }

        if ($operation === 'transfer') {
            if ($qty <= 0) {
                return new WP_Error('mgws_bad_request', 'qty must be > 0', array('status' => 400));
            }
            if ($to_warehouse_id <= 0) {
                return new WP_Error('mgws_bad_request', 'Select destination warehouse', array('status' => 400));
            }
            if ($to_warehouse_id === $warehouse_id) {
                return new WP_Error('mgws_bad_request', 'Source and destination warehouses must be different', array('status' => 400));
            }
            $to_site = MGWS_DB::get_site_id_for_warehouse($to_warehouse_id);
            if ($to_site <= 0) {
                return new WP_Error('mgws_bad_request', 'Invalid destination', array('status' => 400));
            }
            if ($site_limit > 0 && (int) $to_site !== (int) $site_limit) {
                return new WP_Error('mgws_forbidden', 'Destination outside allowed site', array('status' => 403));
            }

            global $wpdb;
            $wpdb->query('START TRANSACTION');
            $from_res = MGWS_DB::apply_delta_level($warehouse_id, $product_id, $variation_id, -$qty, $room, $rack, $shelf);
            if (!$from_res['ok']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', (string) ($from_res['message'] ?? 'Error'), array('status' => 409));
            }
            $to_res = MGWS_DB::apply_delta_level($to_warehouse_id, $product_id, $variation_id, $qty, $to_room, $to_rack, $to_shelf);
            if (!$to_res['ok']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mgws_conflict', (string) ($to_res['message'] ?? 'Error'), array('status' => 409));
            }

            MGWS_DB::insert_move('out', (int) $wh_site, $warehouse_id, $product_id, $variation_id, $qty, $room, $rack, $shelf, $user_id, 0, 'API transfer out', $warehouse_id, $to_warehouse_id);
            MGWS_DB::insert_move('in', (int) $to_site, $to_warehouse_id, $product_id, $variation_id, $qty, $to_room, $to_rack, $to_shelf, $user_id, 0, 'API transfer in', $warehouse_id, $to_warehouse_id);
            $wpdb->query('COMMIT');

            $this->sync_woo_stock($product_id, $variation_id);
            return rest_ensure_response(array('ok' => true, 'message' => 'Transfer registered'));
        }

        return new WP_Error('mgws_bad_request', 'Invalid operation', array('status' => 400));
    }

    public function route_order_accept(WP_REST_Request $request) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('mgws_unavailable', 'WooCommerce not available', array('status' => 503));
        }

        $order_id = (int) $request->get_param('order_id');
        if ($order_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid order_id', array('status' => 400));
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('mgws_not_found', 'Order not found', array('status' => 404));
        }

        $already = (int) get_post_meta($order_id, '_mgws_accept_committed', true);
        if ($already === 1) {
            return new WP_Error('mgws_conflict', 'Order already accepted', array('status' => 409));
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $allow_partial = !empty($body['allow_partial']);
        $cards_in = is_array($body['cards'] ?? null) ? $body['cards'] : array();
        if (empty($cards_in)) {
            return new WP_Error('mgws_bad_request', 'cards required', array('status' => 400));
        }

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
            if (!is_array($card)) {
                continue;
            }
            $pid = (int) ($card['product_id'] ?? 0);
            $vid = (int) ($card['variation_id'] ?? 0);
            if ($pid <= 0 || $vid < 0) {
                continue;
            }
            $card_key = $pid . ':' . $vid;
            if (!isset($required_map[$card_key])) {
                return new WP_Error('mgws_bad_request', 'Card not present in order: ' . $card_key, array('status' => 400));
            }
            $touched_items[$card_key] = array('product_id' => $pid, 'variation_id' => $vid);

            $allocs = is_array($card['allocations'] ?? null) ? $card['allocations'] : array();
            foreach ($allocs as $a) {
                if (!is_array($a)) {
                    continue;
                }
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
                $wh_site = MGWS_DB::get_site_id_for_warehouse($warehouse_id);
                if ($wh_site <= 0 || (int) $wh_site !== (int) $site_id) {
                    return new WP_Error('mgws_bad_request', 'Invalid allocation warehouse/site', array('status' => 400));
                }
                if ($site_limit > 0 && (int) $site_id !== (int) $site_limit) {
                    return new WP_Error('mgws_forbidden', 'Allocation outside allowed site', array('status' => 403));
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

        if (empty($allocations)) {
            return new WP_Error('mgws_bad_request', 'No allocations provided', array('status' => 400));
        }

        foreach ($required_map as $k => $req) {
            $alloc = (int) ($allocated_by_card[$k] ?? 0);
            if ($alloc > (int) $req) {
                return new WP_Error('mgws_bad_request', 'Allocated qty exceeds required for ' . $k, array('status' => 400));
            }
        }
        if (!$allow_partial) {
            foreach ($required_map as $k => $req) {
                $alloc = (int) ($allocated_by_card[$k] ?? 0);
                if ($alloc < (int) $req) {
                    return new WP_Error('mgws_bad_request', 'Insufficient qty for full acceptance', array('status' => 400));
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
            return new WP_Error('mgws_conflict', (string) ($result['message'] ?? 'Error'), array('status' => 409));
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

        $note = __('Order accepted (API).', 'mg-warehouse-stock');
        if ($allocation_audit['totals']['total_remaining'] > 0) {
            $note .= ' ' . sprintf(
                __('Warning: not enough stock for %d units.', 'mg-warehouse-stock'),
                (int) $allocation_audit['totals']['total_remaining']
            );
        }
        $order->add_order_note($note, false, true);
        $order->update_status('mg-accepted');

        return rest_ensure_response(array('ok' => true, 'message' => 'Order accepted', 'totals' => $allocation_audit['totals']));
    }

    public function route_pos_checkout(WP_REST_Request $request) {
        if (!function_exists('wc_create_order')) {
            return new WP_Error('mgws_unavailable', 'WooCommerce not available', array('status' => 503));
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $idempotency_key = $this->get_pos_idempotency_key($body);
        if (is_wp_error($idempotency_key)) {
            return $idempotency_key;
        }

        $sale_items = $this->parse_pos_items($body['sale_items'] ?? array(), 'sale');
        if (is_wp_error($sale_items)) {
            return $sale_items;
        }
        $return_items = $this->parse_pos_items($body['return_items'] ?? array(), 'return');
        if (is_wp_error($return_items)) {
            return $return_items;
        }
        if (empty($sale_items) && empty($return_items)) {
            return new WP_Error('mgws_bad_request', 'At least one sale or return item is required', array('status' => 400));
        }
        $return_reference_validation = $this->validate_pos_return_references($return_items);
        if (is_wp_error($return_reference_validation)) {
            return $return_reference_validation;
        }

        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : array();
        $meta_data = is_array($body['meta_data'] ?? null) ? $body['meta_data'] : array();
        $totals = is_array($body['totals'] ?? null) ? $body['totals'] : array();
        $payment_method = sanitize_key((string) ($body['payment_method'] ?? ''));
        $payment_method_title = sanitize_text_field((string) ($body['payment_method_title'] ?? ''));
        $operation_type = sanitize_key((string) ($body['operation_type'] ?? 'sale'));
        $effective_operation_type = sanitize_key((string) ($body['effective_operation_type'] ?? $operation_type));
        $set_paid = !empty($body['set_paid']);
        $customer_note = isset($body['note']) ? sanitize_textarea_field((string) $body['note']) : '';
        $site_limit = $this->get_user_site_limit(get_current_user_id());

        $payload_hash = '';
        if ($idempotency_key !== '') {
            $payload_hash = $this->get_pos_idempotency_payload_hash($body);
            if (is_wp_error($payload_hash)) {
                return $payload_hash;
            }
            $existing_record = MGWS_DB::get_pos_idempotency($idempotency_key);
            if (is_array($existing_record)) {
                return $this->get_existing_pos_idempotency_response($idempotency_key, $payload_hash, $existing_record);
            }
        }

        // Enforcement turno cassa (server-side): ogni checkout POS deve riferire
        // uno shift aperto registrato in mg_pos_shifts. Il blocco avviene dopo il
        // replay idempotente per non far fallire le risposte gia registrate.
        $pos_shift_id = 0;
        $pos_shift_key = '';
        if ($this->pos_turno_obbligatorio()) {
            $pos_shift_key = trim((string) ($body['shift_id'] ?? ''));
            if ($pos_shift_key === '') {
                foreach ($meta_data as $meta) {
                    if (is_array($meta) && (string) ($meta['key'] ?? '') === '_turno_id') {
                        $pos_shift_key = trim((string) ($meta['value'] ?? ''));
                        break;
                    }
                }
            }
            if ($pos_shift_key === '') {
                return new WP_Error('mgws_shift_required', 'POS checkout requires an open shift (shift_id or _turno_id)', array('status' => 409));
            }
            if (preg_match('/^[0-9]+$/', $pos_shift_key) === 1) {
                $pos_shift_row = MGWS_DB::get_pos_shift_by_id((int) $pos_shift_key);
            } else {
                $pos_shift_row = MGWS_DB::get_pos_shift_by_key($pos_shift_key);
            }
            if (!is_array($pos_shift_row)) {
                return new WP_Error('mgws_shift_not_found', 'Shift not found: open a shift from the POS before checking out', array('status' => 409));
            }
            if (($pos_shift_row['status'] ?? '') !== 'open') {
                return new WP_Error('mgws_shift_closed', 'Shift is closed: open a new shift before checking out', array('status' => 409));
            }
            $pos_shift_id = (int) $pos_shift_row['id'];
        }

        $checkout_plan = $this->prepare_pos_checkout_plan($sale_items, $return_items, $site_limit);
        if (is_wp_error($checkout_plan)) {
            return $checkout_plan;
        }

        $idempotency_reserved = false;
        if ($idempotency_key !== '') {
            $reservation = MGWS_DB::reserve_pos_idempotency($idempotency_key, $payload_hash, get_current_user_id());
            if (($reservation['state'] ?? '') === 'existing') {
                return $this->get_existing_pos_idempotency_response($idempotency_key, $payload_hash, $reservation['record'] ?? array());
            }
            if (($reservation['state'] ?? '') !== 'reserved') {
                return new WP_Error('mgws_idempotency_unavailable', 'Unable to reserve checkout idempotency key', array('status' => 503));
            }
            $idempotency_reserved = true;
        }

        $order_status = 'processing';
        $order_total = (float) ($totals['totale'] ?? 0);
        if ($order_total < 0) {
            $order_status = 'refunded';
        } elseif ($set_paid) {
            $order_status = 'completed';
        }

        $order = wc_create_order(array('status' => $order_status));
        if (!$order || !method_exists($order, 'get_id')) {
            if ($idempotency_reserved) {
                MGWS_DB::mark_pos_idempotency_failed(
                    $idempotency_key,
                    $payload_hash,
                    0,
                    500,
                    'mgws_create_failed',
                    'Unable to create order',
                    'no_order_created'
                );
            }
            return new WP_Error('mgws_create_failed', 'Unable to create order', array('status' => 500));
        }

        $order_id = (int) $order->get_id();
        $touched_items = $checkout_plan['touched_items'];
        $movement_plan = $checkout_plan['movement_plan'];
        $sale_audit = $checkout_plan['sale_audit'];
        $return_audit = $checkout_plan['return_audit'];

        try {
            $order->set_created_via('mgws-pos');
            $order->set_currency(function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR');
            $order->set_payment_method($payment_method);
            if ($payment_method_title !== '') {
                $order->set_payment_method_title($payment_method_title);
            }

            $billing_first_name = sanitize_text_field((string) ($customer['first_name'] ?? ''));
            $billing_last_name = sanitize_text_field((string) ($customer['last_name'] ?? ''));
            $billing_email = sanitize_email((string) ($customer['email'] ?? ''));
            $billing_phone = sanitize_text_field((string) ($customer['phone'] ?? ''));

            if ($billing_first_name !== '') {
                $order->set_billing_first_name($billing_first_name);
            }
            if ($billing_last_name !== '') {
                $order->set_billing_last_name($billing_last_name);
            }
            if ($billing_email !== '') {
                $order->set_billing_email($billing_email);
            }
            if ($billing_phone !== '') {
                $order->set_billing_phone($billing_phone);
            }

            if ($customer_note !== '') {
                $order->set_customer_note($customer_note);
            }

            foreach ($meta_data as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $meta_key = sanitize_key((string) ($meta['key'] ?? ''));
                if ($meta_key === '') {
                    continue;
                }
                $order->add_meta_data($meta_key, $meta['value'] ?? '', true);
            }

            $order->add_meta_data('_mgws_pos_checkout_v1', 1, true);
            if ($pos_shift_id > 0) {
                $order->add_meta_data('_mgws_shift_id', $pos_shift_id, true);
                $order->add_meta_data('_mgws_shift_key', $pos_shift_key, true);
            }
            $order->add_meta_data('_mgws_pos_operation_type', $operation_type, true);
            $order->add_meta_data('_mgws_pos_effective_operation_type', $effective_operation_type, true);
            $order->add_meta_data('_mgws_pos_totals', wp_json_encode($totals), true);

            foreach ($checkout_plan['sale_items'] as $sale_item) {
                $order->add_item($sale_item['order_item']);
            }

            foreach ($checkout_plan['return_items'] as $return_item) {
                $order->add_item($return_item['order_item']);
            }

            $order->calculate_totals(false);
            $order->set_total($order_total);
            $order->save();

            global $wpdb;
            $wpdb->query('START TRANSACTION');

            foreach ($movement_plan as $step) {
                if ($step['kind'] === 'sale') {
                    foreach ($step['allocations'] as $allocation) {
                        $updated = $wpdb->query($wpdb->prepare(
                            "UPDATE " . MGWS_DB::table_levels() . "
                             SET qty = qty - %d, updated_at_gmt = %s
                             WHERE site_id = %d AND warehouse_id = %d AND product_id = %d AND variation_id = %d
                               AND room = %s AND rack = %s AND shelf = %s
                               AND qty >= %d",
                            (int) $allocation['use_qty'],
                            gmdate('Y-m-d H:i:s'),
                            (int) $allocation['site_id'],
                            (int) $allocation['warehouse_id'],
                            (int) $step['product_id'],
                            (int) $step['variation_id'],
                            (string) $allocation['room'],
                            (string) $allocation['rack'],
                            (string) $allocation['shelf'],
                            (int) $allocation['use_qty']
                        ));
                        if ((int) $updated !== 1) {
                            $wpdb->query('ROLLBACK');
                            throw new Exception(__('Stock ran out or changed during checkout', 'mg-warehouse-stock'));
                        }

                        if (!MGWS_DB::insert_move('out', (int) $allocation['site_id'], (int) $allocation['warehouse_id'], (int) $step['product_id'], (int) $step['variation_id'], (int) $allocation['use_qty'], (string) $allocation['room'], (string) $allocation['rack'], (string) $allocation['shelf'], get_current_user_id(), $order_id, 'POS checkout', (int) $allocation['warehouse_id'], 0)) {
                            $wpdb->query('ROLLBACK');
                            throw new Exception(__('Could not record the outgoing movement', 'mg-warehouse-stock'));
                        }
                    }
                    continue;
                }

                $location = $step['location'];
                $delta = (int) $step['qty'];
                $delta_result = MGWS_DB::apply_delta_level((int) $location['warehouse_id'], (int) $step['product_id'], (int) $step['variation_id'], $delta, (string) $location['room'], (string) $location['rack'], (string) $location['shelf']);
                if (is_array($delta_result) && !empty($delta_result['ok'])) {
                    if (!MGWS_DB::insert_move('in', (int) $location['site_id'], (int) $location['warehouse_id'], (int) $step['product_id'], (int) $step['variation_id'], $delta, (string) $location['room'], (string) $location['rack'], (string) $location['shelf'], get_current_user_id(), $order_id, 'POS return', 0, (int) $location['warehouse_id'])) {
                        $wpdb->query('ROLLBACK');
                        throw new Exception(__('Could not record the incoming movement', 'mg-warehouse-stock'));
                    }
                    continue;
                }

                $wpdb->query('ROLLBACK');
                throw new Exception((string) ($delta_result['message'] ?? __('Could not record the return', 'mg-warehouse-stock')));
            }

            $wpdb->query('COMMIT');

            foreach ($touched_items as $it) {
                $this->sync_woo_stock((int) $it['product_id'], (int) $it['variation_id']);
            }

            $audit = array(
                'order_id' => $order_id,
                'created_at_gmt' => gmdate('Y-m-d H:i:s'),
                'operation_type' => $operation_type,
                'effective_operation_type' => $effective_operation_type,
                'sale_items' => $sale_audit,
                'return_items' => $return_audit,
                'totals' => array(
                    'total_sale' => 0,
                    'total_return' => 0,
                    'net_total' => (float) $order_total,
                ),
            );
            foreach ($sale_audit as $item) {
                $audit['totals']['total_sale'] += (float) ($item['subtotal'] ?? 0);
            }
            foreach ($return_audit as $item) {
                $audit['totals']['total_return'] += (float) ($item['subtotal'] ?? 0);
            }

            update_post_meta($order_id, '_mgws_pos_order_audit', wp_json_encode($audit));
            update_post_meta($order_id, '_mgws_pos_checkout_success', 1);
            update_post_meta($order_id, '_mgws_pos_checkout_at_gmt', gmdate('Y-m-d H:i:s'));
            update_post_meta($order_id, '_mgws_pos_checkout_by', get_current_user_id());

            if ($order_status !== '') {
                $order->update_status($order_status);
            }

            // Progressivo scontrino: in simulazione lo assegna il server, con
            // registratore telematico lo emette il dispositivo e l'app lo
            // invia gia' valorizzato in receipt_number.
            // Receipt number: simulation allocates server-side, a fiscal
            // register issues it and the app sends it as receipt_number.
            // Il documento si identifica con l'idempotency key gia' risolto dal
            // checkout, cosi' lo stesso scontrino non genera mai due righe.
            // The document reuses the idempotency key already resolved by the
            // checkout, so the same receipt never produces two rows.
            $receipt_key = $idempotency_key !== '' ? $idempotency_key : ('order-' . $order_id);
            $register_name = '';
            $business_day_id = '';
            foreach ($meta_data as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $meta_key = (string) ($meta['key'] ?? '');
                if ($meta_key === '_cassa_nome') {
                    $register_name = sanitize_text_field((string) ($meta['value'] ?? ''));
                } elseif ($meta_key === '_giornata_id') {
                    $business_day_id = sanitize_text_field((string) ($meta['value'] ?? ''));
                }
            }
            // Le righe rendono il documento leggibile dal server e permettono di
            // controllare i resi su dati reali.
            // Lines make the document readable server-side and allow returns
            // to be enforced against real data.
            $device_receipt_number = trim((string) ($body['receipt_number'] ?? ''));
            $issued = MGWS_DB::issue_pos_receipt(array(
                'receipt_key' => $receipt_key,
                'business_day_id' => $business_day_id,
                'register_name' => $register_name,
                'sequential_number' => $device_receipt_number !== '' ? $device_receipt_number : 0,
                'status' => 'paid',
                'operation_type' => $effective_operation_type,
                'shift_id' => $pos_shift_id,
                'order_id' => $order_id,
                'idempotency_key' => $idempotency_key,
                'coupon_code' => sanitize_text_field((string) ($totals['coupon_code'] ?? '')),
                'note' => $customer_note !== '' ? $customer_note : null,
                'payment_method' => $payment_method,
                'tax_rate' => (float) ($totals['aliquota_iva'] ?? 22),
                'coupon_discount' => (float) ($totals['coupon_sconto'] ?? 0),
                'sales_total' => (float) ($totals['totale_vendite'] ?? 0),
                'returns_total' => (float) ($totals['totale_resi'] ?? 0),
                'net_total' => (float) ($totals['saldo_operazione'] ?? 0),
                'received_amount' => (float) ($totals['resto'] ?? 0),
                'change_amount' => (float) ($totals['resto'] ?? 0),
                'customer_id' => $order->get_customer_id() > 0 ? (int) $order->get_customer_id() : 0,
                'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'subtotal' => (float) ($totals['subtotale'] ?? $totals['subtotal'] ?? 0),
                'vat_total' => (float) ($totals['iva'] ?? 0),
                'discount_total' => (float) ($totals['sconto'] ?? 0),
                'total' => (float) ($totals['totale'] ?? 0),
                'issued_at_gmt' => gmdate('Y-m-d H:i:s'),
                'created_by' => get_current_user_id(),
            ));
            if (is_wp_error($issued)) {
                // Lo scontrino e' dato fiscale: senza progressivo non si puo'
                // concludere la vendita, quindi il checkout fallisce e
                // l'ordine viene rimosso come in ogni altro errore.
                throw new Exception('Unable to record POS receipt');
            }
            $receipt_row = $issued['row'] ?? array();
            if (!empty($issued['replayed']) && empty($issued['row'])) {
                $receipt_row = array();
            }
            $receipt_id = (int) ($receipt_row['id'] ?? 0);
            if ($receipt_id > 0) {
                $receipt_lines = array_merge($sale_items, $return_items);
                $vat_rate = (float) ($totals['aliquota_iva'] ?? 22);
                $operation_kind = 'sale';
                $operation_reference = trim((string) ($body['customer_id'] ?? ''));
                if ((int) ($totals['totale_resi'] ?? 0) > 0) {
                    $operation_kind = 'return';
                } elseif ((int) ($totals['totale_vendite'] ?? 0) > 0 && (int) ($totals['totale_resi'] ?? 0) < 0) {
                    $operation_kind = 'exchange';
                }
                foreach ($receipt_lines as $index => $receipt_line) {
                    $receipt_lines[$index]['vat_rate'] = $vat_rate;
                    // La classe dell'operazione sta nell'intestazione, non
                    // sulla riga: e' il documento a dire se e' vendita, reso o
                    // cambio, cosi' i totali non dipendono dalle righe.
                    $receipt_lines[$index]['operation_kind'] = $operation_kind;
                }
                MGWS_DB::save_pos_receipt_lines($receipt_id, $receipt_lines);
            }
            $sequential_number = (int) ($receipt_row['sequential_number'] ?? 0);
            if ($sequential_number > 0) {
                update_post_meta($order_id, '_mgws_pos_receipt_sequential_number', $sequential_number);
                update_post_meta($order_id, '_mgws_pos_receipt_key', $receipt_key);
            }

            $response = array(
                'success' => true,
                'ok' => true,
                'message' => 'POS checkout completed',
                'order_id' => $order_id,
                'woo_order_id' => $order_id,
                'order_status' => $order_status,
                'totals' => $audit['totals'],
                'sale_items' => $sale_audit,
                'return_items' => $return_audit,
                'receipt_key' => $receipt_key,
                'sequential_number' => $sequential_number,
                'returnable' => $this->pos_returnable_map($return_items),
            );
            if ($idempotency_reserved) {
                $response['idempotency'] = array(
                    'key' => $idempotency_key,
                    'replayed' => false,
                );
                if (!MGWS_DB::mark_pos_idempotency_succeeded($idempotency_key, $payload_hash, $order_id, 200, $response)) {
                    throw new Exception('Unable to persist checkout idempotency result');
                }
            }

            return rest_ensure_response($response);
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            $recovery_state = 'no_order_created';
            if ($order_id > 0 && function_exists('wp_delete_post')) {
                $recovery_state = wp_delete_post($order_id, true) ? 'order_deleted' : 'order_delete_failed';
            }
            if ($idempotency_reserved) {
                MGWS_DB::mark_pos_idempotency_failed(
                    $idempotency_key,
                    $payload_hash,
                    $order_id,
                    409,
                    'mgws_checkout_failed',
                    'Checkout could not be completed',
                    $recovery_state
                );
            }
            return new WP_Error('mgws_checkout_failed', 'Checkout could not be completed', array('status' => 409));
        }
    }

    public function route_pos_shift_open(WP_REST_Request $request) {
        $shift_key = (string) trim((string) $request->get_param('shift_key'));
        $existing = MGWS_DB::get_pos_shift_by_key($shift_key);
        if (is_array($existing)) {
            return new WP_Error('mgws_shift_conflict', 'A shift with this key already exists', array('status' => 409));
        }
        $cassa_name = (string) trim((string) $request->get_param('cassa_name'));
        if ($cassa_name !== '') {
            $open = MGWS_DB::get_open_pos_shift_by_cassa($cassa_name);
            if (is_array($open)) {
                return new WP_Error('mgws_shift_already_open', 'A shift is already open for this cash register', array('status' => 409));
            }
        }
        $shift_id = MGWS_DB::insert_pos_shift(array(
            'shift_key' => $shift_key,
            'giornata_id' => (string) trim((string) $request->get_param('giornata_id')),
            'cassa_name' => $cassa_name,
            'sede' => (string) trim((string) $request->get_param('sede')),
            'operator_id' => (int) $request->get_param('operator_id'),
            'operator_name' => (string) trim((string) $request->get_param('operator_name')),
            'fondo_iniziale' => (float) $request->get_param('fondo_iniziale'),
            'created_by' => get_current_user_id(),
        ));
        if ($shift_id <= 0) {
            return new WP_Error('mgws_shift_create_failed', 'Unable to open the shift', array('status' => 500));
        }
        $row = MGWS_DB::get_pos_shift_by_id($shift_id);
        return rest_ensure_response($this->format_pos_shift($row));
    }

    public function route_pos_shift_list(WP_REST_Request $request) {
        $rows = MGWS_DB::list_pos_shifts(array(
            'status' => sanitize_key((string) $request->get_param('status')),
            'cassa_name' => sanitize_text_field((string) $request->get_param('cassa_name')),
            'giornata_id' => sanitize_text_field((string) $request->get_param('giornata_id')),
        ));
        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->format_pos_shift($row);
        }
        return rest_ensure_response(array(
            'shifts' => $out,
            'count' => count($out),
        ));
    }

    public function route_pos_shift_get(WP_REST_Request $request) {
        $row = $this->resolve_pos_shift_ident((string) $request->get_param('shift_ident'));
        if (is_wp_error($row)) {
            return $row;
        }
        return rest_ensure_response($this->format_pos_shift($row));
    }

    public function route_pos_shift_close(WP_REST_Request $request) {
        $row = $this->resolve_pos_shift_ident((string) $request->get_param('shift_ident'));
        if (is_wp_error($row)) {
            return $row;
        }
        if (($row['status'] ?? '') !== 'open') {
            return new WP_Error('mgws_shift_not_open', 'Shift is not open', array('status' => 409));
        }
        $shift_id = (int) $row['id'];
        $expected = $this->compute_pos_shift_totals($shift_id);
        $contante_contato = (float) $request->get_param('contante_contato');
        $carta_contato = (float) $request->get_param('carta_contato');
        $contante_atteso = round((float) $row['fondo_iniziale'] + (float) $expected['contanti'], 2);
        $carta_atteso = round((float) $expected['carta'], 2);
        $close_totals = array(
            'expected_totals' => array(
                'contanti' => round((float) $expected['contanti'], 2),
                'carta' => round((float) $expected['carta'], 2),
                'altri' => round((float) $expected['altri'], 2),
                'rimborsi' => round((float) $expected['rimborsi'], 2),
                'orders' => (int) $expected['orders'],
            ),
            'contato' => array(
                'contante' => $contante_contato,
                'carta' => $carta_contato,
            ),
            'differenze' => array(
                'contante_atteso' => $contante_atteso,
                'carta_atteso' => $carta_atteso,
                'contante' => round($contante_contato - $contante_atteso, 2),
                'carta' => round($carta_contato - $carta_atteso, 2),
            ),
            'causale_differenza' => sanitize_text_field((string) $request->get_param('causale_differenza')),
            'note' => sanitize_textarea_field((string) $request->get_param('note')),
        );
        $updated = MGWS_DB::close_pos_shift($shift_id, $close_totals);
        if (!$updated) {
            return new WP_Error('mgws_shift_close_failed', 'Unable to close the shift', array('status' => 500));
        }
        $closed = MGWS_DB::get_pos_shift_by_id($shift_id);
        return rest_ensure_response($this->format_pos_shift($closed));
    }

    /**
 * Elenco scontrini con sintesi, per lo storico e i report.
 *
 * La lista porta gia' i totali cosi' l'app non deve scaricare ogni documento
 * per costruire il riepilogo di giornata.
 *
 * Receipt list with aggregates, for the history and the reports. Totals are
 * included so the app does not need to download every document to build a
 * daily summary.
 */
public function route_pos_receipt_list(WP_REST_Request $request) {
    $business_day_id = sanitize_text_field((string) $request->get_param('business_day_id'));
    $register_name = sanitize_text_field((string) $request->get_param('register_name'));
    $operation_type = sanitize_key((string) $request->get_param('operation_type'));
    $payment_method = sanitize_key((string) $request->get_param('payment_method'));
    $status = sanitize_key((string) $request->get_param('status'));
    $customer_id = (int) ($request->get_param('customer_id') ?: 0);
    $query = sanitize_text_field((string) ($request->get_param('query') ?? ''));
    $limit = (int) ($request->get_param('limit') ?: 100);
    $offset = (int) ($request->get_param('offset') ?: 0);
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);

    $table = MGWS_DB::table_pos_receipts();
    global $wpdb;
    $where = array('1=1');
    $params = array();
    if ($business_day_id !== '') {
        $where[] = 'business_day_id = %s';
        $params[] = $business_day_id;
    }
    if ($register_name !== '') {
        $where[] = 'register_name = %s';
        $params[] = $register_name;
    }
    if ($operation_type !== '') {
        $where[] = 'operation_type = %s';
        $params[] = $operation_type;
    }
    if ($payment_method !== '') {
        $where[] = 'payment_method = %s';
        $params[] = $payment_method;
    }
    if ($status !== '') {
        $where[] = 'status = %s';
        $params[] = $status;
    }
    if ($customer_id > 0) {
        $where[] = 'customer_id = %d';
        $params[] = $customer_id;
    }
    // La ricerca guarda cliente, progressivo e chiave documento: sono i campi
    // che l'operatore usa per ritrovare una vendita. Il confronto numerico
    // vale solo se il testo e' un numero, altrimenti "Rossi" diventerebbe 0 e
    // aggancerebbe ogni documento senza ordine.
    // Search covers customer, receipt number and document key. The numeric
    // comparison only applies to numeric input, otherwise a name would become
    // 0 and match every document without an order.
    if ($query !== '') {
        $like = '%' . $wpdb->esc_like($query) . '%';
        $clauses = array(
            'customer_name LIKE %s',
            'receipt_key LIKE %s',
        );
        $query_params = array($like, $like);
        if (ctype_digit($query)) {
            $clauses[] = 'order_id = %d';
            $clauses[] = 'sequential_number = %d';
            $query_params[] = (int) $query;
            $query_params[] = (int) $query;
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
        $params = array_merge($params, $query_params);
    }
    $where_sql = implode(' AND ', $where);

    $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $where_sql
        . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array($limit, $offset))), ARRAY_A);

    $count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql;
    $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

    $summary_sql = 'SELECT COALESCE(SUM(total),0) AS total, COALESCE(SUM(vat_total),0) AS vat_total,'
        . ' COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(discount_total),0) AS discount_total,'
        . ' COALESCE(SUM(sales_total),0) AS sales_total, COALESCE(SUM(returns_total),0) AS returns_total,'
        . ' COALESCE(SUM(CASE WHEN total < 0 THEN -total ELSE 0 END),0) AS refunds_total,'
        . ' SUM(CASE WHEN operation_type = %s THEN 1 ELSE 0 END) AS sales_count,'
        . ' SUM(CASE WHEN operation_type = %s THEN 1 ELSE 0 END) AS returns_count,'
        . ' SUM(CASE WHEN operation_type = %s THEN 1 ELSE 0 END) AS exchanges_count,'
        . ' COUNT(*) AS document_count FROM ' . $table . ' WHERE ' . $where_sql;
    $summary = $wpdb->get_row($wpdb->prepare(
        $summary_sql,
        array_merge(array('sale', 'return', 'exchange'), $params)
    ), ARRAY_A);

    // Totali per metodo di pagamento: servono alla chiusura del turno, che
    // confronta atteso e contato senza rileggere ogni documento.
    $method_sql = 'SELECT payment_method,'
        . ' SUM(CASE WHEN total >= 0 THEN total ELSE 0 END) AS collected,'
        . ' SUM(CASE WHEN total < 0 THEN -total ELSE 0 END) AS refunded,'
        . ' COUNT(*) AS document_count'
        . ' FROM ' . $table . ' WHERE ' . $where_sql
        . " AND payment_method <> '' GROUP BY payment_method";
    $method_rows = $wpdb->get_results($wpdb->prepare($method_sql, $params), ARRAY_A);
    $by_method = array();
    if (is_array($method_rows)) {
        foreach ($method_rows as $method_row) {
            $by_method[] = array(
                'payment_method' => (string) $method_row['payment_method'],
                'collected' => (float) $method_row['collected'],
                'refunded' => (float) $method_row['refunded'],
                'document_count' => (int) $method_row['document_count'],
            );
        }
    }

    $receipts = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $receipts[] = $this->pos_receipt_row_to_array($row);
        }
    }

    return rest_ensure_response(array(
        'success' => true,
        'receipts' => $receipts,
        'summary' => array(
            'total' => (float) ($summary['total'] ?? 0),
            'vat_total' => (float) ($summary['vat_total'] ?? 0),
            'subtotal' => (float) ($summary['subtotal'] ?? 0),
            'discount_total' => (float) ($summary['discount_total'] ?? 0),
            'sales_total' => (float) ($summary['sales_total'] ?? 0),
            'returns_total' => (float) ($summary['returns_total'] ?? 0),
            'refunds_total' => (float) ($summary['refunds_total'] ?? 0),
            'sales_count' => (int) ($summary['sales_count'] ?? 0),
            'returns_count' => (int) ($summary['returns_count'] ?? 0),
            'exchanges_count' => (int) ($summary['exchanges_count'] ?? 0),
            'document_count' => (int) ($summary['document_count'] ?? 0),
            'by_payment_method' => $by_method,
        ),
        'total_rows' => $total_rows,
        'limit' => $limit,
        'offset' => $offset,
    ));
}

/**
 * Dettaglio documento con righe e residuo rendibile.
 *
 * `returnable` e' calcolato dal server su tutti i resi registrati, quindi
 * l'app non deve ricostruirlo dal proprio storico locale.
 *
 * Document detail with lines and the returnable remainder, computed by the
 * server from every recorded return.
 */
public function route_pos_receipt_get(WP_REST_Request $request) {
    $ident = (string) $request->get_param('receipt_ident');
    $row = MGWS_DB::get_pos_receipt_by_key($ident);
    if (!is_array($row)) {
        $row = preg_match('/^[0-9]+$/', $ident) === 1
            ? MGWS_DB::get_pos_receipt_by_id((int) $ident)
            : null;
    }
    if (!is_array($row)) {
        return new WP_Error('mgws_receipt_not_found', 'POS receipt not found', array('status' => 404));
    }

    global $wpdb;
    $lines = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . MGWS_DB::table_pos_receipt_lines() . ' WHERE receipt_id = %d ORDER BY id ASC',
        (int) $row['id']
    ), ARRAY_A);

    $payload = $this->pos_receipt_row_to_array($row);
    $payload['lines'] = array();
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $payload['lines'][] = array(
                'line_key' => (string) ($line['line_key'] ?? ''),
                'movement_type' => (string) ($line['movement_type'] ?? ''),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'variation_id' => (int) ($line['variation_id'] ?? 0),
                'sku' => (string) ($line['sku'] ?? ''),
                'barcode' => (string) ($line['barcode'] ?? ''),
                'name' => (string) ($line['line_name'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'discount_total' => (float) ($line['discount_total'] ?? 0),
                'discount_percent' => (float) ($line['discount_percent'] ?? 0),
                'subtotal' => (float) ($line['subtotal'] ?? 0),
                'vat_rate' => (float) ($line['vat_rate'] ?? 0),
                'source_sale_id' => (string) ($line['source_receipt_key'] ?? ''),
                'source_line_key' => (string) ($line['source_line_key'] ?? ''),
                'return_reason' => (string) ($line['return_reason'] ?? ''),
                'return_outcome' => (string) ($line['return_outcome'] ?? ''),
            );
        }
    }
    $payload['returnable'] = MGWS_DB::get_pos_returnable_quantities((string) $row['receipt_key']);

    return rest_ensure_response(array(
        'success' => true,
        'receipt' => $payload,
    ));
}

private function pos_receipt_row_to_array(array $row) {
    return array(
        'receipt_key' => (string) ($row['receipt_key'] ?? ''),
        'business_day_id' => (string) ($row['business_day_id'] ?? ''),
        'register_name' => (string) ($row['register_name'] ?? ''),
        'sequential_number' => (int) ($row['sequential_number'] ?? 0),
        'status' => (string) ($row['status'] ?? ''),
        'operation_type' => (string) ($row['operation_type'] ?? ''),
        'shift_id' => (int) ($row['shift_id'] ?? 0),
        'order_id' => (int) ($row['order_id'] ?? 0),
        'coupon_code' => (string) ($row['coupon_code'] ?? ''),
        'note' => (string) ($row['note'] ?? ''),
        'payment_method' => (string) ($row['payment_method'] ?? ''),
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'subtotal' => (float) ($row['subtotal'] ?? 0),
        'vat_total' => (float) ($row['vat_total'] ?? 0),
        'tax_rate' => (float) ($row['tax_rate'] ?? 0),
        'discount_total' => (float) ($row['discount_total'] ?? 0),
        'coupon_discount' => (float) ($row['coupon_discount'] ?? 0),
        'total' => (float) ($row['total'] ?? 0),
        'sales_total' => (float) ($row['sales_total'] ?? 0),
        'returns_total' => (float) ($row['returns_total'] ?? 0),
        'net_total' => (float) ($row['net_total'] ?? 0),
        'issued_at' => (string) ($row['issued_at_gmt'] ?? ''),
        'corrections' => !empty($row['corrections'])
            ? json_decode((string) $row['corrections'], true)
            : array(),
    );
}

/**
 * Restituisce il residuo rendibile per linea dopo i resi appena registrati.
 *
 * L'app usa questa mappa per non duplicare il calcolo: il server e' la fonte
 * del residuo, perche' conosce i resi di tutti i dispositivi.
 *
 * Returns the returnable remainder per line after the returns just recorded,
 * so the app does not have to recompute it.
 */
/**
 * Annulla un documento o gli appende una rettifica.
 *
 * Lo storico deve poter essere corretto, ma le correzioni restano tracciate:
 * l'annullamento non cancella la riga e ogni rettifica viene conservata.
 * Cancels a document or appends a correction to it. Corrections are tracked:
 * cancelling never deletes the row and every note is preserved.
 */
public function route_pos_receipt_status(WP_REST_Request $request) {
    $ident = (string) $request->get_param('receipt_ident');
    $row = MGWS_DB::get_pos_receipt_by_key($ident);
    if (!is_array($row)) {
        $row = preg_match('/^[0-9]+$/', $ident) === 1
            ? MGWS_DB::get_pos_receipt_by_id((int) $ident)
            : null;
    }
    if (!is_array($row)) {
        return new WP_Error('mgws_receipt_not_found', 'POS receipt not found', array('status' => 404));
    }

    $status = sanitize_key((string) $request->get_param('status'));
    $note = sanitize_textarea_field((string) ($request->get_param('note') ?? ''));
    $updated = MGWS_DB::update_pos_receipt_status((int) $row['id'], $status, $note);
    if (is_wp_error($updated)) {
        return $updated;
    }

    return rest_ensure_response(array(
        'success' => true,
        'receipt' => $this->pos_receipt_row_to_array($updated),
        'corrections' => MGWS_DB::get_pos_receipt_corrections((int) $row['id']),
    ));
}

private function pos_closing_args() {
    return array(
        'closing_key' => array('required' => true, 'type' => 'string', 'sanitize_callback' => array($this, 'sanitize_pos_shift_key'), 'validate_callback' => array($this, 'validate_pos_shift_key')),
        'business_day_id' => $this->inventory_text_arg(),
        'register_name' => $this->inventory_text_arg(),
        'location' => $this->inventory_text_arg(),
        'shift_id' => $this->inventory_non_negative_int_arg(0),
        'operator_id' => $this->inventory_non_negative_int_arg(0),
        'operator_name' => $this->inventory_text_arg(),
        'opening_float' => $this->pos_float_arg(0),
        'cash_collected' => $this->pos_float_arg(0),
        'card_collected' => $this->pos_float_arg(0),
        'other_collected' => $this->pos_float_arg(0),
        'refunds' => $this->pos_float_arg(0),
        'manual_in' => $this->pos_float_arg(0),
        'manual_out' => $this->pos_float_arg(0),
        'cash_counted' => $this->pos_float_arg(0),
        'card_counted' => $this->pos_float_arg(0),
        'difference_reason' => $this->inventory_text_arg(),
        'note' => $this->inventory_text_arg(),
    );
}

/**
 * Storico chiusure di turno.
 *
 * Vive sul server perche' una chiusura e' un dato contabile: deve
 * sopravvivere al dispositivo che l'ha prodotta.
 * Shift closing history, kept server-side because a closing is an accounting
 * record that must outlive the device that produced it.
 */
public function route_pos_closing_list(WP_REST_Request $request) {
    $rows = MGWS_DB::list_pos_closings(array(
        'business_day_id' => (string) $request->get_param('business_day_id'),
        'register_name' => (string) $request->get_param('register_name'),
        'limit' => (int) ($request->get_param('limit') ?: 100),
    ));
    $closings = array();
    foreach ($rows as $row) {
        $closings[] = $this->pos_closing_row_to_array($row);
    }
    return rest_ensure_response(array(
        'success' => true,
        'closings' => $closings,
        'total_rows' => count($closings),
    ));
}

public function route_pos_closing_create(WP_REST_Request $request) {
    $issued = MGWS_DB::insert_pos_closing(array(
        'closing_key' => (string) $request->get_param('closing_key'),
        'business_day_id' => (string) ($request->get_param('business_day_id') ?? ''),
        'register_name' => (string) ($request->get_param('register_name') ?? ''),
        'location' => (string) ($request->get_param('location') ?? ''),
        'shift_id' => (int) ($request->get_param('shift_id') ?? 0),
        'operator_id' => (int) ($request->get_param('operator_id') ?? 0),
        'operator_name' => (string) ($request->get_param('operator_name') ?? ''),
        'opening_float' => (float) ($request->get_param('opening_float') ?? 0),
        'cash_collected' => (float) ($request->get_param('cash_collected') ?? 0),
        'card_collected' => (float) ($request->get_param('card_collected') ?? 0),
        'other_collected' => (float) ($request->get_param('other_collected') ?? 0),
        'refunds' => (float) ($request->get_param('refunds') ?? 0),
        'manual_in' => (float) ($request->get_param('manual_in') ?? 0),
        'manual_out' => (float) ($request->get_param('manual_out') ?? 0),
        'cash_counted' => (float) ($request->get_param('cash_counted') ?? 0),
        'card_counted' => (float) ($request->get_param('card_counted') ?? 0),
        'difference_reason' => (string) ($request->get_param('difference_reason') ?? ''),
        'note' => (string) ($request->get_param('note') ?? ''),
        'closed_at_gmt' => gmdate('Y-m-d H:i:s'),
        'created_by' => get_current_user_id(),
    ));
    if (is_wp_error($issued)) {
        return $issued;
    }
    return rest_ensure_response(array(
        'success' => true,
        'replayed' => !empty($issued['replayed']),
        'closing' => $this->pos_closing_row_to_array($issued['row']),
    ));
}

public function route_pos_closing_corrections(WP_REST_Request $request) {
    $ident = (string) $request->get_param('closing_ident');
    $row = MGWS_DB::get_pos_closing($ident);
    if (!is_array($row)) {
        $row = preg_match('/^[0-9]+$/', $ident) === 1
            ? MGWS_DB::get_pos_closing_by_id((int) $ident)
            : null;
    }
    if (!is_array($row)) {
        return new WP_Error('mgws_closing_not_found', 'Shift closing not found', array('status' => 404));
    }
    $corrections = MGWS_DB::update_pos_closing_corrections(
        (int) $row['id'],
        (string) $request->get_param('note')
    );
    if (is_wp_error($corrections)) {
        return $corrections;
    }
    return rest_ensure_response(array(
        'success' => true,
        'corrections' => $corrections,
    ));
}

private function pos_closing_row_to_array(array $row) {
    $cash_collected = (float) ($row['cash_collected'] ?? 0);
    $card_collected = (float) ($row['card_collected'] ?? 0);
    $cash_counted = (float) ($row['cash_counted'] ?? 0);
    $card_counted = (float) ($row['card_counted'] ?? 0);
    return array(
        'closing_key' => (string) ($row['closing_key'] ?? ''),
        'business_day_id' => (string) ($row['business_day_id'] ?? ''),
        'register_name' => (string) ($row['register_name'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'shift_id' => (int) ($row['shift_id'] ?? 0),
        'operator_id' => (int) ($row['operator_id'] ?? 0),
        'operator_name' => (string) ($row['operator_name'] ?? ''),
        'opening_float' => (float) ($row['opening_float'] ?? 0),
        'cash_collected' => $cash_collected,
        'card_collected' => $card_collected,
        'other_collected' => (float) ($row['other_collected'] ?? 0),
        'refunds' => (float) ($row['refunds'] ?? 0),
        'manual_in' => (float) ($row['manual_in'] ?? 0),
        'manual_out' => (float) ($row['manual_out'] ?? 0),
        'cash_counted' => $cash_counted,
        'card_counted' => $card_counted,
        'cash_expected' => $cash_collected + (float) ($row['opening_float'] ?? 0)
            + (float) ($row['manual_in'] ?? 0) - (float) ($row['manual_out'] ?? 0)
            - (float) ($row['refunds'] ?? 0),
        'cash_difference' => $cash_counted - ($cash_collected + (float) ($row['opening_float'] ?? 0)
            + (float) ($row['manual_in'] ?? 0) - (float) ($row['manual_out'] ?? 0)
            - (float) ($row['refunds'] ?? 0)),
        'card_difference' => $card_counted - $card_collected,
        'difference_reason' => (string) ($row['difference_reason'] ?? ''),
        'note' => (string) ($row['note'] ?? ''),
        'closed_at' => (string) ($row['closed_at_gmt'] ?? ''),
        'corrections' => !empty($row['corrections'])
            ? json_decode((string) $row['corrections'], true)
            : array(),
    );
}

private function validate_pos_receipt_status($value) {
    return in_array(
        (string) $value,
        array('paid', 'cancelled', 'refunded'),
        true
    );
}

private function pos_returnable_map(array $return_items) {
    $map = array();
    $seen = array();
    foreach ($return_items as $item) {
        $source_key = trim((string) ($item['source_sale_id'] ?? ''));
        $line_key = trim((string) ($item['source_line_key'] ?? ''));
        if ($source_key === '' || $line_key === '') {
            continue;
        }
        $identity = $source_key . '|' . $line_key;
        if (isset($seen[$identity])) {
            continue;
        }
        $seen[$identity] = true;
        $remaining = MGWS_DB::get_pos_returnable_quantities($source_key);
        $map[$identity] = (int) ($remaining[$line_key] ?? 0);
    }
    return $map;
}

private function resolve_pos_shift_ident($ident) {
        $ident = trim((string) $ident);
        if ($ident === '') {
            return new WP_Error('mgws_bad_request', 'Shift identifier is required', array('status' => 400));
        }
        if (preg_match('/^[0-9]+$/', $ident) === 1) {
            $row = MGWS_DB::get_pos_shift_by_id((int) $ident);
        } else {
            $row = MGWS_DB::get_pos_shift_by_key($ident);
        }
        if (!is_array($row)) {
            return new WP_Error('mgws_shift_not_found', 'Shift not found', array('status' => 404));
        }
        return $row;
    }

    private function format_pos_shift($row) {
        $close_totals = null;
        if (!empty($row['close_totals'])) {
            $decoded = json_decode((string) $row['close_totals'], true);
            if (is_array($decoded)) {
                $close_totals = $decoded;
            }
        }
        return array(
            'id' => (int) $row['id'],
            'shift_key' => (string) $row['shift_key'],
            'status' => (string) $row['status'],
            'giornata_id' => (string) $row['giornata_id'],
            'cassa_name' => (string) $row['cassa_name'],
            'sede' => ($row['sede'] !== null && $row['sede'] !== '') ? (string) $row['sede'] : null,
            'operator_id' => ($row['operator_id'] !== null && (int) $row['operator_id'] > 0) ? (int) $row['operator_id'] : null,
            'operator_name' => ($row['operator_name'] !== null && $row['operator_name'] !== '') ? (string) $row['operator_name'] : null,
            'fondo_iniziale' => (float) $row['fondo_iniziale'],
            'opened_at_gmt' => (string) $row['opened_at_gmt'],
            'closed_at_gmt' => !empty($row['closed_at_gmt']) ? (string) $row['closed_at_gmt'] : null,
            'close_totals' => $close_totals,
        );
    }

    private function compute_pos_shift_totals($shift_id) {
        global $wpdb;
        $totals = array('contanti' => 0.0, 'carta' => 0.0, 'altri' => 0.0, 'rimborsi' => 0.0, 'orders' => 0);
        $shift_id = (int) $shift_id;
        if ($shift_id <= 0 || !function_exists('wc_get_order') || !function_exists('wc_get_orders')) {
            return $totals;
        }
        // HPOS-safe: wc_get_orders astrae HPOS vs legacy CPT, quindi e'
        // affidabile sia con Custom Order Tables enabled che con postmeta.
        // Il meta _mgws_shift_id viene scritto dallo stesso MGWS su entrambi
        // i datastore (add_meta_data + save), quindi la query via WC orders
        // li trova sempre senza dipendere dal backend postmeta raw.
        $shift_orders = wc_get_orders(array(
            'limit' => -1,
            'type' => 'shop_order',
            'meta_key' => '_mgws_shift_id',
            'meta_value' => (string) $shift_id,
        ));
        if (!is_array($shift_orders)) {
            return $totals;
        }
        foreach ($shift_orders as $order) {
            if (!$order) {
                continue;
            }
            $status = $order->get_status();
            if (in_array($status, array('cancelled', 'trash', 'checkout-draft', 'draft', 'failed'), true)) {
                continue;
            }
            $total = (float) $order->get_total();
            $totals['orders']++;
            $method = (string) $order->get_payment_method();
            if ($total < 0) {
                $totals['rimborsi'] += abs($total);
            } elseif ($method === 'contanti') {
                $totals['contanti'] += $total;
            } elseif (in_array($method, array('carta', 'bancomat'), true)) {
                $totals['carta'] += $total;
            } else {
                $totals['altri'] += $total;
            }
        }
        return $totals;
    }

    public function route_resolve_barcode(WP_REST_Request $request) {
        $code = (string) $request->get_param('code');
        $code = sanitize_text_field(trim($code));
        if (strlen($code) > 128) {
            return new WP_Error('mgws_bad_request', 'Barcode too long', array('status' => 400));
        }
        if ($code === '') {
            return new WP_Error('mgws_bad_request', 'Empty barcode', array('status' => 400));
        }

        $id = $this->find_product_id_by_barcode($code);
        if ($id <= 0) {
            return new WP_Error('mgws_not_found', 'Barcode not found', array('status' => 404));
        }

        if (!function_exists('wc_get_product')) {
            return rest_ensure_response(array('product_id' => (int) $id, 'variation_id' => 0));
        }

        $p = wc_get_product($id);
        if ($p && $p->is_type('variation')) {
            return rest_ensure_response(array(
                'product_id' => (int) $p->get_parent_id(),
                'variation_id' => (int) $id,
            ));
        }
        return rest_ensure_response(array('product_id' => (int) $id, 'variation_id' => 0));
    }

    public function route_get_user_permissions(WP_REST_Request $request) {
        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, false);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $caps = array();
        $whitelist = $this->editable_capability_whitelist();
        foreach ($whitelist as $cap) {
            $caps[$cap] = !empty($target->allcaps[$cap]);
        }

        return rest_ensure_response(array(
            'user_id' => (int) $target->ID,
            'roles' => array_values(array_map('strval', $target->roles)),
            'capabilities' => $caps,
            'capability_whitelist' => $whitelist,
            'editable_roles' => array_keys(get_editable_roles()),
        ));
    }

    public function route_patch_user_permissions(WP_REST_Request $request) {
        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, true);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        if (array_key_exists('roles', $body)) {
            if (!current_user_can('manage_options')) {
                return new WP_Error('mgws_forbidden', 'Only administrators can modify roles from this endpoint', array('status' => 403));
            }
            if (!is_array($body['roles'])) {
                return new WP_Error('mgws_bad_request', 'roles must be an array', array('status' => 400));
            }
            $editable_roles = get_editable_roles();
            $roles = array_values(array_unique(array_filter(array_map('sanitize_key', $body['roles']))));
            foreach ($roles as $role) {
                if (!isset($editable_roles[$role])) {
                    return new WP_Error('mgws_bad_request', 'Invalid role: ' . $role, array('status' => 400));
                }
            }

            if (empty($roles)) {
                return new WP_Error('mgws_bad_request', 'At least one role is required', array('status' => 400));
            }

            $target->set_role(array_shift($roles));
            foreach ($roles as $role) {
                $target->add_role($role);
            }
        }

        if (array_key_exists('capabilities', $body)) {
            if (!is_array($body['capabilities'])) {
                return new WP_Error('mgws_bad_request', 'capabilities must be an object', array('status' => 400));
            }
            $allowed = array_fill_keys($this->editable_capability_whitelist(), true);
            foreach ($body['capabilities'] as $cap => $value) {
                $cap = sanitize_key((string) $cap);
                if (!isset($allowed[$cap])) {
                    return new WP_Error('mgws_bad_request', 'Capability not allowed: ' . $cap, array('status' => 400));
                }
                $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($enabled === null) {
                    return new WP_Error('mgws_bad_request', 'Capability value must be boolean: ' . $cap, array('status' => 400));
                }
                if ($enabled) {
                    $target->add_cap($cap, true);
                } else {
                    $target->remove_cap($cap);
                }
            }
        }

        return $this->route_get_user_permissions($request);
    }

    public function route_create_app_password(WP_REST_Request $request) {
        if (!class_exists('WP_Application_Passwords')) {
            return new WP_Error('mgws_unavailable', 'Application Passwords are not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, true);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }
        $name = sanitize_text_field((string) ($body['name'] ?? 'MGWS App'));
        if ($name === '') {
            $name = 'MGWS App';
        }

        if (!method_exists('WP_Application_Passwords', 'create_new_application_password')) {
            return new WP_Error('mgws_unavailable', 'Application Passwords API not supported by this WordPress version', array('status' => 503));
        }

        $created = WP_Application_Passwords::create_new_application_password((int) $target->ID, array('name' => $name));
        if (is_wp_error($created)) {
            return $created;
        }

        $password_plain = '';
        $item = array();
        if (is_array($created)) {
            if (isset($created[1])) {
                $password_plain = (string) $created[1];
            }
            if (isset($created[0]) && is_array($created[0])) {
                $item = $created[0];
            }
            if ($password_plain === '' && isset($created['password'])) {
                $password_plain = (string) $created['password'];
            }
            if (empty($item) && isset($created['item']) && is_array($created['item'])) {
                $item = $created['item'];
            }
        }

        if ($password_plain === '') {
            return new WP_Error('mgws_error', 'Unable to create application password', array('status' => 500));
        }

        return rest_ensure_response(array(
            'user_id' => (int) $target->ID,
            'name' => (string) ($item['name'] ?? $name),
            'uuid' => (string) ($item['uuid'] ?? ''),
            'app_password' => $password_plain,
            'note' => 'Show this password only once and store it securely.',
        ));
    }

    public function route_list_app_passwords(WP_REST_Request $request) {
        if (!class_exists('WP_Application_Passwords')) {
            return new WP_Error('mgws_unavailable', 'Application Passwords are not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, false);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $items = $this->list_application_password_items((int) $target->ID);
        return rest_ensure_response(array(
            'user_id' => (int) $target->ID,
            'items' => $items,
        ));
    }

    public function route_delete_app_password(WP_REST_Request $request) {
        if (!class_exists('WP_Application_Passwords')) {
            return new WP_Error('mgws_unavailable', 'Application Passwords are not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, true);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $uuid = sanitize_text_field((string) $request->get_param('uuid'));
        if ($uuid === '') {
            return new WP_Error('mgws_bad_request', 'Invalid uuid', array('status' => 400));
        }

        $deleted = false;
        if (method_exists('WP_Application_Passwords', 'delete_application_password')) {
            $deleted = (bool) WP_Application_Passwords::delete_application_password((int) $target->ID, $uuid);
        }
        if (!$deleted) {
            return new WP_Error('mgws_not_found', 'Application password not found', array('status' => 404));
        }

        return rest_ensure_response(array('ok' => true));
    }

    public function route_create_woo_key(WP_REST_Request $request) {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, true);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $description = sanitize_text_field((string) ($body['description'] ?? 'MGWS API key'));
        if ($description === '') {
            $description = 'MGWS API key';
        }

        $permissions = sanitize_key((string) ($body['permissions'] ?? 'read_write'));
        $allowed_permissions = array('read', 'write', 'read_write');
        if (!in_array($permissions, $allowed_permissions, true)) {
            return new WP_Error('mgws_bad_request', 'Invalid permissions', array('status' => 400));
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (empty($exists)) {
            return new WP_Error('mgws_unavailable', 'WooCommerce API table not found', array('status' => 503));
        }

        $consumer_key = 'ck_' . $this->random_wc_key_fragment();
        $consumer_secret = 'cs_' . $this->random_wc_key_fragment();
        $key_hash = function_exists('wc_api_hash') ? wc_api_hash($consumer_key) : hash('sha256', $consumer_key);

        $inserted = $wpdb->insert($table, array(
            'user_id' => (int) $target->ID,
            'description' => $description,
            'permissions' => $permissions,
            'consumer_key' => $key_hash,
            'consumer_secret' => $consumer_secret,
            'truncated_key' => substr($consumer_key, -7),
            'nonces' => '',
        ), array('%d', '%s', '%s', '%s', '%s', '%s', '%s'));

        if (!$inserted) {
            return new WP_Error('mgws_error', 'Unable to create Woo API key', array('status' => 500));
        }

        return rest_ensure_response(array(
            'user_id' => (int) $target->ID,
            'key_id' => (int) $wpdb->insert_id,
            'description' => $description,
            'permissions' => $permissions,
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'note' => 'Show this secret only once and store it securely.',
        ));
    }

    public function route_list_woo_keys(WP_REST_Request $request) {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, false);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (empty($exists)) {
            return new WP_Error('mgws_unavailable', 'WooCommerce API table not found', array('status' => 503));
        }
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT key_id, description, permissions, truncated_key, last_access FROM {$table} WHERE user_id=%d ORDER BY key_id DESC",
            (int) $target->ID
        ), ARRAY_A);
        if (!is_array($items)) {
            $items = array();
        }

        return rest_ensure_response(array(
            'user_id' => (int) $target->ID,
            'items' => $items,
        ));
    }

    public function route_delete_woo_key(WP_REST_Request $request) {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return new WP_Error('mgws_unavailable', 'WooCommerce is not available', array('status' => 503));
        }

        $target = $this->get_target_user_from_request($request);
        if (is_wp_error($target)) {
            return $target;
        }
        $guard = $this->assert_user_manage_allowed($target->ID, true);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $key_id = (int) $request->get_param('key_id');
        if ($key_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid key_id', array('status' => 400));
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (empty($exists)) {
            return new WP_Error('mgws_unavailable', 'WooCommerce API table not found', array('status' => 503));
        }
        $deleted = $wpdb->delete($table, array('key_id' => $key_id, 'user_id' => (int) $target->ID), array('%d', '%d'));
        if (!$deleted) {
            return new WP_Error('mgws_not_found', 'Woo API key not found', array('status' => 404));
        }

        return rest_ensure_response(array('ok' => true));
    }

    // ---- Helpers

    private function editable_capability_whitelist() {
        return array(
            'edit_products',
            'edit_shop_orders',
            'read',
            'mgws_stock_read',
            'mgws_stock_move',
            'mgws_order_accept',
        );
    }

    private function get_target_user_from_request(WP_REST_Request $request) {
        $user_id = (int) $request->get_param('user_id');
        if ($user_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid user_id', array('status' => 400));
        }
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('mgws_not_found', 'User not found', array('status' => 404));
        }
        return $user;
    }

    private function assert_user_manage_allowed($target_user_id, $for_write) {
        $target_user_id = (int) $target_user_id;
        if ($target_user_id <= 0) {
            return new WP_Error('mgws_bad_request', 'Invalid user_id', array('status' => 400));
        }
        if (!current_user_can('edit_user', $target_user_id)) {
            return new WP_Error('mgws_forbidden', 'Cannot manage this user', array('status' => 403));
        }
        if ($for_write && (int) get_current_user_id() === $target_user_id) {
            return new WP_Error('mgws_forbidden', 'Cannot modify your own privileged permissions from this endpoint', array('status' => 403));
        }
        if (user_can($target_user_id, 'manage_options') && !current_user_can('manage_options')) {
            return new WP_Error('mgws_forbidden', 'Cannot manage administrator-level users', array('status' => 403));
        }
        return true;
    }

    private function random_wc_key_fragment() {
        if (function_exists('wc_rand_hash')) {
            return wc_rand_hash();
        }
        return wp_generate_password(32, false, false);
    }

    private function list_application_password_items($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array();
        }

        $items = array();
        if (method_exists('WP_Application_Passwords', 'list_application_passwords')) {
            $items = WP_Application_Passwords::list_application_passwords($user_id);
        } elseif (method_exists('WP_Application_Passwords', 'get_user_application_passwords')) {
            $items = WP_Application_Passwords::get_user_application_passwords($user_id);
        }

        if (!is_array($items)) {
            return array();
        }
        return array_values(array_map(function ($item) {
            if (!is_array($item)) {
                return array();
            }
            return array(
                'uuid' => (string) ($item['uuid'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'created' => (string) ($item['created'] ?? ''),
                'last_used' => (string) ($item['last_used'] ?? ''),
                'last_ip' => (string) ($item['last_ip'] ?? ''),
            );
        }, $items));
    }

    private function get_user_site_limit($user_id) {
        $site_id = (int) get_user_meta((int) $user_id, 'mg_default_site_id', true);
        return $site_id > 0 ? $site_id : 0;
    }

    /**
     * `true` se il negozio ha piu' di una sede.
     *
     * Decide se un totale debba dichiarare la sua sede. Con una sola sede la
     * domanda non si pone: "il totale del prodotto" e "il totale di quella sede"
     * sono lo stesso numero, quindi chiederla sarebbe chiedere una formalita'.
     * Con piu' sedi i due numeri divergono e la dichiarazione e' l'unica cosa che
     * distingue il totale del negozio dal totale di un posto.
     *
     * Si contano i post, non i magazzini: la sede e' il posto fisico, ed e' lei
     * che l'operatore abita. Il magazzino e' una divisione virtuale dentro la
     * sede, quindi tre magazzini nella stessa sede restano una sede sola.
     */
    private function inventory_requires_site_scope() {
        $ids = get_posts(array(
            'post_type' => 'mg_site',
            'posts_per_page' => 2,
            'fields' => 'ids',
            'post_status' => 'publish',
        ));
        return is_array($ids) && count($ids) > 1;
    }

    private function load_post_titles($ids) {
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $posts = get_posts(array(
            'post_type' => array('mg_site', 'mg_warehouse'),
            'post__in' => array_map('intval', $ids),
            'posts_per_page' => -1,
            'orderby' => 'post__in',
        ));
        foreach ($posts as $p) {
            $out[(int) $p->ID] = (string) $p->post_title;
        }
        return $out;
    }

    private function sync_woo_stock($product_id, $variation_id) {
        if (!function_exists('wc_get_product')) {
            return;
        }
        $sum = MGWS_DB::sum_qty_for_item((int) $product_id, (int) $variation_id);
        $product = ((int) $variation_id > 0) ? wc_get_product((int) $variation_id) : wc_get_product((int) $product_id);
        if (!$product) {
            return;
        }
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $sum);
        $product->set_stock_status(((int) $sum > 0) ? 'instock' : 'outofstock');
        $product->save();
    }

    private function get_pos_idempotency_key($body) {
        if (!is_array($body)) {
            return '';
        }

        if (array_key_exists('idempotency_key', $body) && $body['idempotency_key'] !== null && $body['idempotency_key'] !== '') {
            return $this->normalize_pos_idempotency_key($body['idempotency_key']);
        }

        $meta_data = $body['meta_data'] ?? array();
        if (!is_array($meta_data)) {
            return '';
        }
        foreach ($meta_data as $meta) {
            if (!is_array($meta) || (string) ($meta['key'] ?? '') !== '_id_scontrino_locale') {
                continue;
            }
            if (!array_key_exists('value', $meta) || $meta['value'] === null || $meta['value'] === '') {
                continue;
            }
            return $this->normalize_pos_idempotency_key($meta['value']);
        }

        return '';
    }

    private function normalize_pos_idempotency_key($value) {
        if (!$this->validate_pos_idempotency_key($value)) {
            return new WP_Error('mgws_bad_request', 'Invalid idempotency_key', array('status' => 400));
        }
        return $this->sanitize_pos_idempotency_key($value);
    }

    private function get_pos_idempotency_payload_hash($body) {
        $payload = array(
            'sale_items' => is_array($body['sale_items'] ?? null) ? $body['sale_items'] : array(),
            'return_items' => is_array($body['return_items'] ?? null) ? $body['return_items'] : array(),
            'customer' => is_array($body['customer'] ?? null) ? $body['customer'] : array(),
            'totals' => is_array($body['totals'] ?? null) ? $body['totals'] : array(),
            'payment' => array(
                'method' => $body['payment_method'] ?? '',
                'title' => $body['payment_method_title'] ?? '',
            ),
            'operation' => array(
                'type' => $body['operation_type'] ?? 'sale',
                'effective_type' => $body['effective_operation_type'] ?? ($body['operation_type'] ?? 'sale'),
            ),
            'set_paid' => !empty($body['set_paid']),
            'note' => $body['note'] ?? '',
            'meta_data' => $this->filter_pos_idempotency_meta_data($body['meta_data'] ?? array()),
        );
        $json = wp_json_encode(
            $this->canonicalize_pos_idempotency_value($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            return new WP_Error('mgws_bad_request', 'Unable to normalize checkout payload', array('status' => 400));
        }
        return hash('sha256', $json);
    }

    private function filter_pos_idempotency_meta_data($meta_data) {
        if (!is_array($meta_data)) {
            return array();
        }
        $volatile_keys = array(
            '_id_scontrino_locale',
            '_data_operazione',
            'timestamp',
            'created_at',
            'created_at_gmt',
            'updated_at',
            'updated_at_gmt',
            'sent_at',
        );
        $filtered = array();
        foreach ($meta_data as $meta) {
            if (is_array($meta) && in_array((string) ($meta['key'] ?? ''), $volatile_keys, true)) {
                continue;
            }
            $filtered[] = $meta;
        }
        return $filtered;
    }

    private function canonicalize_pos_idempotency_value($value) {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->is_pos_list_array($value)) {
            $out = array();
            foreach ($value as $item) {
                $out[] = $this->canonicalize_pos_idempotency_value($item);
            }
            return $out;
        }

        $out = array();
        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->canonicalize_pos_idempotency_value($item);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function is_pos_list_array($value) {
        if (!is_array($value)) {
            return false;
        }
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }

    private function get_existing_pos_idempotency_response($idempotency_key, $payload_hash, $record) {
        if (!is_array($record) || !hash_equals((string) ($record['payload_hash'] ?? ''), (string) $payload_hash)) {
            return new WP_Error('mgws_idempotency_conflict', 'Idempotency key is already associated with a different checkout payload', array('status' => 409));
        }

        $status = (string) ($record['status'] ?? '');
        if ($status === 'succeeded') {
            $response = json_decode((string) ($record['response_json'] ?? ''), true);
            if (!is_array($response)) {
                return new WP_Error('mgws_idempotency_recovery_required', 'Stored checkout result is unavailable', array('status' => 409));
            }
            $response['idempotency'] = array(
                'key' => $idempotency_key,
                'replayed' => true,
            );
            return rest_ensure_response($response);
        }

        if ($status === 'failed') {
            $error_code = sanitize_key((string) ($record['error_code'] ?? ''));
            if ($error_code === '') {
                $error_code = 'mgws_idempotency_failed';
            }
            $http_status = (int) ($record['http_status'] ?? 0);
            if ($http_status < 400 || $http_status > 599) {
                $http_status = 409;
            }
            return new WP_Error($error_code, 'Previous checkout attempt failed', array('status' => $http_status));
        }

        return new WP_Error('mgws_idempotency_in_progress', 'Checkout with this idempotency key is already in progress', array('status' => 409));
    }

    private function prepare_pos_checkout_plan($sale_items, $return_items, $site_limit) {
        $plan = array(
            'sale_items' => array(),
            'return_items' => array(),
            'touched_items' => array(),
            'movement_plan' => array(),
            'sale_audit' => array(),
            'return_audit' => array(),
        );

        foreach ($sale_items as $item) {
            $sale_item = $this->build_pos_sale_item($item, $site_limit);
            if (is_wp_error($sale_item)) {
                return $sale_item;
            }
            $plan['sale_items'][] = $sale_item;
            $plan['movement_plan'][] = array(
                'kind' => 'sale',
                'product_id' => $sale_item['product_id'],
                'variation_id' => $sale_item['variation_id'],
                'qty' => $sale_item['qty'],
                'allocations' => $sale_item['allocations'],
            );
            $plan['sale_audit'][] = $sale_item['audit'];
            $plan['touched_items'][$sale_item['product_key']] = array(
                'product_id' => $sale_item['product_id'],
                'variation_id' => $sale_item['variation_id'],
            );
        }

        foreach ($return_items as $item) {
            $return_item = $this->build_pos_return_item($item, $site_limit);
            if (is_wp_error($return_item)) {
                return $return_item;
            }
            $plan['return_items'][] = $return_item;
            $plan['movement_plan'][] = array(
                'kind' => 'return',
                'product_id' => $return_item['product_id'],
                'variation_id' => $return_item['variation_id'],
                'qty' => $return_item['qty'],
                'location' => $return_item['location'],
            );
            $plan['return_audit'][] = $return_item['audit'];
            $plan['touched_items'][$return_item['product_key']] = array(
                'product_id' => $return_item['product_id'],
                'variation_id' => $return_item['variation_id'],
            );
        }

        return $plan;
    }

    private function parse_pos_items($items, $kind) {
        if (!is_array($items)) {
            return array();
        }

        $out = array();
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!$this->is_positive_integer($item['product_id'] ?? null) || !$this->is_non_negative_integer($item['variation_id'] ?? 0) || !$this->is_positive_integer($item['quantity'] ?? null)) {
                return new WP_Error('mgws_bad_request', 'Invalid ' . $kind . ' item at index ' . $index, array('status' => 400));
            }
            $product_id = (int) $item['product_id'];
            $variation_id = (int) ($item['variation_id'] ?? 0);
            $qty = (int) $item['quantity'];

            $out[] = array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $qty,
                'sku' => sanitize_text_field((string) ($item['sku'] ?? '')),
                'name' => sanitize_text_field((string) ($item['name'] ?? '')),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'subtotal' => (float) ($item['subtotal'] ?? 0),
                'movement_type' => sanitize_key((string) ($item['movement_type'] ?? $kind)),
                'line_key' => sanitize_text_field((string) ($item['line_key'] ?? '')),
                'source_sale_id' => sanitize_text_field((string) ($item['source_sale_id'] ?? '')),
                'source_line_key' => sanitize_text_field((string) ($item['source_line_key'] ?? '')),
                'return_reason' => sanitize_text_field((string) ($item['return_reason'] ?? '')),
                'return_outcome' => sanitize_key((string) ($item['return_outcome'] ?? '')),
            );
        }

        return $out;
    }

    private function validate_pos_return_references($return_items) {
        foreach ($return_items as $item) {
            $source_sale_id = trim((string) ($item['source_sale_id'] ?? ''));
            $source_line_key = trim((string) ($item['source_line_key'] ?? ''));
            if ($source_sale_id === '' && $source_line_key === '') {
                continue; // client legacy: mantiene comportamento precedente
            }
            if ($source_sale_id === '' || $source_line_key === '') {
                return new WP_Error('mgws_bad_request', 'Return item requires source_sale_id and source_line_key', array('status' => 400));
            }
            if (trim((string) ($item['return_reason'] ?? '')) === '') {
                return new WP_Error('mgws_bad_request', 'Return reason is required for referenced returns', array('status' => 400));
            }

            $origin = $this->find_pos_order_by_local_receipt_id($source_sale_id);
            if (!$origin) {
                return new WP_Error('mgws_return_source_not_found', 'Source sale not found for return', array('status' => 404));
            }
            $audit = $this->pos_order_audit($origin);
            $sale_line = $this->find_pos_sale_audit_line($audit, $source_line_key, (int) $item['product_id'], (int) $item['variation_id']);
            if (!is_array($sale_line)) {
                return new WP_Error('mgws_return_line_not_found', 'Source sale line not found for return', array('status' => 404));
            }
            $sold_qty = (int) ($sale_line['quantity'] ?? 0);
            if ($sold_qty <= 0) {
                return new WP_Error('mgws_return_line_not_found', 'Source sale line has no quantity', array('status' => 404));
            }
            $already_returned = $this->pos_returned_qty_for_source($source_sale_id, $source_line_key);
            $requested = (int) ($item['quantity'] ?? 0);
            if ($requested + $already_returned > $sold_qty) {
                return new WP_Error('mgws_return_over_limit', 'Return quantity exceeds remaining quantity', array('status' => 409));
            }
        }
        return true;
    }

    private function find_pos_order_by_local_receipt_id($receipt_id) {
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        $orders = wc_get_orders(array(
            'limit' => 1,
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => array(
                array(
                    'key' => '_id_scontrino_locale',
                    'value' => (string) $receipt_id,
                    'compare' => '=',
                ),
            ),
            'return' => 'objects',
        ));
        return !empty($orders) ? $orders[0] : null;
    }

    private function pos_order_audit($order) {
        if (!$order || !method_exists($order, 'get_meta')) {
            return array();
        }
        $raw = $order->get_meta('_mgws_pos_order_audit', true);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

    private function find_pos_sale_audit_line($audit, $line_key, $product_id, $variation_id) {
        foreach (($audit['sale_items'] ?? array()) as $line) {
            if (!is_array($line)) {
                continue;
            }
            if ((string) ($line['line_key'] ?? '') !== '' && (string) $line['line_key'] === (string) $line_key) {
                return $line;
            }
            if ((int) ($line['product_id'] ?? 0) === (int) $product_id && (int) ($line['variation_id'] ?? 0) === (int) $variation_id) {
                return $line;
            }
        }
        return null;
    }

    private function pos_returned_qty_for_source($source_sale_id, $source_line_key) {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $orders = wc_get_orders(array(
            'limit' => -1,
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => '_mgws_pos_checkout_success',
            'meta_value' => '1',
            'return' => 'objects',
        ));
        $qty = 0;
        foreach ($orders as $order) {
            $audit = $this->pos_order_audit($order);
            foreach (($audit['return_items'] ?? array()) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                if ((string) ($line['source_sale_id'] ?? '') === (string) $source_sale_id && (string) ($line['source_line_key'] ?? '') === (string) $source_line_key) {
                    $qty += (int) ($line['quantity'] ?? 0);
                }
            }
        }
        return $qty;
    }

    private function build_pos_sale_item($item, $site_limit) {
        $product_id = (int) $item['product_id'];
        $variation_id = (int) $item['variation_id'];
        $qty = (int) $item['quantity'];
        $validation = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $product = null;
        if (function_exists('wc_get_product')) {
            $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        }
        if (!$product) {
            return new WP_Error('mgws_bad_request', 'Product not found for sale item', array('status' => 400));
        }

        $preferred = $this->resolve_pos_preferred_location($product_id, $variation_id);
        $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, (int) $site_limit);
        $levels = $this->prioritize_pos_levels($levels, $preferred);

        $remaining = $qty;
        $allocations = array();
        foreach ($levels as $level) {
            if ($remaining <= 0) {
                break;
            }
            $available = (int) ($level['qty'] ?? 0);
            if ($available <= 0) {
                continue;
            }
            $use = min($remaining, $available);
            $allocations[] = array(
                'site_id' => (int) ($level['site_id'] ?? 0),
                'warehouse_id' => (int) ($level['warehouse_id'] ?? 0),
                'room' => (string) ($level['room'] ?? ''),
                'rack' => (string) ($level['rack'] ?? ''),
                'shelf' => (string) ($level['shelf'] ?? ''),
                'use_qty' => $use,
            );
            $remaining -= $use;
        }

        if ($remaining > 0) {
            return new WP_Error('mgws_conflict', 'Insufficient stock for checkout', array('status' => 409));
        }

        $subtotal = (float) ($item['subtotal'] ?? 0);
        if ($subtotal == 0.0) {
            $subtotal = (float) ($item['unit_price'] ?? 0) * $qty;
        }

        $order_item = new WC_Order_Item_Product();
        $order_item->set_product($product);
        $order_item->set_name((string) ($item['name'] !== '' ? $item['name'] : $product->get_name()));
        $order_item->set_quantity($qty);
        $order_item->set_subtotal($subtotal);
        $order_item->set_total($subtotal);
        if (!empty($item['line_key'])) {
            $order_item->add_meta_data('_mgws_source_line_key', (string) $item['line_key'], true);
        }

        return array(
            'order_item' => $order_item,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'qty' => $qty,
            'allocations' => $allocations,
            'audit' => array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $qty,
                'subtotal' => $subtotal,
                'line_key' => (string) ($item['line_key'] ?? ''),
                'allocations' => $allocations,
            ),
            'product_key' => $product_id . ':' . $variation_id,
        );
    }

    private function build_pos_return_item($item, $site_limit) {
        $product_id = (int) $item['product_id'];
        $variation_id = (int) $item['variation_id'];
        $qty = (int) $item['quantity'];
        $validation = $this->validate_item_for_stock_ops($product_id, $variation_id);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $product = null;
        if (function_exists('wc_get_product')) {
            $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        }
        if (!$product) {
            return new WP_Error('mgws_bad_request', 'Product not found for return item', array('status' => 400));
        }

        $location = $this->resolve_pos_return_location($product_id, $variation_id, (int) $site_limit);
        if (empty($location['warehouse_id'])) {
            return new WP_Error('mgws_conflict', 'Unable to resolve stock location for return item', array('status' => 409));
        }

        $subtotal = (float) ($item['subtotal'] ?? 0);
        if ($subtotal == 0.0) {
            $subtotal = (float) ($item['unit_price'] ?? 0) * $qty;
        }

        $fee = new WC_Order_Item_Fee();
        $fee->set_name('Reso: ' . (string) ($item['name'] !== '' ? $item['name'] : $product->get_name()));
        $fee->set_amount(0);
        $fee->set_total(-abs($subtotal));
        if (method_exists($fee, 'set_tax_status')) {
            $fee->set_tax_status('none');
        }
        if (!empty($item['source_sale_id'])) {
            $fee->add_meta_data('_mgws_source_sale_id', (string) $item['source_sale_id'], true);
        }
        if (!empty($item['source_line_key'])) {
            $fee->add_meta_data('_mgws_source_line_key', (string) $item['source_line_key'], true);
        }
        if (!empty($item['return_reason'])) {
            $fee->add_meta_data('_mgws_return_reason', (string) $item['return_reason'], true);
        }

        return array(
            'order_item' => $fee,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'qty' => $qty,
            'location' => $location,
            'audit' => array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $qty,
                'subtotal' => $subtotal,
                'source_sale_id' => (string) ($item['source_sale_id'] ?? ''),
                'source_line_key' => (string) ($item['source_line_key'] ?? ''),
                'return_reason' => (string) ($item['return_reason'] ?? ''),
                'return_outcome' => (string) ($item['return_outcome'] ?? ''),
                'location' => $location,
            ),
            'product_key' => $product_id . ':' . $variation_id,
        );
    }

    private function resolve_pos_preferred_location($product_id, $variation_id) {
        $candidates = array();
        if ($variation_id > 0) {
            $candidates[] = $variation_id;
        }
        if ($product_id > 0) {
            $candidates[] = $product_id;
        }

        foreach ($candidates as $candidate_id) {
            $site_id = (int) get_post_meta($candidate_id, 'mgws_default_site_id', true);
            $warehouse_id = (int) get_post_meta($candidate_id, 'mgws_default_warehouse_id', true);
            $room = MGWS_DB::sanitize_loc((string) get_post_meta($candidate_id, 'mgws_default_room', true));
            $rack = MGWS_DB::sanitize_loc((string) get_post_meta($candidate_id, 'mgws_default_rack', true));
            $shelf = MGWS_DB::sanitize_loc((string) get_post_meta($candidate_id, 'mgws_default_shelf', true));

            if ($warehouse_id > 0) {
                $wh_site = (int) MGWS_DB::get_site_id_for_warehouse($warehouse_id);
                if ($wh_site <= 0) {
                    $warehouse_id = 0;
                } elseif ($site_id > 0 && $wh_site !== $site_id) {
                    $warehouse_id = 0;
                } elseif ($site_id <= 0) {
                    $site_id = $wh_site;
                }
            }

            if ($warehouse_id <= 0 && $site_id > 0) {
                $warehouse_id = $this->get_first_warehouse_for_site($site_id);
            }

            if ($site_id > 0 || $warehouse_id > 0 || $room !== '' || $rack !== '' || $shelf !== '') {
                return array(
                    'site_id' => $site_id,
                    'warehouse_id' => $warehouse_id,
                    'room' => $room,
                    'rack' => $rack,
                    'shelf' => $shelf,
                );
            }
        }

        return array('site_id' => 0, 'warehouse_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '');
    }

    private function resolve_pos_return_location($product_id, $variation_id, $site_limit) {
        $preferred = $this->resolve_pos_preferred_location($product_id, $variation_id);
        if ((int) ($preferred['warehouse_id'] ?? 0) > 0) {
            return $preferred;
        }

        $levels = MGWS_DB::get_levels_for_item($product_id, $variation_id, $site_limit);
        if (!empty($levels)) {
            $level = $levels[0];
            return array(
                'site_id' => (int) ($level['site_id'] ?? 0),
                'warehouse_id' => (int) ($level['warehouse_id'] ?? 0),
                'room' => (string) ($level['room'] ?? ''),
                'rack' => (string) ($level['rack'] ?? ''),
                'shelf' => (string) ($level['shelf'] ?? ''),
            );
        }

        return $this->resolve_first_available_warehouse_location($site_limit);
    }

    private function resolve_first_available_warehouse_location($site_limit) {
        $site_limit = (int) $site_limit;
        $arguments = array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        );
        if ($site_limit > 0) {
            $arguments['meta_query'] = array(
                array(
                    'key' => 'mg_site_id',
                    'value' => $site_limit,
                    'compare' => '=',
                ),
            );
        }
        $warehouses = get_posts($arguments);
        foreach (is_array($warehouses) ? $warehouses : array() as $warehouse_id) {
            $warehouse_id = (int) $warehouse_id;
            $site_id = (int) MGWS_DB::get_site_id_for_warehouse($warehouse_id);
            if ($warehouse_id <= 0 || $site_id <= 0 || ($site_limit > 0 && $site_id !== $site_limit)) {
                continue;
            }
            return array(
                'site_id' => $site_id,
                'warehouse_id' => $warehouse_id,
                'room' => '',
                'rack' => '',
                'shelf' => '',
            );
        }
        return array('site_id' => 0, 'warehouse_id' => 0, 'room' => '', 'rack' => '', 'shelf' => '');
    }

    private function prioritize_pos_levels($levels, $preferred) {
        if (!is_array($levels) || empty($levels) || !is_array($preferred)) {
            return is_array($levels) ? $levels : array();
        }

        usort($levels, function ($a, $b) use ($preferred) {
            $score_a = $this->pos_level_score($a, $preferred);
            $score_b = $this->pos_level_score($b, $preferred);
            if ($score_a === $score_b) {
                $qty_a = (int) ($a['qty'] ?? 0);
                $qty_b = (int) ($b['qty'] ?? 0);
                if ($qty_a === $qty_b) {
                    return ((int) ($a['warehouse_id'] ?? 0) <=> (int) ($b['warehouse_id'] ?? 0));
                }
                return $qty_b <=> $qty_a;
            }
            return $score_b <=> $score_a;
        });

        return $levels;
    }

    private function pos_level_score($level, $preferred) {
        $score = 0;
        if ((int) ($preferred['site_id'] ?? 0) > 0 && (int) ($level['site_id'] ?? 0) === (int) $preferred['site_id']) {
            $score += 1;
        }
        if ((int) ($preferred['warehouse_id'] ?? 0) > 0 && (int) ($level['warehouse_id'] ?? 0) === (int) $preferred['warehouse_id']) {
            $score += 2;
        }
        if ((string) ($preferred['room'] ?? '') !== '' && (string) ($level['room'] ?? '') === (string) $preferred['room']) {
            $score += 3;
        }
        if ((string) ($preferred['rack'] ?? '') !== '' && (string) ($level['rack'] ?? '') === (string) $preferred['rack']) {
            $score += 4;
        }
        if ((string) ($preferred['shelf'] ?? '') !== '' && (string) ($level['shelf'] ?? '') === (string) $preferred['shelf']) {
            $score += 5;
        }
        return $score;
    }

    private function get_first_warehouse_for_site($site_id) {
        $site_id = (int) $site_id;
        if ($site_id <= 0) {
            return 0;
        }
        $warehouses = get_posts(array(
            'post_type' => 'mg_warehouse',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => 'mg_site_id',
                    'value' => $site_id,
                    'compare' => '=',
                ),
            ),
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        if (empty($warehouses) || !is_array($warehouses)) {
            return 0;
        }
        return (int) $warehouses[0];
    }

    private function validate_item_for_stock_ops($product_id, $variation_id) {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;

        if ($product_id <= 0 || $variation_id < 0) {
            return new WP_Error('mgws_bad_request', 'Invalid product_id/variation_id', array('status' => 400));
        }

        if (!function_exists('wc_get_product')) {
            return true;
        }

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                return new WP_Error('mgws_bad_request', 'Invalid variation_id', array('status' => 400));
            }
            if ((int) $variation->get_parent_id() !== $product_id) {
                return new WP_Error('mgws_bad_request', 'variation_id does not belong to product_id', array('status' => 400));
            }
            return true;
        }

        $product = wc_get_product($product_id);
        if (!$product || $product->is_type('variation')) {
            return new WP_Error('mgws_bad_request', 'Invalid product_id', array('status' => 400));
        }

        return true;
    }

    private function find_product_id_by_barcode($barcode) {
        global $wpdb;

        // 1) ATUM table (if installed): wp_atum_product_data.barcode -> product_id
        $atum_table = $wpdb->prefix . 'atum_product_data';
        // Escape LIKE wildcards so we match the table name literally.
        $like = str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $atum_table);
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if (!empty($exists)) {
            $pid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT product_id FROM {$atum_table} WHERE barcode = %s LIMIT 1",
                $barcode
            ));
            if ($pid > 0) {
                return $pid;
            }
        }

        // 2) Fallback to postmeta _barcode.
        $pid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_barcode' AND meta_value = %s LIMIT 1",
            $barcode
        ));
        return $pid > 0 ? $pid : 0;
    }
}
