<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permission sets: named bundles of capabilities, stored as templates and
 * applied as WordPress roles (`mgws_{slug}`).
 *
 * Why a table on top of the roles option: `{prefix}user_roles` has no label an
 * app can read, no timestamps, and no way to tell a role this plugin owns from
 * a role another plugin created. The table is the source of truth; the role is
 * the effect, rebuilt from the table by sync_all().
 *
 * No parallel auth system: these are ordinary WordPress roles created with
 * add_role(). They show up in wp-admin, WooCommerce reads them, and every
 * permission check stays current_user_can().
 */
class MGWS_Permission_Sets {
    public const ROLE_PREFIX = 'mgws_';
    private const SEEDED_OPTION = 'mgws_permission_sets_seeded';

    /**
     * Slugs that would be a lie in the UI: the role they name already exists
     * and is not ours. The `mgws_` prefix already prevents a collision, but a
     * set called "administrator" would still read as the real thing.
     */
    private const RESERVED_SLUGS = array(
        'administrator',
        'admin',
        'shop_manager',
        'customer',
        'subscriber',
        'editor',
        'author',
        'contributor',
    );

    /**
     * The allow-list. A capability outside this map is refused, not ignored: a
     * set that promises something it cannot keep is worse than no set.
     *
     * `manage_options` is deliberately absent. No set can make an
     * administrator; that stays a whole WordPress role assignable only by
     * someone who already has manage_options.
     *
     * @return array<string, array{label: string, area: string}>
     */
    public static function catalog() {
        return array(
            'read' => array('label' => __('Back office access', 'mg-warehouse-stock'), 'area' => 'wordpress'),
            'edit_products' => array('label' => __('Edit products', 'mg-warehouse-stock'), 'area' => 'wordpress'),
            'edit_shop_orders' => array('label' => __('Edit shop orders', 'mg-warehouse-stock'), 'area' => 'wordpress'),
            'mgws_stock_read' => array('label' => __('Read stock', 'mg-warehouse-stock'), 'area' => 'stock'),
            'mgws_stock_move' => array('label' => __('Move stock', 'mg-warehouse-stock'), 'area' => 'stock'),
            'mgws_order_accept' => array('label' => __('Accept orders', 'mg-warehouse-stock'), 'area' => 'orders'),
            'mgws_supplier_manage' => array('label' => __('Manage suppliers', 'mg-warehouse-stock'), 'area' => 'suppliers'),
            'mgws_purchase_approve' => array('label' => __('Approve purchases', 'mg-warehouse-stock'), 'area' => 'purchasing'),
            'mgws_manage_employees' => array('label' => __('Manage employees', 'mg-warehouse-stock'), 'area' => 'people'),
            'mgws_manage_user_permissions' => array('label' => __('Manage user permissions', 'mg-warehouse-stock'), 'area' => 'people'),
            'mgws_manage_credentials' => array('label' => __('Manage credentials', 'mg-warehouse-stock'), 'area' => 'people'),
            'mgws_reports_read' => array('label' => __('Read reports', 'mg-warehouse-stock'), 'area' => 'reports'),
            'mgws_costs_read' => array('label' => __('Read costs', 'mg-warehouse-stock'), 'area' => 'costs'),
            'mgws_costs_manage' => array('label' => __('Manage costs', 'mg-warehouse-stock'), 'area' => 'costs'),
            'mgws_roles_read' => array('label' => __('Read permission sets', 'mg-warehouse-stock'), 'area' => 'roles'),
            'mgws_roles_manage' => array('label' => __('Manage permission sets', 'mg-warehouse-stock'), 'area' => 'roles'),
            'mgws_lots_manage' => array('label' => __('Manage stock lots', 'mg-warehouse-stock'), 'area' => 'lots'),
            'mgws_transfers_manage' => array('label' => __('Manage stock transfers', 'mg-warehouse-stock'), 'area' => 'transfers'),
            'mgws_price_lists_manage' => array('label' => __('Manage price lists', 'mg-warehouse-stock'), 'area' => 'price_lists'),
            'mgws_approval_rules_manage' => array('label' => __('Manage approval rules', 'mg-warehouse-stock'), 'area' => 'approvals'),
            'mgws_returns_manage' => array('label' => __('Manage returns', 'mg-warehouse-stock'), 'area' => 'returns'),
            'mgws_stock_reset_manage' => array('label' => __('Mass stock reset', 'mg-warehouse-stock'), 'area' => 'stock'),
            'mgws_count_schedules_manage' => array('label' => __('Manage scheduled counts', 'mg-warehouse-stock'), 'area' => 'stock'),
            'mgws_kits_manage' => array('label' => __('Manage kits', 'mg-warehouse-stock'), 'area' => 'kits'),
            'mgws_reservations_manage' => array('label' => __('Manage reservations', 'mg-warehouse-stock'), 'area' => 'orders'),
            'mgws_shipments_manage' => array('label' => __('Manage shipments', 'mg-warehouse-stock'), 'area' => 'orders'),
            'mgws_product_barcodes_manage' => array('label' => __('Manage product barcodes', 'mg-warehouse-stock'), 'area' => 'products'),
            'mgws_supplier_prices_manage' => array('label' => __('Manage supplier prices', 'mg-warehouse-stock'), 'area' => 'purchasing'),
            'mgws_documents_manage' => array('label' => __('Manage documents', 'mg-warehouse-stock'), 'area' => 'documents'),
            'mgws_payments_manage' => array('label' => __('Manage payments', 'mg-warehouse-stock'), 'area' => 'documents'),
        );
    }

    /**
     * The catalog as a JSON list, in catalog order. The app mirrors the keys it
     * knows and falls back to these labels for the ones it does not.
     *
     * @return array<int, array{capability: string, label: string, area: string}>
     */
    public static function catalog_list() {
        $out = array();
        foreach (self::catalog() as $capability => $meta) {
            $out[] = array(
                'capability' => $capability,
                'label' => (string) $meta['label'],
                'area' => (string) $meta['area'],
            );
        }
        return $out;
    }

    public static function role_key($slug) {
        return self::ROLE_PREFIX . (string) $slug;
    }

    /**
     * Tutti i set, con il conteggio utenti calcolato una volta sola.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all() {
        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i ORDER BY label ASC, id ASC', $table),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return array();
        }
        $counts = self::role_user_counts();
        $out = array();
        foreach ($rows as $row) {
            $out[] = self::format_row($row, $counts);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get($id) {
        $row = self::row($id);
        if (!$row) {
            return null;
        }
        return self::format_row($row, self::role_user_counts());
    }

    /**
     * Crea un set: riga + ruolo. L'ordine conta, perche' un ruolo senza riga
     * sarebbe invisibile all'app e una riga senza ruolo sarebbe una promessa
     * vuota.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function create($slug, $label, $capabilities) {
        $guard = self::assert_no_escalation($capabilities);
        if (is_wp_error($guard)) {
            return $guard;
        }
        return self::insert($slug, $label, $capabilities);
    }

    /**
     * Crea set e ruolo senza il controllo di escalation. Serve al seeding e
     * alla riparazione: quei percorsi non sono una richiesta di un utente e non
     * devono dipendere da chi ha innescato l'aggiornamento del plugin.
     *
     * @return array<string, mixed>|WP_Error
     */
    private static function insert($slug, $label, $capabilities) {
        $slug = self::sanitize_slug($slug);
        if (is_wp_error($slug)) {
            return $slug;
        }
        $label = self::sanitize_label($label);
        if (is_wp_error($label)) {
            return $label;
        }
        $caps = self::validate_capabilities($capabilities);
        if (is_wp_error($caps)) {
            return $caps;
        }

        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $existing = $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, $slug)
        );
        if ($existing !== null) {
            return new WP_Error('mgws_role_exists', 'A permission set with this slug already exists', array('status' => 409));
        }
        $role_key = self::role_key($slug);
        if (get_role($role_key)) {
            return new WP_Error('mgws_role_exists', 'A WordPress role with this name already exists', array('status' => 409));
        }

        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert(
            $table,
            array(
                'slug' => $slug,
                'label' => $label,
                'capabilities' => (string) wp_json_encode($caps),
                'created_at_gmt' => $now,
                'updated_at_gmt' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        if (!$inserted) {
            return new WP_Error('mgws_db_error', 'Could not save the permission set', array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;

        $created = add_role($role_key, $label, self::capability_map($caps));
        if (!$created) {
            $wpdb->delete($table, array('id' => $id), array('%d'));
            return new WP_Error('mgws_role_exists', 'A WordPress role with this name already exists', array('status' => 409));
        }

        wp_roles()->for_site();
        return self::get($id);
    }

    /**
     * Cambia le capability di un set. Slug ed etichetta sono immutabili: il
     * nome del ruolo vive nel meta di ogni utente e non si rinomina.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function update($id, $capabilities) {
        $id = (int) $id;
        $row = self::row($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Permission set not found', array('status' => 404));
        }
        $role_key = self::role_key($row['slug']);
        $users = self::count_role_users($role_key);
        if ($users > 0) {
            return new WP_Error(
                'mgws_role_in_use',
                sprintf('The permission set is assigned to %d user(s); duplicate it instead of changing it', $users),
                array('status' => 409)
            );
        }

        $guard = self::assert_no_escalation($capabilities);
        if (is_wp_error($guard)) {
            return $guard;
        }
        $caps = self::validate_capabilities($capabilities);
        if (is_wp_error($caps)) {
            return $caps;
        }

        $role = get_role($role_key);
        if ($role) {
            self::apply_capabilities($role, $caps);
        } else {
            // Il ruolo e' sparito (cancellato a mano da wp-admin): la tabella
            // resta la verita', quindi lo ricreiamo invece di fallire.
            add_role($role_key, (string) $row['label'], self::capability_map($caps));
        }

        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $wpdb->update(
            $table,
            array(
                'capabilities' => (string) wp_json_encode($caps),
                'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        wp_roles()->for_site();
        return self::get($id);
    }

    /**
     * Cancella un set e il suo ruolo. Solo se nessuno lo usa: remove_role()
     * toglie il ruolo dall'option ma non stacca gli utenti, che resterebbero
     * senza le capability del set in silenzio.
     *
     * @return true|WP_Error
     */
    public static function delete($id) {
        $id = (int) $id;
        $row = self::row($id);
        if (!$row) {
            return new WP_Error('mgws_not_found', 'Permission set not found', array('status' => 404));
        }
        $role_key = self::role_key($row['slug']);

        $users = self::count_role_users($role_key);
        if ($users > 0) {
            return new WP_Error(
                'mgws_role_in_use',
                sprintf('The permission set is assigned to %d user(s) and cannot be deleted', $users),
                array('status' => 409)
            );
        }
        // Ricontrollo immediatamente prima della rimozione: il conteggio di
        // sopra e' una query, e in mezzo un utente puo' aver ricevuto il ruolo.
        if (!empty(get_users(array('role' => $role_key, 'fields' => 'ID', 'number' => 1)))) {
            return new WP_Error('mgws_role_in_use', 'The permission set is assigned to a user and cannot be deleted', array('status' => 409));
        }

        remove_role($role_key);

        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $wpdb->delete($table, array('id' => $id), array('%d'));

        wp_roles()->for_site();
        return true;
    }

    /**
     * Riporta ogni ruolo `mgws_*` a quello che la tabella dice: aggiunge le
     * capability del set, toglie tutte le altre (compresa una manage_options
     * aggiunta a mano, che sarebbe un'escalation).
     *
     * @return void
     */
    public static function sync_all() {
        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $table),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $role_key = self::role_key($row['slug']);
            $caps = self::decode_capabilities($row['capabilities'] ?? '');
            $role = get_role($role_key);
            if (!$role) {
                add_role($role_key, (string) $row['label'], self::capability_map($caps));
                continue;
            }
            self::apply_capabilities($role, $caps);
        }
        wp_roles()->for_site();
    }

    /**
     * I due set iniziali, una volta sola. La guardia e' l'option e non la
     * tabella vuota: chi li cancella non se li deve vedere tornare.
     *
     * @return void
     */
    public static function seed_defaults() {
        if (get_option(self::SEEDED_OPTION)) {
            return;
        }
        update_option(self::SEEDED_OPTION, 1, false);

        self::insert('warehouse', __('Warehouse operator', 'mg-warehouse-stock'), array(
            'read',
            'mgws_stock_read',
            'mgws_stock_move',
        ));
        self::insert('cashier', __('Cashier', 'mg-warehouse-stock'), array(
            'read',
            'mgws_stock_read',
            'mgws_order_accept',
        ));
    }

    /**
     * Quanti utenti hanno manage_options, comunque lo abbiano ottenuto. Non si
     * usa count_users()['avail_roles']['administrator'] perche' un ruolo
     * custom puo' concedere manage_options.
     *
     * @return int
     */
    public static function count_administrators() {
        $users = get_users(array(
            'capability' => 'manage_options',
            'fields' => 'ID',
        ));
        return is_array($users) ? count($users) : 0;
    }

    /**
     * Vero se applicare $new_roles al target lascerebbe il negozio senza
     * nessun amministratore. WP_User::set_role() azzera il meta delle
     * capability, quindi dopo il cambio il target ha solo le capability dei
     * ruoli nuovi: basta guardare quelli.
     *
     * @param int      $user_id
     * @param string[] $new_roles
     * @return bool
     */
    public static function is_last_administrator($user_id, array $new_roles) {
        $user = get_user_by('ID', (int) $user_id);
        if (!$user || !user_can($user, 'manage_options')) {
            return false;
        }
        foreach ($new_roles as $role_key) {
            $role = get_role((string) $role_key);
            if ($role && !empty($role->capabilities['manage_options'])) {
                return false;
            }
        }
        return self::count_administrators() <= 1;
    }

    // ---- Internals

    /**
     * @return array<string, mixed>|null
     */
    private static function row($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = MGWS_DB::table_permission_sets();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int>   $counts
     * @return array<string, mixed>
     */
    private static function format_row(array $row, array $counts) {
        $slug = (string) ($row['slug'] ?? '');
        $role_key = self::role_key($slug);
        $users = (int) ($counts[$role_key] ?? 0);
        $in_use = $users > 0;
        return array(
            'id' => (int) ($row['id'] ?? 0),
            'slug' => $slug,
            'role' => $role_key,
            'label' => (string) ($row['label'] ?? ''),
            'capabilities' => self::decode_capabilities($row['capabilities'] ?? ''),
            'user_count' => $users,
            'in_use' => $in_use,
            'editable' => !$in_use,
            'deletable' => !$in_use,
        );
    }

    /**
     * Conteggio utenti per ruolo, una query per elenco e non una per set.
     *
     * @return array<string, int>
     */
    private static function role_user_counts() {
        $counts = count_users();
        return isset($counts['avail_roles']) && is_array($counts['avail_roles'])
            ? array_map('intval', $counts['avail_roles'])
            : array();
    }

    private static function count_role_users($role_key) {
        $users = get_users(array(
            'role' => (string) $role_key,
            'fields' => 'ID',
        ));
        return is_array($users) ? count($users) : 0;
    }

    /**
     * @return string[]
     */
    private static function decode_capabilities($raw) {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $catalog = self::catalog();
        $out = array();
        foreach ($decoded as $capability) {
            $capability = (string) $capability;
            if (isset($catalog[$capability])) {
                $out[$capability] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @param string[] $capabilities
     * @return array<string, bool>
     */
    private static function capability_map(array $capabilities) {
        return array_fill_keys($capabilities, true);
    }

    /**
     * Allinea un ruolo al set: aggiunge quello che manca, toglie tutto il
     * resto. Il secondo passo e' quello che impedisce a una capability
     * aggiunta a mano in wp-admin di restare.
     *
     * @param WP_Role  $role
     * @param string[] $capabilities
     * @return void
     */
    private static function apply_capabilities($role, array $capabilities) {
        $wanted = self::capability_map($capabilities);
        $current = array_keys(array_filter((array) $role->capabilities));
        foreach (array_keys($wanted) as $capability) {
            if (empty($role->capabilities[$capability])) {
                $role->add_cap($capability, true);
            }
        }
        foreach ($current as $capability) {
            if (!isset($wanted[$capability])) {
                $role->remove_cap($capability);
            }
        }
    }

    /**
     * @return string|WP_Error
     */
    private static function sanitize_slug($slug) {
        $slug = trim((string) $slug);
        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $slug)) {
            return new WP_Error('mgws_bad_request', 'Slug must be 2-32 characters: lowercase letters, digits, dash or underscore', array('status' => 400));
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return new WP_Error('mgws_bad_request', 'This slug is reserved by an existing WordPress role', array('status' => 400));
        }
        return $slug;
    }

    /**
     * @return string|WP_Error
     */
    private static function sanitize_label($label) {
        $label = trim(sanitize_text_field((string) $label));
        if ($label === '' || strlen($label) > 191) {
            return new WP_Error('mgws_bad_request', 'Label must be 1-191 characters', array('status' => 400));
        }
        return $label;
    }

    /**
     * @param mixed $capabilities
     * @return string[]|WP_Error
     */
    private static function validate_capabilities($capabilities) {
        if (!is_array($capabilities)) {
            return new WP_Error('mgws_bad_request', 'capabilities must be an array', array('status' => 400));
        }
        $catalog = self::catalog();
        $out = array();
        foreach ($capabilities as $capability) {
            $capability = sanitize_key((string) $capability);
            if (!isset($catalog[$capability])) {
                return new WP_Error('mgws_bad_request', 'Capability not allowed: ' . $capability, array('status' => 400));
            }
            $out[$capability] = true;
        }
        if (empty($out)) {
            return new WP_Error('mgws_bad_request', 'At least one capability is required', array('status' => 400));
        }
        return array_keys($out);
    }

    /**
     * Nessuna escalation: chi non e' amministratore puo' mettere in un set solo
     * capability che possiede gia'. Un set e' un modo per dare ad altri quello
     * che si ha, non per prendersi quello che non si ha.
     *
     * @param mixed $capabilities
     * @return true|WP_Error
     */
    /**
     * Rejects capabilities the current user does not hold. Also used when an
     * employee account is created with a set: granting a role is granting
     * its capabilities.
     */
    public static function assert_no_escalation($capabilities) {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!is_array($capabilities)) {
            return true; // la validazione risponde 400 con un messaggio migliore
        }
        foreach ($capabilities as $capability) {
            $capability = sanitize_key((string) $capability);
            if (!current_user_can($capability)) {
                return new WP_Error(
                    'mgws_capability_escalation',
                    'Cannot grant a capability you do not hold: ' . $capability,
                    array('status' => 403)
                );
            }
        }
        return true;
    }
}
