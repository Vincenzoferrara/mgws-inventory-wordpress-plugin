<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp-stub/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['mgws_test_actions'] = array();
$GLOBALS['mgws_test_routes'] = array();
$GLOBALS['mgws_test_logged_in'] = false;
$GLOBALS['mgws_test_capabilities'] = array();
$GLOBALS['mgws_test_products'] = array();
$GLOBALS['mgws_test_orders'] = array();
$GLOBALS['mgws_test_post_meta'] = array();
$GLOBALS['mgws_test_warehouses'] = array();
$GLOBALS['mgws_test_users'] = array();
$GLOBALS['mgws_test_options'] = array();
$GLOBALS['mgws_test_order_creations'] = 0;

final class WP_Error {
    private string $code;
    private string $message;
    private array $data;

    public function __construct(string $code = '', string $message = '', array $data = array()) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_data(): array {
        return $this->data;
    }
}

final class WP_REST_Request {
    private array $params;
    private mixed $jsonParams;

    public function __construct(array $params = array(), mixed $jsonParams = null) {
        $this->params = $params;
        $this->jsonParams = $jsonParams;
    }

    public function get_param(string $key): mixed {
        return $this->params[$key] ?? null;
    }

    public function get_json_params(): mixed {
        return $this->jsonParams;
    }
}

function add_action(string $hook, mixed $callback): void {
    $GLOBALS['mgws_test_actions'][$hook][] = $callback;
}

function add_filter(string $hook, mixed $callback): void {
    add_action($hook, $callback);
}

function register_activation_hook(string $file, mixed $callback): void {
    $GLOBALS['mgws_test_activation_hook'] = array($file, $callback);
}

function register_uninstall_hook(string $file, mixed $callback): void {
    $GLOBALS['mgws_test_uninstall_hook'] = array($file, $callback);
}

// The gettext family, with the behaviour WordPress actually has: with no
// catalogue loaded, __() hands back the source string untouched. The source is
// English, so a test asserting on it asserts on the plugin's own wording. A
// test that loaded a real .mo would be testing a translation instead.

function __(string $text, string $domain = 'default'): string {
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr__(string $text, string $domain = 'default'): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function _x(string $text, string $context, string $domain = 'default'): string {
    return $text;
}

function _n_noop(string $singular, string $plural, ?string $domain = null): array {
    return array(
        0 => $singular,
        1 => $plural,
        'singular' => $singular,
        'plural' => $plural,
        'context' => null,
        'domain' => $domain,
    );
}

function register_rest_route(string $namespace, string $route, array $definition): bool {
    $GLOBALS['mgws_test_routes'][$namespace][$route] = $definition;
    return true;
}

function rest_ensure_response(mixed $response): mixed {
    return $response;
}

function is_wp_error(mixed $value): bool {
    return $value instanceof WP_Error;
}

function is_user_logged_in(): bool {
    return (bool) $GLOBALS['mgws_test_logged_in'];
}

function current_user_can(string $capability): bool {
    return !empty($GLOBALS['mgws_test_capabilities'][$capability]);
}

function get_current_user_id(): int {
    return 7;
}

function get_user_meta(int $userId, string $key, bool $single = false): mixed {
    return $GLOBALS['mgws_test_users'][$userId]['meta'][$key] ?? 0;
}

function get_user_by(string $field, mixed $value): mixed {
    foreach ($GLOBALS['mgws_test_users'] as $userId => $user) {
        if ($field === 'id' && (int) $value === (int) $userId) {
            return new MGWS_Contract_User((int) $userId, (string) $user['email']);
        }
        if ($field === 'email' && strtolower((string) $value) === strtolower((string) $user['email'])) {
            return new MGWS_Contract_User((int) $userId, (string) $user['email']);
        }
    }
    return false;
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed {
    return $GLOBALS['mgws_test_post_meta'][$postId][$key] ?? 0;
}

function update_post_meta(int $postId, string $key, mixed $value): bool {
    $GLOBALS['mgws_test_post_meta'][$postId][$key] = $value;
    return true;
}

function get_posts(array $arguments = array()): array {
    // Il negozio finto ha una sede sola salvo diversa indicazione del test:
    // con una sola sede vale per tutti, come nel negozio vero.
    if (($arguments['post_type'] ?? '') === 'mg_site') {
        return $GLOBALS['mgws_test_sites'] ?? array(900);
    }
    if (($arguments['post_type'] ?? '') !== 'mg_warehouse') {
        return array();
    }
    $warehouses = $GLOBALS['mgws_test_warehouses'];
    $siteId = 0;
    foreach (($arguments['meta_query'] ?? array()) as $query) {
        if (($query['key'] ?? '') === 'mg_site_id') {
            $siteId = (int) ($query['value'] ?? 0);
        }
    }
    if ($siteId > 0) {
        $warehouses = array_values(array_filter($warehouses, static fn (int $warehouseId): bool => (int) get_post_meta($warehouseId, 'mg_site_id', true) === $siteId));
    }
    $limit = (int) ($arguments['posts_per_page'] ?? -1);
    return $limit > 0 ? array_slice($warehouses, 0, $limit) : $warehouses;
}

function sanitize_key(string $value): string {
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
}

function sanitize_text_field(string $value): string {
    return trim(strip_tags($value));
}

function sanitize_textarea_field(string $value): string {
    return trim(strip_tags($value));
}

/// Le optioni WP stanno in un array: senza questo stub le rotte che leggono
/// un'opzione (es. il turno obbligatorio in POS) non possono girare.
function get_option(string $option, mixed $default = false): mixed {
    return array_key_exists($option, $GLOBALS['mgws_test_options'])
        ? $GLOBALS['mgws_test_options'][$option]
        : $default;
}

function update_option(string $option, mixed $value): bool {
    $GLOBALS['mgws_test_options'][$option] = $value;
    return true;
}

function add_option(string $option, mixed $value): bool {
    if (array_key_exists($option, $GLOBALS['mgws_test_options'])) {
        return false;
    }
    $GLOBALS['mgws_test_options'][$option] = $value;
    return true;
}

function sanitize_email(string $value): string {
    return filter_var($value, FILTER_SANITIZE_EMAIL) ?: '';
}

/// Come WordPress: valida davvero, a differenza di `sanitize_email` che
/// ripulisce solo. Serve a distinguere "email assente" da "email spazzatura".
function is_email(string $value): string|false {
    return filter_var($value, FILTER_VALIDATE_EMAIL) ?: false;
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false {
    return json_encode($value, $flags, $depth);
}

function get_woocommerce_currency(): string {
    return 'EUR';
}

function wc_get_product(int $productId): mixed {
    return $GLOBALS['mgws_test_products'][$productId] ?? null;
}

function wc_create_order(array $arguments = array()): MGWS_Contract_Order {
    $GLOBALS['mgws_test_order_creations']++;
    $orderId = 1000 + $GLOBALS['mgws_test_order_creations'];
    $order = new MGWS_Contract_Order($orderId, (string) ($arguments['status'] ?? 'processing'));
    $GLOBALS['mgws_test_orders'][$orderId] = $order;
    return $order;
}

function wp_delete_post(int $postId, bool $forceDelete = false): bool {
    unset($GLOBALS['mgws_test_orders'][$postId]);
    return true;
}

final class MGWS_Contract_Product {
    private int $id;
    private bool $variation;
    private int $parentId;
    public bool $manageStock = false;
    public int $stockQuantity = 0;
    public string $stockStatus = '';

    public function __construct(int $id, bool $variation = false, int $parentId = 0) {
        $this->id = $id;
        $this->variation = $variation;
        $this->parentId = $parentId;
    }

    public function is_type(string $type): bool {
        return $type === 'variation' && $this->variation;
    }

    public function get_parent_id(): int {
        return $this->parentId;
    }

    public function get_name(): string {
        return 'Product ' . $this->id;
    }

    public function set_manage_stock(bool $value): void { $this->manageStock = $value; }
    public function set_stock_quantity(int $value): void { $this->stockQuantity = $value; }
    public function set_stock_status(string $value): void { $this->stockStatus = $value; }
    public function save(): void {}
}

final class MGWS_Contract_User {
    public int $ID;
    public string $user_email;
    public string $display_name;

    public function __construct(int $id, string $email, string $displayName = '') {
        $this->ID = $id;
        $this->user_email = $email;
        $this->display_name = $displayName;
    }
}

final class WC_Order_Item_Product {
    public function set_product(mixed $product): void {}
    public function set_name(string $name): void {}
    public function set_quantity(int $quantity): void {}
    public function set_subtotal(float $subtotal): void {}
    public function set_total(float $total): void {}
}

final class WC_Order_Item_Fee {
    public function set_name(string $name): void {}
    public function set_amount(float $amount): void {}
    public function set_total(float $total): void {}
    public function set_tax_status(string $status): void {}
}

final class MGWS_Contract_Order {
    private int $id;
    private string $status;

    public function __construct(int $id, string $status) {
        $this->id = $id;
        $this->status = $status;
    }

    public function get_id(): int { return $this->id; }
    public function set_created_via(string $value): void {}
    public function set_currency(string $currency): void {}
    public function set_payment_method(string $method): void {}
    public function set_payment_method_title(string $title): void {}
    public function set_billing_first_name(string $value): void {}
    public function set_billing_last_name(string $value): void {}
    public function set_billing_email(string $value): void {}
    public function set_billing_phone(string $value): void {}
    public function set_customer_note(string $value): void {}
    public function add_meta_data(string $key, mixed $value, bool $unique = false): void {}
    public function add_item(mixed $item): void {}
    public function calculate_totals(bool $andTaxes = true): void {}
    public function set_total(float $total): void {}
    public function save(): void {}
    public function update_status(string $status): void { $this->status = $status; }
}

final class MGWS_Contract_Prepared_Query {
    public string $sql;
    public array $params;

    public function __construct(string $sql, array $params) {
        $this->sql = $sql;
        $this->params = $params;
    }
}

final class MGWS_Contract_WPDB {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $levels = array();
    public array $moves = array();
    public array $barcodes = array();
    public array $idempotency = array();
    public array $loyaltyCards = array();
    public array $loyaltyMovements = array();
    public array $restockRows = array();
    public array $transactions = array();
    public array $schemaTables = array();
    public array $dbDeltaCalls = array();
    public bool $schemaStrict = false;
    public mixed $reservation_hook = null;

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare(string $sql, mixed ...$params): MGWS_Contract_Prepared_Query {
        if (count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }
        return new MGWS_Contract_Prepared_Query($sql, $params);
    }

    public function apply_db_delta(string $sql): array {
        $this->dbDeltaCalls[] = $sql;
        if (!preg_match('/^CREATE\\s+TABLE\\s+([^\\s(]+)/i', trim($sql), $tableMatch)) {
            throw new RuntimeException('dbDelta mock expected a CREATE TABLE statement');
        }

        $table = trim($tableMatch[1], '`');
        $created = !isset($this->schemaTables[$table]);
        if ($created) {
            $this->schemaTables[$table] = array('columns' => array(), 'indexes' => array());
        }

        foreach (preg_split('/\\R/', $sql) as $line) {
            if (preg_match('/^\s*([a-z_][a-z0-9_]*)\s+(?:BIGINT|INT|SMALLINT|VARCHAR|CHAR|DATETIME|TEXT|LONGTEXT)/i', $line, $columnMatch)) {
                $this->schemaTables[$table]['columns'][$columnMatch[1]] = true;
            }
            $index = null;
            if (preg_match('/^\\s*PRIMARY KEY\\s+\\(([^)]+)\\)/i', $line, $matches)) {
                $index = array('name' => 'PRIMARY', 'unique' => true, 'columns' => $matches[1]);
            } elseif (preg_match('/^\\s*UNIQUE KEY\\s+([A-Za-z0-9_]+)\\s+\\(([^)]+)\\)/i', $line, $matches)) {
                $index = array('name' => $matches[1], 'unique' => true, 'columns' => $matches[2]);
            } elseif (preg_match('/^\\s*KEY\\s+([A-Za-z0-9_]+)\\s+\\(([^)]+)\\)/i', $line, $matches)) {
                $index = array('name' => $matches[1], 'unique' => false, 'columns' => $matches[2]);
            }
            if ($index === null) {
                continue;
            }
            $index['columns'] = array_map(static fn (string $column): string => trim($column, " `"), explode(',', $index['columns']));
            $this->schemaTables[$table]['indexes'][$index['name']] = array(
                'unique' => $index['unique'],
                'columns' => $index['columns'],
            );
        }

        return array(($created ? 'Created table ' : 'Updated table ') . $table);
    }

    public function insert(string $table, array $data, mixed $format = null): int|false {
        $this->last_error = '';
        if (str_ends_with($table, 'mg_pos_idempotency')) {
            $key = (string) $data['idempotency_key'];
            if (isset($this->idempotency[$key])) {
                $this->last_error = 'duplicate uq_idempotency_key';
                return false;
            }
            $data['id'] = ++$this->insert_id;
            $this->idempotency[$key] = $data;
            if ($this->reservation_hook !== null) {
                $hook = $this->reservation_hook;
                $this->reservation_hook = null;
                $hook();
            }
            return 1;
        }
        if (str_ends_with($table, 'mg_stock_moves')) {
            $data['id'] = count($this->moves) + 1;
            $this->insert_id = (int) $data['id'];
            $this->moves[] = $data;
            return 1;
        }
        if (str_ends_with($table, 'mg_stock_levels')) {
            $this->levels[] = $data;
            return 1;
        }
        if (str_ends_with($table, 'mg_loyalty_cards')) {
            foreach ($this->loyaltyCards as $card) {
                if ((int) $card['customer_id'] === (int) $data['customer_id']) {
                    $this->last_error = 'duplicate uq_customer';
                    return false;
                }
                if (!empty($data['card_number']) && (string) $card['card_number'] === (string) $data['card_number']) {
                    $this->last_error = 'duplicate uq_card_number';
                    return false;
                }
            }
            $data['id'] = ++$this->insert_id;
            $this->loyaltyCards[(int) $data['id']] = $data;
            return 1;
        }
        if (str_ends_with($table, 'mg_loyalty_movements')) {
            $data['id'] = ++$this->insert_id;
            $this->loyaltyMovements[(int) $data['id']] = $data;
            return 1;
        }
        if (isset($this->schemaTables[$table])) {
            foreach ($this->schemaTables[$table]['indexes'] as $name => $index) {
                if (empty($index['unique'])) {
                    continue;
                }
                foreach ($this->restockRows[$table] ?? array() as $row) {
                    $duplicate = true;
                    foreach ($index['columns'] as $column) {
                        if ((string) ($row[$column] ?? '') !== (string) ($data[$column] ?? '')) {
                            $duplicate = false;
                            break;
                        }
                    }
                    if ($duplicate) {
                        $this->last_error = 'duplicate ' . $name;
                        return false;
                    }
                }
            }
            $data['id'] = ++$this->insert_id;
            $this->restockRows[$table][(int) $data['id']] = $data;
            return 1;
        }
        return false;
    }

    public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int|false {
        if (str_ends_with($table, 'mg_loyalty_cards')) {
            foreach ($this->loyaltyCards as $id => $card) {
                $matches = true;
                foreach ($where as $column => $value) {
                    if ((string) ($card[$column] ?? '') !== (string) $value) {
                        $matches = false;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
                foreach ($this->loyaltyCards as $otherId => $other) {
                    if ($otherId !== $id && !empty($data['card_number']) && (string) $other['card_number'] === (string) $data['card_number']) {
                        return false;
                    }
                }
                $this->loyaltyCards[$id] = array_merge($card, $data);
                return 1;
            }
            return 0;
        }
        if (isset($this->schemaTables[$table]) && !str_ends_with($table, 'mg_pos_idempotency')) {
            foreach ($this->restockRows[$table] ?? array() as $id => $row) {
                $matches = true;
                foreach ($where as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== (string) $value) {
                        $matches = false;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
                $this->restockRows[$table][$id] = array_merge($row, $data);
                return 1;
            }
            return 0;
        }
        if (!str_ends_with($table, 'mg_pos_idempotency')) {
            return false;
        }
        $key = (string) ($where['idempotency_key'] ?? '');
        if (!isset($this->idempotency[$key])) {
            return 0;
        }
        foreach ($where as $column => $value) {
            if ((string) ($this->idempotency[$key][$column] ?? '') !== (string) $value) {
                return 0;
            }
        }
        $this->idempotency[$key] = array_merge($this->idempotency[$key], $data);
        return 1;
    }

    public function delete(string $table, array $where, mixed $whereFormat = null): int|false {
        if (!isset($this->schemaTables[$table])) {
            return false;
        }
        foreach ($this->restockRows[$table] ?? array() as $id => $row) {
            $matches = true;
            foreach ($where as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            unset($this->restockRows[$table][$id]);
            return 1;
        }
        return 0;
    }

    private function movement_rows(mixed $query): array {
        $sql = $query instanceof MGWS_Contract_Prepared_Query ? $query->sql : (string) $query;
        $params = $query instanceof MGWS_Contract_Prepared_Query ? $query->params : array();
        $index = 0;
        $filters = array();
        foreach (array('site_id', 'product_id', 'user_id') as $column) {
            if (str_contains($sql, $column . ' = %d')) {
                $filters[$column] = (int) ($params[$index++] ?? 0);
            }
        }
        if (str_contains($sql, 'variation_id = %d')) {
            $filters['variation_id'] = (int) ($params[$index++] ?? 0);
        }
        foreach (array('source_type', 'reason_code', 'stock_effect') as $column) {
            if (str_contains($sql, $column . ' = %s')) {
                $filters[$column] = (string) ($params[$index++] ?? '');
            }
        }
        $dateFrom = str_contains($sql, 'ts_gmt >= %s') ? (string) ($params[$index++] ?? '') : '';
        $dateTo = str_contains($sql, 'ts_gmt <= %s') ? (string) ($params[$index++] ?? '') : '';
        $rows = array_values(array_filter($this->moves, static function (array $movement) use ($filters, $dateFrom, $dateTo): bool {
            foreach ($filters as $column => $value) {
                if ((string) ($movement[$column] ?? '') !== (string) $value) {
                    return false;
                }
            }
            return ($dateFrom === '' || (string) $movement['ts_gmt'] >= $dateFrom) && ($dateTo === '' || (string) $movement['ts_gmt'] <= $dateTo);
        }));
        usort($rows, static function (array $left, array $right): int {
            $time = strcmp((string) $right['ts_gmt'], (string) $left['ts_gmt']);
            return $time !== 0 ? $time : ((int) $right['id'] <=> (int) $left['id']);
        });
        return $rows;
    }

    public function get_row(mixed $query, mixed $output = null): ?array {
        if (!$query instanceof MGWS_Contract_Prepared_Query) {
            return null;
        }
        if (str_contains($query->sql, 'mg_pos_idempotency')) {
            $key = (string) ($query->params[0] ?? '');
            return $this->idempotency[$key] ?? null;
        }
        if (str_contains($query->sql, 'mg_stock_moves') && str_contains($query->sql, 'WHERE id = %d')) {
            $movementId = (int) ($query->params[0] ?? 0);
            foreach ($this->moves as $movement) {
                if ((int) $movement['id'] === $movementId) {
                    return $movement;
                }
            }
            return null;
        }
        if (str_contains($query->sql, 'mg_loyalty_cards')) {
            if (str_contains($query->sql, 'customer_id =')) {
                $customerId = (int) ($query->params[0] ?? 0);
                foreach ($this->loyaltyCards as $card) {
                    if ((int) $card['customer_id'] === $customerId) {
                        return $card;
                    }
                }
            }
            if (str_contains($query->sql, 'card_number =')) {
                $cardNumber = (string) ($query->params[0] ?? '');
                foreach ($this->loyaltyCards as $card) {
                    if ((string) ($card['card_number'] ?? '') === $cardNumber) {
                        return $card;
                    }
                }
            }
        }
        if (str_contains($query->sql, 'mg_stock_levels')) {
            $warehouseId = (int) ($query->params[0] ?? 0);
            $productId = (int) ($query->params[1] ?? 0);
            $variationId = (int) ($query->params[2] ?? 0);
            $room = (string) ($query->params[3] ?? '');
            $rack = (string) ($query->params[4] ?? '');
            $shelf = (string) ($query->params[5] ?? '');
            foreach ($this->levels as $level) {
                if ((int) ($level['warehouse_id'] ?? 0) === $warehouseId && (int) ($level['product_id'] ?? 0) === $productId && (int) ($level['variation_id'] ?? 0) === $variationId && (string) ($level['room'] ?? '') === $room && (string) ($level['rack'] ?? '') === $rack && (string) ($level['shelf'] ?? '') === $shelf) {
                    return $level;
                }
            }
        }
        foreach ($this->restockRows as $table => $rows) {
            if (!str_contains($query->sql, $table)) {
                continue;
            }
            if (str_contains($query->sql, 'WHERE id=%d')) {
                $id = (int) ($query->params[0] ?? 0);
                return $rows[$id] ?? null;
            }
            return $rows === array() ? null : reset($rows);
        }
        return null;
    }

    public function get_results(mixed $query, mixed $output = null): array {
        $sql = $query instanceof MGWS_Contract_Prepared_Query ? $query->sql : (string) $query;
        $params = $query instanceof MGWS_Contract_Prepared_Query ? $query->params : array();
        if (str_contains($sql, 'mg_stock_moves')) {
            $rows = $this->movement_rows($query);
            if (!str_contains($sql, 'LIMIT %d OFFSET %d')) {
                return $rows;
            }
            return array_slice($rows, (int) ($params[count($params) - 1] ?? 0), (int) ($params[count($params) - 2] ?? 0));
        }
        if (str_contains($sql, 'mg_loyalty_movements')) {
            $customerId = (int) ($params[0] ?? 0);
            $perPage = (int) ($params[1] ?? 20);
            $offset = (int) ($params[2] ?? 0);
            $rows = array_values(array_filter($this->loyaltyMovements, static function (array $movement) use ($customerId): bool {
                return (int) $movement['customer_id'] === $customerId;
            }));
            usort($rows, static function (array $left, array $right): int {
                $time = strcmp((string) $right['created_at_gmt'], (string) $left['created_at_gmt']);
                return $time !== 0 ? $time : ((int) $right['id'] <=> (int) $left['id']);
            });
            return array_slice($rows, $offset, $perPage);
        }
        foreach ($this->restockRows as $table => $rows) {
            if (str_contains($sql, $table)) {
                return array_values($rows);
            }
        }
        if (!str_contains($sql, 'mg_stock_levels')) {
            return array();
        }
        if (!str_contains($sql, 'product_id=%d AND variation_id=%d')) {
            return $this->levels;
        }
        $productId = (int) ($params[0] ?? 0);
        $variationId = (int) ($params[1] ?? 0);
        $positiveOnly = str_contains($sql, 'qty >= 1');
        return array_values(array_filter($this->levels, static function (array $level) use ($productId, $variationId, $positiveOnly): bool {
            return (int) $level['product_id'] === $productId && (int) $level['variation_id'] === $variationId && (!$positiveOnly || (int) $level['qty'] >= 1);
        }));
    }

    public function get_var(mixed $query): mixed {
        if (is_string($query) && str_contains($query, 'mg_stock_moves') && str_contains($query, 'COUNT(*)')) {
            return count($this->movement_rows($query));
        }
        if (is_string($query) && str_contains($query, 'mg_loyalty_cards')) {
            if (str_contains($query, 'COALESCE(SUM(points_balance)')) {
                return array_sum(array_map(static fn (array $card): int => (int) $card['points_balance'], $this->loyaltyCards));
            }
            if (str_contains($query, 'card_number IS NOT NULL')) {
                return count(array_filter($this->loyaltyCards, static fn (array $card): bool => !empty($card['card_number'])));
            }
            if (str_contains($query, 'COUNT(*)')) {
                return count($this->loyaltyCards);
            }
        }
        if (!$query instanceof MGWS_Contract_Prepared_Query) {
            return 0;
        }
        if (str_contains($query->sql, 'mg_stock_moves') && str_contains($query->sql, 'COUNT(*)')) {
            return count($this->movement_rows($query));
        }
        if (str_contains($query->sql, 'SHOW TABLES LIKE')) {
            return (string) ($query->params[0] ?? '');
        }
        if (str_contains($query->sql, 'atum_product_data') && str_contains($query->sql, 'SELECT product_id')) {
            return (int) ($this->barcodes[(string) ($query->params[0] ?? '')] ?? 0);
        }
        if (str_contains($query->sql, 'meta_key = \'_barcode\'')) {
            return (int) ($this->barcodes[(string) ($query->params[0] ?? '')] ?? 0);
        }
        if (str_contains($query->sql, 'mg_loyalty_cards') && str_contains($query->sql, 'SELECT customer_id')) {
            $cardNumber = (string) ($query->params[0] ?? '');
            $customerId = (int) ($query->params[1] ?? 0);
            foreach ($this->loyaltyCards as $card) {
                if ((string) ($card['card_number'] ?? '') === $cardNumber && (int) $card['customer_id'] !== $customerId) {
                    return (int) $card['customer_id'];
                }
            }
            return 0;
        }
        if (!str_contains($query->sql, 'SUM(qty)')) {
            return 0;
        }
        $productId = (int) ($query->params[0] ?? 0);
        $variationId = (int) ($query->params[1] ?? 0);
        $sum = 0;
        foreach ($this->levels as $level) {
            if ((int) $level['product_id'] === $productId && (int) $level['variation_id'] === $variationId) {
                $sum += (int) $level['qty'];
            }
        }
        return $sum;
    }

    public function get_col(mixed $query): array {
        $sql = $query instanceof MGWS_Contract_Prepared_Query ? $query->sql : (string) $query;
        if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+([^\s]+)\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"]/i', $sql, $matches)) {
            $table = trim($matches[1], '`');
            $column = $matches[2];
            return !empty($this->schemaTables[$table]['columns'][$column]) ? array($column) : array();
        }
        return array();
    }

    public function query(mixed $query): int|bool {
        if (is_string($query)) {
            $this->transactions[] = $query;
            return true;
        }
        if (!$query instanceof MGWS_Contract_Prepared_Query || !str_contains($query->sql, 'UPDATE wp_mg_stock_levels')) {
            return false;
        }
        if (str_contains($query->sql, 'qty = qty +')) {
            $delta = (int) ($query->params[0] ?? 0);
            $siteId = (int) ($query->params[1] ?? 0);
            $warehouseId = (int) ($query->params[3] ?? 0);
            $productId = (int) ($query->params[4] ?? 0);
            $variationId = (int) ($query->params[5] ?? 0);
            $room = (string) ($query->params[6] ?? '');
            $rack = (string) ($query->params[7] ?? '');
            $shelf = (string) ($query->params[8] ?? '');
            foreach ($this->levels as &$level) {
                if ((int) $level['warehouse_id'] === $warehouseId && (int) $level['product_id'] === $productId && (int) $level['variation_id'] === $variationId && (string) $level['room'] === $room && (string) $level['rack'] === $rack && (string) $level['shelf'] === $shelf && (int) $level['qty'] + $delta >= 0) {
                    $level['qty'] += $delta;
                    $level['site_id'] = $siteId;
                    unset($level);
                    return 1;
                }
            }
            unset($level);
            return 0;
        }
        if (!str_contains($query->sql, 'qty = qty -')) {
            return false;
        }
        $qty = (int) ($query->params[0] ?? 0);
        $siteId = (int) ($query->params[2] ?? 0);
        $warehouseId = (int) ($query->params[3] ?? 0);
        $productId = (int) ($query->params[4] ?? 0);
        $variationId = (int) ($query->params[5] ?? 0);
        $room = (string) ($query->params[6] ?? '');
        $rack = (string) ($query->params[7] ?? '');
        $shelf = (string) ($query->params[8] ?? '');
        foreach ($this->levels as &$level) {
            if ((int) $level['site_id'] === $siteId && (int) $level['warehouse_id'] === $warehouseId && (int) $level['product_id'] === $productId && (int) $level['variation_id'] === $variationId && (string) $level['room'] === $room && (string) $level['rack'] === $rack && (string) $level['shelf'] === $shelf && (int) $level['qty'] >= $qty) {
                $level['qty'] -= $qty;
                unset($level);
                return 1;
            }
        }
        unset($level);
        return 0;
    }
}

final class MGWS_Contract_Test_Failure extends RuntimeException {
}

function mgws_contract_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new MGWS_Contract_Test_Failure($message);
    }
}

function mgws_contract_assert_error(mixed $value, string $code, int $status): void {
    mgws_contract_assert(is_wp_error($value), 'expected WP_Error');
    mgws_contract_assert($value->get_error_code() === $code, 'expected error code ' . $code . ', got ' . $value->get_error_code());
    $data = $value->get_error_data();
    mgws_contract_assert((int) ($data['status'] ?? 0) === $status, 'expected HTTP status ' . $status);
}

function mgws_contract_assert_no_sensitive_error_text(WP_Error $error): void {
    $body = strtolower($error->get_error_code() . ' ' . $error->get_error_message() . ' ' . json_encode($error->get_error_data()));
    foreach (array('select', 'ABSPATH', 'wp_woocommerce_api_keys', 'consumer_secret', 'password', 'token', '/mnt/', '/work/', 'ada@example.test') as $forbidden) {
        mgws_contract_assert(!str_contains($body, strtolower($forbidden)), 'error response leaked forbidden text: ' . $forbidden);
    }
}

function mgws_contract_reset_pos_fixture(): void {
    $GLOBALS['wpdb'] = new MGWS_Contract_WPDB();
    $GLOBALS['wpdb']->levels = array(array(
        'site_id' => 1,
        'warehouse_id' => 10,
        'product_id' => 101,
        'variation_id' => 0,
        'qty' => 1,
        'room' => '',
        'rack' => '',
        'shelf' => '',
    ));
    $GLOBALS['mgws_test_products'] = array(101 => new MGWS_Contract_Product(101));
    $GLOBALS['mgws_test_orders'] = array();
    $GLOBALS['mgws_test_post_meta'] = array();
    $GLOBALS['mgws_test_order_creations'] = 0;
    // Questi test verificano l'idempotenza del checkout, non il turno di
    // cassa: senza questa opzione il checkout si fermerebbe al controllo turno
    // prima di arrivare alla logica che si vuole provare.
    $GLOBALS['mgws_test_options']['mgws_pos_turno_obbligatorio'] = '0';
}

function mgws_contract_reset_inventory_fixture(bool $withLevels = true): void {
    $GLOBALS['wpdb'] = new MGWS_Contract_WPDB();
    $GLOBALS['wpdb']->levels = $withLevels ? array(array(
        'site_id' => 1, 'warehouse_id' => 10, 'product_id' => 101,
        'variation_id' => 0, 'qty' => 7, 'room' => 'A', 'rack' => '1', 'shelf' => '1',
    )) : array();
    $GLOBALS['mgws_test_products'] = array(101 => new MGWS_Contract_Product(101));
    $GLOBALS['mgws_test_post_meta'] = array(
        10 => array(
            'mg_site_id' => 1,
            'mgws_wh_loc_tree' => array('rooms' => array('A' => array('all' => 1, 'racks' => array()))),
        ),
        101 => array(
            'mgws_default_site_id' => 1,
            'mgws_default_warehouse_id' => 10,
            'mgws_default_room' => 'A',
            'mgws_default_rack' => '1',
            'mgws_default_shelf' => '1',
        ),
    );
    $GLOBALS['mgws_test_warehouses'] = array(10);
    $GLOBALS['mgws_test_logged_in'] = true;
    $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true, 'mgws_stock_move' => true);
}

function mgws_contract_reset_restock_fixture(): void {
    $GLOBALS['wpdb'] = new MGWS_Contract_WPDB();
    MGWS_DB::create_or_update_tables();
    $GLOBALS['wpdb']->levels = array(
        array('site_id' => 1, 'warehouse_id' => 10, 'product_id' => 101, 'variation_id' => 102, 'qty' => 2, 'room' => 'A', 'rack' => '1', 'shelf' => '1'),
        array('site_id' => 1, 'warehouse_id' => 10, 'product_id' => 101, 'variation_id' => 0, 'qty' => 9, 'room' => 'A', 'rack' => '1', 'shelf' => '2'),
    );
    $GLOBALS['mgws_test_products'] = array(
        101 => new MGWS_Contract_Product(101),
        102 => new MGWS_Contract_Product(102, true, 101),
    );
    $GLOBALS['mgws_test_post_meta'] = array(
        10 => array('mg_site_id' => 1, 'mgws_wh_loc_tree' => array('rooms' => array('A' => array('all' => 1, 'racks' => array())))),
    );
    $GLOBALS['mgws_test_logged_in'] = true;
    $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true, 'mgws_stock_move' => true);
}

function mgws_contract_reset_movement_ledger_fixture(): void {
    mgws_contract_reset_restock_fixture();
    $GLOBALS['wpdb']->moves = array(
        array('id' => 301, 'ts_gmt' => '2026-08-01 08:00:00', 'user_id' => 7, 'type' => 'in', 'site_id' => 1, 'warehouse_id' => 10, 'warehouse_from' => 0, 'warehouse_to' => 0, 'product_id' => 101, 'variation_id' => 102, 'qty' => 3, 'room' => 'A', 'rack' => '1', 'shelf' => '1', 'ref_order_id' => 0, 'stock_effect' => 'load', 'source_type' => 'quick_load', 'source_id' => 0, 'source_line_id' => 0, 'reason_code' => 'quick_load', 'note' => 'Quick load: shelf replenishment; stock 2 -> 5'),
        array('id' => 302, 'ts_gmt' => '2026-08-02 09:00:00', 'user_id' => 8, 'type' => 'in', 'site_id' => 1, 'warehouse_id' => 10, 'warehouse_from' => 0, 'warehouse_to' => 0, 'product_id' => 101, 'variation_id' => 102, 'qty' => 4, 'room' => 'A', 'rack' => '1', 'shelf' => '1', 'ref_order_id' => 0, 'stock_effect' => 'load', 'source_type' => 'receipt', 'source_id' => 401, 'source_line_id' => 501, 'reason_code' => 'supplier_delivery', 'note' => 'Receipt load'),
        array('id' => 303, 'ts_gmt' => '2026-08-03 10:00:00', 'user_id' => 9, 'type' => 'adjust', 'site_id' => 1, 'warehouse_id' => 10, 'warehouse_from' => 0, 'warehouse_to' => 0, 'product_id' => 101, 'variation_id' => 0, 'qty' => -2, 'room' => 'A', 'rack' => '1', 'shelf' => '2', 'ref_order_id' => 0, 'stock_effect' => 'adjustment', 'source_type' => 'inventory_count', 'source_id' => 601, 'source_line_id' => 701, 'reason_code' => 'stock_count', 'note' => 'Inventory count adjustment'),
        array('id' => 304, 'ts_gmt' => '2026-08-04 11:00:00', 'user_id' => 7, 'type' => 'out', 'site_id' => 1, 'warehouse_id' => 10, 'warehouse_from' => 10, 'warehouse_to' => 0, 'product_id' => 101, 'variation_id' => 0, 'qty' => 1, 'room' => 'A', 'rack' => '1', 'shelf' => '2', 'ref_order_id' => 801, 'stock_effect' => '', 'source_type' => '', 'source_id' => 0, 'source_line_id' => 0, 'reason_code' => '', 'note' => 'POS checkout'),
    );
}

function mgws_contract_reset_loyalty_fixture(): void {
    $GLOBALS['wpdb'] = new MGWS_Contract_WPDB();
    $GLOBALS['mgws_test_users'] = array(
        201 => array(
            'email' => 'ada@example.test',
            'meta' => array(
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
            ),
        ),
        202 => array(
            'email' => 'grace@example.test',
            'meta' => array(
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
            ),
        ),
    );
    $GLOBALS['mgws_test_logged_in'] = true;
    $GLOBALS['mgws_test_capabilities'] = array(
        'mgws_stock_read' => true,
        'mgws_stock_move' => true,
    );
}

function mgws_contract_reset_schema_fixture(bool $stockOnly = false): MGWS_Contract_WPDB {
    $wpdb = new MGWS_Contract_WPDB();
    $wpdb->schemaStrict = true;
    $GLOBALS['wpdb'] = $wpdb;

    if (!$stockOnly) {
        return $wpdb;
    }

    $wpdb->schemaTables[MGWS_DB::table_levels()] = array('indexes' => array('PRIMARY' => array('unique' => true, 'columns' => array('id'))));
    $wpdb->schemaTables[MGWS_DB::table_moves()] = array('indexes' => array('PRIMARY' => array('unique' => true, 'columns' => array('id'))));
    $wpdb->levels = array(array(
        'id' => 41, 'site_id' => 1, 'warehouse_id' => 10, 'product_id' => 101,
        'variation_id' => 0, 'qty' => 7, 'room' => 'A', 'rack' => '1', 'shelf' => '2',
        'updated_at_gmt' => '2026-07-18 10:00:00',
    ));
    $wpdb->moves = array(array(
        'id' => 42, 'ts_gmt' => '2026-07-18 10:01:00', 'user_id' => 7, 'type' => 'adjustment',
        'site_id' => 1, 'warehouse_id' => 10, 'warehouse_from' => 0, 'warehouse_to' => 0,
        'product_id' => 101, 'variation_id' => 0, 'qty' => 7, 'room' => 'A', 'rack' => '1',
        'shelf' => '2', 'ref_order_id' => 0, 'note' => 'legacy stock movement',
    ));
    return $wpdb;
}

function mgws_contract_assert_schema_index(MGWS_Contract_WPDB $wpdb, string $table, string $index, bool $unique, array $columns): void {
    $definition = $wpdb->schemaTables[$table]['indexes'][$index] ?? null;
    mgws_contract_assert(is_array($definition), 'missing schema index ' . $table . '.' . $index);
    mgws_contract_assert(($definition['unique'] ?? null) === $unique, 'schema uniqueness differs for ' . $table . '.' . $index);
    mgws_contract_assert(($definition['columns'] ?? null) === $columns, 'schema columns differ for ' . $table . '.' . $index);
}

/// Dice se una tabella ha ricevuto una chiamata dbDelta.
///
/// Lo stub registra lo SQL intero, non il nome della tabella, quindi qui si
/// guarda il CREATE TABLE che lo apre invece di affidarsi a un indice
/// posizionale: cosi' l'ordine delle chiamate non conta e una tabella spostata
/// non falsifica l'allarme.
function mgws_contract_schema_table_was_migrated(MGWS_Contract_WPDB $wpdb, string $table): bool {
    $pattern = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($table, '/') . '`?\s*\(/i';
    foreach ($wpdb->dbDeltaCalls as $sql) {
        if (preg_match($pattern, ltrim($sql))) {
            return true;
        }
    }
    return false;
}

function mgws_contract_assert_schema_columns(MGWS_Contract_WPDB $wpdb, string $table, array $columns): void {
    foreach ($columns as $column) {
        mgws_contract_assert(
            !empty($wpdb->schemaTables[$table]['columns'][$column]),
            'missing schema column ' . $table . '.' . $column
        );
    }
}

function mgws_contract_assert_no_destructive_schema_sql(): void {
    $sources = array(dirname(__DIR__) . '/includes/mgws-db.php', __FILE__);
    $dangerous = array(
        'drop' . ' table ' . 'mg_stock_levels',
        'drop' . ' table ' . 'mg_stock_moves',
        'truncate' . ' table ' . 'mg_stock_levels',
        'truncate' . ' table ' . 'mg_stock_moves',
    );

    foreach ($sources as $source) {
        $content = file_get_contents($source);
        mgws_contract_assert(is_string($content), 'cannot inspect schema source ' . $source);
        foreach ($dangerous as $statement) {
            mgws_contract_assert(!str_contains(strtolower($content), $statement), 'destructive stock SQL found in ' . $source);
        }
    }
}

function mgws_contract_assert_loyalty_customer_shape(array $response, int $customerId, string $cardNumber, int $points): void {
    mgws_contract_assert((int) ($response['user_id'] ?? 0) === $customerId, 'loyalty response must expose user_id');
    mgws_contract_assert((int) ($response['customer_id'] ?? 0) === $customerId, 'loyalty response must expose customer_id');
    mgws_contract_assert(($response['card_number'] ?? null) === $cardNumber, 'loyalty response must expose the requested card number');
    mgws_contract_assert(($response['first_name'] ?? '') === 'Ada', 'loyalty response must expose the customer first name');
    mgws_contract_assert(($response['last_name'] ?? '') === 'Lovelace', 'loyalty response must expose the customer last name');
    mgws_contract_assert(($response['email'] ?? '') === 'ada@example.test', 'loyalty response must expose the customer email');
    mgws_contract_assert((int) ($response['points'] ?? -1) === $points, 'loyalty response must expose the current point balance');
}

function mgws_contract_checkout_payload(string $idempotencyKey = 'mgws-test-checkout-0001'): array {
    return array(
        'idempotency_key' => $idempotencyKey,
        'operation_type' => 'sale',
        'effective_operation_type' => 'sale',
        'payment_method' => 'cash',
        'payment_method_title' => 'Cash',
        'set_paid' => true,
        'customer' => array('first_name' => 'Ada', 'email' => 'ada@example.test'),
        'sale_items' => array(array(
            'product_id' => 101,
            'variation_id' => 0,
            'quantity' => 1,
            'sku' => 'SKU-101',
            'name' => 'Product 101',
            'unit_price' => 10.0,
            'subtotal' => 10.0,
        )),
        'return_items' => array(),
        'totals' => array('totale' => 10.0),
        'meta_data' => array(
            array('key' => '_punto_vendita', 'value' => 'Cassa POS'),
            array('key' => '_data_operazione', 'value' => '2026-07-18T10:00:00Z'),
        ),
    );
}

function mgws_contract_assert_checkout_success(mixed $response): array {
    mgws_contract_assert(!is_wp_error($response), 'checkout must not return WP_Error');
    mgws_contract_assert(is_array($response), 'checkout response must be an array');
    mgws_contract_assert(($response['success'] ?? false) === true, 'checkout success flag must be true');
    mgws_contract_assert((int) ($response['order_id'] ?? 0) > 0, 'checkout must return an order id');
    return $response;
}

function mgws_contract_boot_plugin(): MGWS_REST_API {
    $pluginFile = dirname(__DIR__) . '/mg-warehouse-stock.php';
    mgws_contract_assert(is_file($pluginFile), 'plugin bootstrap is missing');
    require_once $pluginFile;
    mgws_contract_assert(class_exists('MGWS_REST_API'), 'MGWS_REST_API was not loaded by the plugin bootstrap');
    return MGWS_REST_API::instance();
}

function mgws_contract_register_routes(MGWS_REST_API $api): array {
    $GLOBALS['mgws_test_routes'] = array();
    $api->register_routes();
    return $GLOBALS['mgws_test_routes'];
}

function mgws_contract_create_receiving_purchase_order(MGWS_REST_API $api, string $suffix): array {
    $supplier = $api->route_inventory_suppliers_create(new WP_REST_Request(array(), array(
        'name' => 'Receiving Supplier ' . $suffix,
        'email' => 'receiving-' . strtolower($suffix) . '@example.test',
    )));
    mgws_contract_assert(!is_wp_error($supplier), 'receiving fixture supplier must be created');

    $purchaseOrder = $api->route_inventory_purchase_orders_create(new WP_REST_Request(array(), array(
        'site_id' => 1,
        'warehouse_id' => 10,
        'supplier_id' => (int) $supplier['id'],
        'document_number' => 'PO-RECV-' . $suffix,
    )));
    mgws_contract_assert(!is_wp_error($purchaseOrder), 'receiving fixture purchase order must be created');
    $line = $api->route_inventory_purchase_order_line_upsert(new WP_REST_Request(array('purchase_order_id' => (int) $purchaseOrder['id']), array(
        'product_id' => 101,
        'variation_id' => 102,
        'ordered_qty' => 10,
        'unit_cost' => '15.00',
    )));
    mgws_contract_assert(!is_wp_error($line), 'receiving fixture purchase order line must be created');
    $ordered = $api->route_inventory_purchase_order_status(new WP_REST_Request(array('purchase_order_id' => (int) $purchaseOrder['id']), array('status' => 'ordered')));
    mgws_contract_assert(!is_wp_error($ordered), 'receiving fixture purchase order must be ordered');
    return array('supplier' => $supplier, 'purchase_order' => $ordered, 'line' => $line);
}

function mgws_contract_route_exists(array $routes, string $namespace, string $route, string $method): bool {
    if (!isset($routes[$namespace][$route])) {
        return false;
    }

    $definition = $routes[$namespace][$route];
    if (isset($definition['methods'])) {
        return $definition['methods'] === $method;
    }

    foreach ($definition as $endpoint) {
        if (is_array($endpoint) && ($endpoint['methods'] ?? null) === $method) {
            return true;
        }
    }

    return false;
}

function mgws_contract_route_permission_callback(array $routes, string $namespace, string $route, string $method): mixed {
    $definition = $routes[$namespace][$route] ?? null;
    if (!is_array($definition)) {
        return null;
    }
    if (($definition['methods'] ?? null) === $method) {
        return $definition['permission_callback'] ?? null;
    }
    foreach ($definition as $endpoint) {
        if (is_array($endpoint) && ($endpoint['methods'] ?? null) === $method) {
            return $endpoint['permission_callback'] ?? null;
        }
    }
    return null;
}

function mgws_contract_parse_arguments(array $arguments): array {
    $options = array(
        'list' => false,
        'filter' => null,
        'self_check_failure' => false,
        'fail_on_pending' => false,
    );

    for ($index = 1; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if ($argument === '--list') {
            $options['list'] = true;
            continue;
        }
        if ($argument === '--self-check-failure') {
            $options['self_check_failure'] = true;
            continue;
        }
        if ($argument === '--fail-on-pending') {
            $options['fail_on_pending'] = true;
            continue;
        }
        if ($argument === '--filter') {
            if (!isset($arguments[$index + 1])) {
                throw new InvalidArgumentException('--filter requires a test name');
            }
            $options['filter'] = $arguments[++$index];
            continue;
        }
        if (str_starts_with($argument, '--filter=')) {
            $options['filter'] = substr($argument, strlen('--filter='));
            continue;
        }
        throw new InvalidArgumentException('unknown option: ' . $argument);
    }

    return $options;
}

function mgws_contract_tests(MGWS_REST_API $api): array {
    return array(
        array(
            'name' => 'schema_happy',
            'description' => 'dbDelta creates all tables, upgrades stock-only schemas, and preserves rows on repeat migrations',
            'run' => static function (): void {
                $fresh = mgws_contract_reset_schema_fixture();
                MGWS_DB::create_or_update_tables();
                $tables = array(
                    MGWS_DB::table_levels(),
                    MGWS_DB::table_moves(),
                    MGWS_DB::table_pos_idempotency(),
                    MGWS_DB::table_loyalty_cards(),
                    MGWS_DB::table_loyalty_movements(),
                    MGWS_DB::table_fornitori(),
                    MGWS_DB::table_reorder_rules(),
                    MGWS_DB::table_purchase_orders(),
                    MGWS_DB::table_purchase_order_lines(),
                    MGWS_DB::table_receipts(),
                    MGWS_DB::table_receipt_lines(),
                    MGWS_DB::table_backorders(),
                    MGWS_DB::table_inventory_count_sessions(),
                    MGWS_DB::table_inventory_count_lines(),
                    MGWS_DB::table_stock_reason_codes(),
                    MGWS_DB::table_employees(),
                );
                foreach ($tables as $table) {
                    mgws_contract_assert(isset($fresh->schemaTables[$table]), 'fresh schema must create ' . $table);
                }
                // Elenco le tabelle gestite da dbDelta invece di contarle: un
                // numero magico va stale appena una tabella entra o esce, e
                // il falso allarme e' peggio di una tabella dimenticata.
                $dbDeltaTables = array(
                    MGWS_DB::table_levels(),
                    MGWS_DB::table_movements(),
                    MGWS_DB::table_moves(),
                    MGWS_DB::table_pos_idempotency(),
                    MGWS_DB::table_pos_shifts(),
                    MGWS_DB::table_loyalty_cards(),
                    MGWS_DB::table_loyalty_movements(),
                    MGWS_DB::table_fornitori(),
                    MGWS_DB::table_reorder_rules(),
                    MGWS_DB::table_purchase_orders(),
                    MGWS_DB::table_purchase_order_lines(),
                    MGWS_DB::table_receipts(),
                    MGWS_DB::table_receipt_lines(),
                    MGWS_DB::table_backorders(),
                    MGWS_DB::table_inventory_count_sessions(),
                    MGWS_DB::table_inventory_count_lines(),
                    MGWS_DB::table_stock_reason_codes(),
                    MGWS_DB::table_employees(),
                );
                foreach ($dbDeltaTables as $dbDeltaTable) {
                    mgws_contract_assert(
                        mgws_contract_schema_table_was_migrated($fresh, $dbDeltaTable),
                        'fresh schema must run dbDelta for ' . $dbDeltaTable
                    );
                }
                mgws_contract_assert(
                    count($fresh->dbDeltaCalls) === count($dbDeltaTables),
                    'dbDelta must run once per managed table, with no leftovers'
                );
                mgws_contract_assert(MGWS_DB::loyalty_tables_exist(), 'fresh schema must make loyalty storage available');
                mgws_contract_assert(MGWS_DB::inventory_restock_tables_exist(), 'fresh schema must make inventory-restock storage available');
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_pos_idempotency(), 'uq_idempotency_key', true, array('idempotency_key'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_pos_idempotency(), 'idx_payload_hash', false, array('payload_hash'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_pos_idempotency(), 'idx_order', false, array('order_id'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_loyalty_cards(), 'uq_customer', true, array('customer_id'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_loyalty_cards(), 'uq_card_number', true, array('card_number'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_loyalty_cards(), 'idx_card_lookup', false, array('card_number'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_loyalty_movements(), 'idx_customer_time', false, array('customer_id', 'created_at_gmt', 'id'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_fornitori(), 'idx_active', false, array('active'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_fornitori(), 'idx_name', false, array('name'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_reorder_rules(), 'uq_reorder_item', true, array('site_id', 'warehouse_id', 'product_id', 'variation_id'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_purchase_orders(), 'uq_purchase_order_number', true, array('site_id', 'document_number'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_receipts(), 'uq_receipt_number', true, array('site_id', 'document_number'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_inventory_count_lines(), 'uq_count_line', true, array('count_session_id', 'warehouse_id', 'product_id', 'variation_id', 'room', 'rack', 'shelf'));
                mgws_contract_assert_schema_index($fresh, MGWS_DB::table_stock_reason_codes(), 'uq_reason_code', true, array('site_id', 'code'));
                mgws_contract_assert_schema_columns($fresh, MGWS_DB::table_moves(), array('stock_effect', 'source_type', 'source_id', 'source_line_id', 'reason_code'));
                mgws_contract_assert_schema_columns($fresh, MGWS_DB::table_receipts(), array('purchase_order_id', 'status', 'idempotency_key', 'validated_at_gmt'));
                mgws_contract_assert_schema_columns($fresh, MGWS_DB::table_inventory_count_sessions(), array('status', 'approved_by_user_id', 'posted_at_gmt'));

                $legacy = mgws_contract_reset_schema_fixture(true);
                $legacyLevels = serialize($legacy->levels);
                $legacyMoves = serialize($legacy->moves);
                MGWS_DB::create_or_update_tables();
                mgws_contract_assert(serialize($legacy->levels) === $legacyLevels, 'stock-level rows must survive a stock-only migration');
                mgws_contract_assert(serialize($legacy->moves) === $legacyMoves, 'stock-move rows must survive a stock-only migration');

                $idempotency = array(
                    'idempotency_key' => 'schema-repeat-key', 'payload_hash' => str_repeat('a', 64), 'status' => 'succeeded',
                    'order_id' => 9001, 'user_id' => 7, 'http_status' => 200, 'response_json' => '{}',
                    'error_code' => '', 'error_message' => null, 'recovery_state' => '',
                    'created_at_gmt' => '2026-07-18 10:02:00', 'updated_at_gmt' => '2026-07-18 10:02:00',
                );
                mgws_contract_assert($legacy->insert(MGWS_DB::table_pos_idempotency(), $idempotency) === 1, 'idempotency fixture row must be inserted');
                mgws_contract_assert($legacy->insert(MGWS_DB::table_loyalty_cards(), array(
                    'customer_id' => 201, 'card_number' => 'SCHEMA-CARD-201', 'tier' => 'silver', 'points_balance' => 9,
                    'created_at_gmt' => '2026-07-18 10:03:00', 'updated_at_gmt' => '2026-07-18 10:03:00',
                )) === 1, 'loyalty-card fixture row must be inserted');
                mgws_contract_assert($legacy->insert(MGWS_DB::table_loyalty_movements(), array(
                    'customer_id' => 201, 'direction' => 'add', 'points_delta' => 9, 'balance_after' => 9,
                    'reference' => 'SCHEMA-1', 'note' => 'migration fixture', 'created_by_user_id' => 7,
                    'created_at_gmt' => '2026-07-18 10:04:00',
                )) === 1, 'loyalty-movement fixture row must be inserted');
                $snapshot = serialize(array($legacy->levels, $legacy->moves, $legacy->idempotency, $legacy->loyaltyCards, $legacy->loyaltyMovements));
                MGWS_DB::create_or_update_tables();
                MGWS_DB::create_or_update_tables();
                mgws_contract_assert(serialize(array($legacy->levels, $legacy->moves, $legacy->idempotency, $legacy->loyaltyCards, $legacy->loyaltyMovements)) === $snapshot, 'double migration must preserve all existing row data');
            },
        ),
        array(
            'name' => 'schema_failure',
            'description' => 'a malformed index fixture is repaired and duplicate idempotency/card keys fail deterministically',
            'run' => static function (): void {
                $wpdb = mgws_contract_reset_schema_fixture();
                MGWS_DB::create_or_update_tables();
                unset($wpdb->schemaTables[MGWS_DB::table_loyalty_cards()]['indexes']['idx_card_lookup']);
                unset($wpdb->schemaTables[MGWS_DB::table_stock_reason_codes()]['indexes']['uq_reason_code']);
                MGWS_DB::create_or_update_tables();
                mgws_contract_assert_schema_index($wpdb, MGWS_DB::table_loyalty_cards(), 'idx_card_lookup', false, array('card_number'));
                mgws_contract_assert_schema_index($wpdb, MGWS_DB::table_stock_reason_codes(), 'uq_reason_code', true, array('site_id', 'code'));

                $idempotency = array(
                    'idempotency_key' => 'schema-duplicate-key', 'payload_hash' => str_repeat('b', 64), 'status' => 'processing',
                    'order_id' => 0, 'user_id' => 7, 'http_status' => 0, 'response_json' => null,
                    'error_code' => '', 'error_message' => null, 'recovery_state' => '',
                    'created_at_gmt' => '2026-07-18 10:05:00', 'updated_at_gmt' => '2026-07-18 10:05:00',
                );
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_pos_idempotency(), $idempotency) === 1, 'first idempotency key insert must succeed');
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_pos_idempotency(), $idempotency) === false, 'duplicate idempotency key must fail');
                mgws_contract_assert($wpdb->last_error === 'duplicate uq_idempotency_key', 'duplicate idempotency key must fail deterministically');
                $card = array(
                    'customer_id' => 202, 'card_number' => 'SCHEMA-DUPLICATE-CARD', 'tier' => 'bronze', 'points_balance' => 0,
                    'created_at_gmt' => '2026-07-18 10:06:00', 'updated_at_gmt' => '2026-07-18 10:06:00',
                );
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_loyalty_cards(), $card) === 1, 'first loyalty card insert must succeed');
                $card['customer_id'] = 203;
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_loyalty_cards(), $card) === false, 'duplicate loyalty card must fail');
                mgws_contract_assert($wpdb->last_error === 'duplicate uq_card_number', 'duplicate loyalty card must fail deterministically');
                $reason = array(
                    'site_id' => 1, 'code' => 'stock_count', 'label' => 'Physical count', 'stock_effect' => 'adjustment',
                    'active' => 1, 'created_at_gmt' => '2026-07-31 10:00:00', 'updated_at_gmt' => '2026-07-31 10:00:00',
                );
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_stock_reason_codes(), $reason) === 1, 'first reason code insert must succeed');
                mgws_contract_assert($wpdb->insert(MGWS_DB::table_stock_reason_codes(), $reason) === false, 'duplicate reason code must fail');
                mgws_contract_assert($wpdb->last_error === 'duplicate uq_reason_code', 'duplicate reason code must fail deterministically');
                mgws_contract_assert_no_destructive_schema_sql();
            },
        ),
        array(
            'name' => 'route_registration',
            'description' => 'actual POS checkout registration has its callback and permission callback',
            'run' => static function () use ($api): void {
                $routes = mgws_contract_register_routes($api);
                mgws_contract_assert(
                    mgws_contract_route_exists($routes, 'mgws/v1', '/pos/checkout', 'POST'),
                    'missing POST /mgws/v1/pos/checkout'
                );
                $definition = $routes['mgws/v1']['/pos/checkout'];
                mgws_contract_assert(
                    ($definition['permission_callback'] ?? null) === array($api, 'perm_pos_checkout'),
                    'POS checkout permission callback differs from the plugin contract'
                );
                mgws_contract_assert(
                    ($definition['callback'] ?? null) === array($api, 'route_pos_checkout'),
                    'POS checkout callback differs from the plugin contract'
                );
            },
        ),
        array(
            'name' => 'inventory_restock_route_contract',
            'description' => 'inventory/restock routes preserve stable capabilities while Task 3 owns supplier, reorder, and purchase-order callbacks',
            'run' => static function () use ($api): void {
                $routes = mgws_contract_register_routes($api);
                $expected = array(
                    array('/inventory/quick-load', 'POST', 'perm_inventory_restock_mutate'),
                    array('/inventory/sites', 'GET', 'perm_inventory_restock_read'), array('/inventory/warehouses', 'GET', 'perm_inventory_restock_read'), array('/inventory/locations', 'GET', 'perm_inventory_restock_read'),
                    array('/inventory/suppliers', 'GET', 'perm_inventory_restock_read'), array('/inventory/suppliers', 'POST', 'perm_inventory_restock_mutate'),
                    array('/inventory/suppliers/(?P<supplier_id>\\d+)', 'GET', 'perm_inventory_restock_read'), array('/inventory/suppliers/(?P<supplier_id>\\d+)', 'PATCH', 'perm_inventory_restock_mutate'), array('/inventory/suppliers/(?P<supplier_id>\\d+)', 'DELETE', 'perm_inventory_restock_mutate'),
                    array('/inventory/reorder-rules', 'GET', 'perm_inventory_restock_read'), array('/inventory/reorder-rules', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/reorder-rules/(?P<rule_id>\\d+)', 'PATCH', 'perm_inventory_restock_mutate'), array('/inventory/reorder-rules/(?P<rule_id>\\d+)', 'DELETE', 'perm_inventory_restock_mutate'), array('/inventory/reorder-suggestions', 'GET', 'perm_inventory_restock_read'),
                    array('/inventory/purchase-orders', 'GET', 'perm_inventory_restock_read'), array('/inventory/purchase-orders', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/purchase-orders/(?P<purchase_order_id>\\d+)', 'GET', 'perm_inventory_restock_read'), array('/inventory/purchase-orders/(?P<purchase_order_id>\\d+)', 'PATCH', 'perm_inventory_restock_mutate'), array('/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/lines', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/status', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/purchase-orders/(?P<purchase_order_id>\\d+)/verify', 'POST', 'perm_inventory_purchase_approve'),
                    array('/inventory/receipts', 'GET', 'perm_inventory_restock_read'), array('/inventory/receipts', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/receipts/(?P<receipt_id>\\d+)', 'GET', 'perm_inventory_restock_read'), array('/inventory/receipts/(?P<receipt_id>\\d+)', 'PATCH', 'perm_inventory_restock_mutate'), array('/inventory/receipts/(?P<receipt_id>\\d+)/convalida', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/backorders', 'GET', 'perm_inventory_restock_read'),
                    array('/inventory/count-sessions', 'GET', 'perm_inventory_restock_read'), array('/inventory/count-sessions', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/count-sessions/(?P<session_id>\\d+)', 'GET', 'perm_inventory_restock_read'), array('/inventory/count-sessions/(?P<session_id>\\d+)', 'PATCH', 'perm_inventory_restock_mutate'), array('/inventory/count-sessions/(?P<session_id>\\d+)/lines', 'POST', 'perm_inventory_restock_mutate'), array('/inventory/count-sessions/(?P<session_id>\\d+)/approve', 'POST', 'perm_inventory_restock_mutate'),
                    array('/inventory/movements', 'GET', 'perm_inventory_restock_read'), array('/inventory/movements/(?P<movement_id>\\d+)', 'GET', 'perm_inventory_restock_read'),
                );
                $owned_callbacks = array(
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
                foreach ($expected as $definition) {
                    [$route, $method, $permission] = $definition;
                    mgws_contract_assert(mgws_contract_route_exists($routes, 'mgws/v1', $route, $method), 'missing inventory-restock route ' . $method . ' ' . $route);
                    mgws_contract_assert(mgws_contract_route_permission_callback($routes, 'mgws/v1', $route, $method) === array($api, $permission), 'wrong inventory-restock permission callback ' . $route);
                    $registered = $routes['mgws/v1'][$route];
                    $endpoint = isset($registered['methods']) ? $registered : null;
                    if ($endpoint === null) {
                        foreach ($registered as $candidate) {
                            if (is_array($candidate) && ($candidate['methods'] ?? null) === $method) {
                                $endpoint = $candidate;
                                break;
                            }
                        }
                    }
                    $callback = $owned_callbacks[$route . ' ' . $method] ?? 'route_inventory_restock_not_implemented';
                    mgws_contract_assert(($endpoint['callback'] ?? null) === array($api, $callback), 'wrong inventory-restock callback ' . $route);
                }
            },
        ),
        array(
            'name' => 'inventory_restock_provider_boundary',
            'description' => 'unowned inventory/restock routes retain their deferred MGWS-only provider boundary',
            'run' => static function () use ($api): void {
                $GLOBALS['mgws_test_logged_in'] = false;
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_not_logged_in', 401);
                $GLOBALS['mgws_test_logged_in'] = true;
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_forbidden', 403);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_move' => true);
                mgws_contract_assert($api->perm_inventory_restock_mutate() === true, 'mgws_stock_move must permit inventory-restock mutations');
                // Con piu' sedi e nessuna assegnata l'inventario e' bloccato,
                // salvo per chi gestisce le sedi e la dichiara nella richiesta.
                $GLOBALS['mgws_test_sites'] = array(900, 901);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_site_required', 403);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_move' => true, 'mgws_manage_employees' => true);
                mgws_contract_assert($api->perm_inventory_restock_mutate() === true, 'site managers must not need an assigned site');
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_move' => true);
                unset($GLOBALS['mgws_test_sites']);
                $result = $api->route_inventory_restock_not_implemented(new WP_REST_Request());
                mgws_contract_assert_error($result, 'mgws_not_implemented', 501);
                $message = strtolower($result->get_error_message());
                mgws_contract_assert(!str_contains($message, 'atum') && !str_contains($message, 'mycred') && !str_contains($message, 'woocommerce'), 'deferred route must not expose a provider dependency');
            },
        ),
        array(
            'name' => 'movement_ledger_happy',
            'description' => 'the authoritative ledger returns linked quick-load, receipt, count, and legacy movement records in a stable newest-first page',
            'run' => static function () use ($api): void {
                mgws_contract_reset_movement_ledger_fixture();
                $routes = mgws_contract_register_routes($api);
                $endpoint = $routes['mgws/v1']['/inventory/movements'] ?? array();
                mgws_contract_assert(($endpoint['callback'] ?? null) === array($api, 'route_inventory_movements_list'), 'movement list route must use its owned callback');

                $response = $api->route_inventory_movements_list(new WP_REST_Request(array('page' => 1, 'per_page' => 2)));
                mgws_contract_assert(!is_wp_error($response), 'movement ledger list must succeed');
                mgws_contract_assert(($response['total'] ?? 0) === 4 && count($response['items'] ?? array()) === 2, 'movement ledger must paginate the authoritative rows');
                mgws_contract_assert((int) ($response['items'][0]['id'] ?? 0) === 304 && (int) ($response['items'][1]['id'] ?? 0) === 303, 'movement ledger must be stable newest-first by timestamp and id');
                $older_page = $api->route_inventory_movements_list(new WP_REST_Request(array('page' => 2, 'per_page' => 2)));
                $quick_load = $older_page['items'][1] ?? array();
                mgws_contract_assert(($quick_load['source']['type'] ?? '') === 'quick_load' && (int) ($quick_load['stock_before'] ?? -1) === 2 && (int) ($quick_load['stock_after'] ?? -1) === 5, 'quick-load movement must retain audited before/after values');
                $base_item = $api->route_inventory_movements_list(new WP_REST_Request(array('product_id' => 101, 'variation_id' => 0, 'page' => 1, 'per_page' => 20)));
                mgws_contract_assert(!is_wp_error($base_item) && ($base_item['total'] ?? 0) === 2, 'variation_id zero must filter the base-product ledger rows');

                $filtered = $api->route_inventory_movements_list(new WP_REST_Request(array(
                    'product_id' => 101, 'variation_id' => 102, 'date_from' => '2026-08-02T00:00:00Z',
                    'date_to' => '2026-08-02T23:59:59Z', 'source' => 'receipt', 'operator' => 8,
                    'reason' => 'supplier_delivery', 'stock_effect' => 'load', 'page' => 1, 'per_page' => 20,
                )));
                mgws_contract_assert(!is_wp_error($filtered) && ($filtered['total'] ?? 0) === 1, 'movement ledger filters must select the receipt movement');
                $receipt = $filtered['items'][0] ?? array();
                foreach (array('id', 'occurred_at_gmt', 'product_id', 'variation_id', 'quantity_delta', 'stock_before', 'stock_after', 'operator_user_id', 'reason_code', 'source') as $field) {
                    mgws_contract_assert(array_key_exists($field, $receipt), 'movement ledger response must expose ' . $field);
                }
                mgws_contract_assert(($receipt['source']['type'] ?? '') === 'receipt' && (int) ($receipt['source']['id'] ?? 0) === 401 && (int) ($receipt['source']['line_id'] ?? 0) === 501, 'receipt movement must retain source document links');

                $count = $api->route_inventory_movement_get(new WP_REST_Request(array('movement_id' => 303)));
                mgws_contract_assert(!is_wp_error($count) && ($count['source']['type'] ?? '') === 'inventory_count', 'movement detail must return the count-session source');
                mgws_contract_assert((int) ($count['source']['links']['inventory_count_session_id'] ?? 0) === 601 && (int) ($count['source']['links']['inventory_count_line_id'] ?? 0) === 701, 'movement detail must expose count-session links');
                $pos = $api->route_inventory_movement_get(new WP_REST_Request(array('movement_id' => 304)));
                mgws_contract_assert(!is_wp_error($pos) && (int) ($pos['source']['links']['order_id'] ?? 0) === 801, 'legacy POS movement must retain its order link');
            },
        ),
        array(
            'name' => 'movement_ledger_failure',
            'description' => 'movement reads reject malformed filters, respect read authorization, report missing rows safely, and never mutate ledger state',
            'run' => static function () use ($api): void {
                mgws_contract_reset_movement_ledger_fixture();
                $before = $GLOBALS['wpdb']->moves;
                $levels_before = $GLOBALS['wpdb']->levels;
                foreach (array(
                    new WP_REST_Request(array('product_id' => '101 OR 1=1')),
                    new WP_REST_Request(array('date_from' => '2026-08-02')),
                    new WP_REST_Request(array('source_type' => 'receipt', 'source' => 'inventory_count')),
                    new WP_REST_Request(array('stock_effect' => 'load; SELECT')),
                    new WP_REST_Request(array('page' => 0)),
                ) as $request) {
                    $error = $api->route_inventory_movements_list($request);
                    mgws_contract_assert_error($error, 'mgws_bad_request', 400);
                    mgws_contract_assert_no_sensitive_error_text($error);
                }
                mgws_contract_assert_error($api->route_inventory_movement_get(new WP_REST_Request(array('movement_id' => 999999))), 'mgws_movement_not_found', 404);
                $GLOBALS['mgws_test_logged_in'] = false;
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_inventory_restock_read(), 'mgws_not_logged_in', 401);
                $GLOBALS['mgws_test_logged_in'] = true;
                mgws_contract_assert_error($api->perm_inventory_restock_read(), 'mgws_forbidden', 403);
                mgws_contract_assert($GLOBALS['wpdb']->moves === $before && $GLOBALS['wpdb']->levels === $levels_before, 'movement ledger reads must not mutate stock or movement rows');
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true);
                $GLOBALS['wpdb']->moves = array();
                $empty = $api->route_inventory_movements_list(new WP_REST_Request(array('page' => 1, 'per_page' => 20)));
                mgws_contract_assert(!is_wp_error($empty) && ($empty['items'] ?? null) === array() && ($empty['total'] ?? -1) === 0, 'empty movement ledger must return a typed empty page');
            },
        ),
        array(
            'name' => 'inventory_quick_load_happy',
            'description' => 'document-free quick loads add one authoritative level delta, one audited load movement, and one Woo projection',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                MGWS_DB::create_or_update_tables();
                $routes = mgws_contract_register_routes($api);
                $endpoint = $routes['mgws/v1']['/inventory/quick-load'] ?? array();
                mgws_contract_assert(($endpoint['callback'] ?? null) === array($api, 'route_inventory_quick_load'), 'quick-load route must use its owned callback');

                $response = $api->route_inventory_quick_load(new WP_REST_Request(array(), array(
                    'product_id' => 101,
                    'quantity_delta' => 3,
                    'idempotency_key' => 'quick-load-retry-0001',
                    'reason' => 'Manual shelf replenishment',
                    'note' => 'Counted and loaded at the back room',
                    'barcode' => '8001234567890',
                    'warehouse_id' => 10,
                    'room' => 'A',
                    'rack' => '1',
                    'shelf' => '1',
                )));

                mgws_contract_assert(!is_wp_error($response), 'document-free quick load must succeed without supplier or purchase-order fields: ' . (is_wp_error($response) ? $response->get_error_code() . ' ' . $response->get_error_message() : ''));
                mgws_contract_assert(($response['operation'] ?? '') === 'quick_load' && (int) ($response['previous_stock'] ?? -1) === 7 && (int) ($response['current_stock'] ?? -1) === 10 && (int) ($response['quantity_delta'] ?? 0) === 3, 'quick load response must expose additive before, after, and delta totals');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 10, 'quick load must update the authoritative MGWS level');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'quick load must create exactly one audit movement');
                $move = $GLOBALS['wpdb']->moves[0];
                mgws_contract_assert(($move['type'] ?? '') === 'in' && ($move['stock_effect'] ?? '') === 'load' && ($move['source_type'] ?? '') === 'quick_load', 'quick load movement must identify its load effect and source');
                mgws_contract_assert((int) ($move['qty'] ?? 0) === 3 && (int) ($move['user_id'] ?? 0) === 7 && str_contains((string) ($move['note'] ?? ''), 'Manual shelf replenishment'), 'quick load movement must retain delta, operator, and reason audit data');
                mgws_contract_assert($GLOBALS['mgws_test_products'][101]->manageStock && $GLOBALS['mgws_test_products'][101]->stockQuantity === 10 && $GLOBALS['mgws_test_products'][101]->stockStatus === 'instock', 'quick load must project the authoritative total to Woo');
            },
        ),
        array(
            'name' => 'inventory_quick_load_idempotency',
            'description' => 'quick-load retries replay one movement and changed payloads conflict without further stock mutation',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                MGWS_DB::create_or_update_tables();
                $request = static function (int $quantity): WP_REST_Request {
                    return new WP_REST_Request(array(), array(
                        'product_id' => 101,
                        'quantity_delta' => $quantity,
                        'idempotency_key' => 'quick-load-retry-0001',
                        'reason' => 'Retry-safe shelf replenishment',
                        'warehouse_id' => 10,
                        'room' => 'A',
                        'rack' => '1',
                        'shelf' => '1',
                    ));
                };

                $first = $api->route_inventory_quick_load($request(3));
                mgws_contract_assert(!is_wp_error($first), 'first keyed quick load must succeed: ' . (is_wp_error($first) ? $first->get_error_code() . ' ' . $first->get_error_message() : ''));
                $replay = $api->route_inventory_quick_load($request(3));
                mgws_contract_assert(!is_wp_error($replay) && ($replay['idempotency']['replayed'] ?? false) === true, 'duplicate keyed quick load must replay the stored result');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 10 && count($GLOBALS['wpdb']->moves) === 1, 'duplicate keyed quick load must not double-load stock or movements');

                $conflict = $api->route_inventory_quick_load($request(4));
                mgws_contract_assert_error($conflict, 'mgws_idempotency_conflict', 409);
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 10 && count($GLOBALS['wpdb']->moves) === 1, 'changed keyed quick load must not mutate stock or movements');
            },
        ),
        array(
            'name' => 'inventory_quick_load_optional_location',
            'description' => 'quick load falls back to the first valid warehouse while omitted room, rack, and shelf remain empty',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture(false);
                MGWS_DB::create_or_update_tables();
                $GLOBALS['mgws_test_post_meta'] = array(10 => array('mg_site_id' => 1));

                $response = $api->route_inventory_quick_load(new WP_REST_Request(array(), array(
                    'product_id' => 101,
                    'quantity_delta' => 2,
                    'idempotency_key' => 'quick-load-optional-location-0001',
                    'reason' => 'Carico senza ubicazione dettagliata',
                )));

                mgws_contract_assert(!is_wp_error($response), 'quick load must resolve the technical warehouse when optional location fields are omitted: ' . (is_wp_error($response) ? $response->get_error_code() . ' ' . $response->get_error_message() : ''));
                mgws_contract_assert((int) ($response['location']['warehouse_id'] ?? 0) === 10 && (int) ($response['location']['site_id'] ?? 0) === 1, 'quick load must select the first valid warehouse and its site');
                mgws_contract_assert(($response['location']['room'] ?? null) === '' && ($response['location']['rack'] ?? null) === '' && ($response['location']['shelf'] ?? null) === '', 'omitted optional location details must remain empty');
                mgws_contract_assert(count($GLOBALS['wpdb']->levels) === 1 && (int) $GLOBALS['wpdb']->levels[0]['qty'] === 2, 'quick load must create the aggregate warehouse level without fabricated location details');
            },
        ),
        array(
            'name' => 'inventory_quick_load_failure',
            'description' => 'quick load rejects unauthorized, malformed, unknown, invalid-variation, non-positive, and blank-reason requests without mutations',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                MGWS_DB::create_or_update_tables();
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_forbidden', 403);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true, 'mgws_stock_move' => true);
                $GLOBALS['mgws_test_products'][102] = new MGWS_Contract_Product(102, true, 101);
                $levels_before = serialize($GLOBALS['wpdb']->levels);
                $moves_before = count($GLOBALS['wpdb']->moves);
                $woo_before = $GLOBALS['mgws_test_products'][101]->stockQuantity;

                $invalid = array(
                    new WP_REST_Request(array(), array('quantity_delta' => 1, 'reason' => 'Missing product')),
                    new WP_REST_Request(array(), array('product_id' => 999, 'variation_id' => 102, 'quantity_delta' => 1, 'reason' => 'Invalid variation')),
                    new WP_REST_Request(array(), array('product_id' => 101, 'quantity_delta' => 0, 'reason' => 'Zero delta')),
                    new WP_REST_Request(array(), array('product_id' => 101, 'quantity_delta' => -1, 'reason' => 'Negative delta')),
                    new WP_REST_Request(array(), array('product_id' => 101, 'quantity_delta' => 1, 'reason' => '   ')),
                    new WP_REST_Request(array(), 'not-an-object'),
                );
                foreach ($invalid as $request) {
                    $result = $api->route_inventory_quick_load($request);
                    mgws_contract_assert_error($result, 'mgws_bad_request', 400);
                    mgws_contract_assert_no_sensitive_error_text($result);
                }
                $missing_product = $api->route_inventory_quick_load(new WP_REST_Request(array(), array('product_id' => 999999, 'quantity_delta' => 1, 'idempotency_key' => 'quick-load-missing-product', 'reason' => 'Unknown product')));
                mgws_contract_assert_error($missing_product, 'mgws_product_not_found', 404);
                mgws_contract_assert_no_sensitive_error_text($missing_product);
                mgws_contract_assert(serialize($GLOBALS['wpdb']->levels) === $levels_before && count($GLOBALS['wpdb']->moves) === $moves_before && $GLOBALS['mgws_test_products'][101]->stockQuantity === $woo_before, 'rejected quick-load input must not mutate stock, movements, or Woo projection');
            },
        ),
        array(
            'name' => 'inventory_counts_happy',
            'description' => 'draft physical counts resolve barcodes without stock writes, expose discrepancies, and post linked adjustments only on approval',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                MGWS_DB::create_or_update_tables();
                $GLOBALS['wpdb']->barcodes = array('COUNT-TAG-101' => 101);

                $created = $api->route_inventory_count_session_create(new WP_REST_Request(array(), array(
                    'site_id' => 1, 'warehouse_id' => 10, 'document_number' => 'COUNT-20260731-01', 'notes' => 'Annual count',
                )));
                mgws_contract_assert(!is_wp_error($created) && ($created['status'] ?? '') === 'draft', 'count-session creation must return a draft');
                $sessionId = (int) ($created['id'] ?? 0);
                mgws_contract_assert($sessionId > 0, 'count-session creation must return an id');

                $line = $api->route_inventory_count_session_line_create(new WP_REST_Request(array('session_id' => $sessionId), array(
                    'barcode' => 'COUNT-TAG-101', 'physical_qty' => 5, 'room' => 'A', 'rack' => '1', 'shelf' => '1', 'reason_code' => 'stock_count',
                )));
                mgws_contract_assert(!is_wp_error($line), 'barcode-assisted count line must be accepted');
                mgws_contract_assert((int) ($line['product_id'] ?? 0) === 101 && (int) ($line['book_qty'] ?? -1) === 7 && (int) ($line['physical_qty'] ?? -1) === 5 && (int) ($line['discrepancy_qty'] ?? 0) === -2, 'count line must preserve book, physical, and discrepancy quantities');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 7 && count($GLOBALS['wpdb']->moves) === 0, 'draft count lines must not mutate stock or movements');

                $detail = $api->route_inventory_count_session_detail(new WP_REST_Request(array('session_id' => $sessionId)));
                mgws_contract_assert(!is_wp_error($detail) && count($detail['lines'] ?? array()) === 1 && (int) ($detail['lines'][0]['discrepancy_qty'] ?? 0) === -2, 'count-session detail must expose discrepancy review');
                $patched = $api->route_inventory_count_session_patch(new WP_REST_Request(array('session_id' => $sessionId), array('notes' => 'Reviewed discrepancy')));
                mgws_contract_assert(!is_wp_error($patched) && ($patched['notes'] ?? '') === 'Reviewed discrepancy', 'draft count-session patch must update notes');

                $approved = $api->route_inventory_count_session_approve(new WP_REST_Request(array('session_id' => $sessionId), array()));
                mgws_contract_assert(!is_wp_error($approved) && ($approved['status'] ?? '') === 'posted', 'approval must post the count session');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 5 && count($GLOBALS['wpdb']->moves) === 1, 'approval must apply exactly the discrepancy as one stock adjustment');
                mgws_contract_assert(($GLOBALS['wpdb']->moves[0]['type'] ?? '') === 'adjust' && ($GLOBALS['wpdb']->moves[0]['source_type'] ?? '') === 'inventory_count' && (int) ($GLOBALS['wpdb']->moves[0]['source_id'] ?? 0) === $sessionId && (int) ($GLOBALS['wpdb']->moves[0]['source_line_id'] ?? 0) === (int) ($line['id'] ?? 0), 'approval movement must link session and line audit metadata');
                mgws_contract_assert((int) ($approved['lines'][0]['stock_move_id'] ?? 0) > 0, 'approved discrepancy line must link its adjustment movement');

                mgws_contract_assert_error($api->route_inventory_count_session_patch(new WP_REST_Request(array('session_id' => $sessionId), array('notes' => 'mutate posted'))), 'mgws_conflict', 409);
                mgws_contract_assert_error($api->route_inventory_count_session_line_create(new WP_REST_Request(array('session_id' => $sessionId), array('product_id' => 101, 'physical_qty' => 1))), 'mgws_conflict', 409);
                mgws_contract_assert_error($api->route_inventory_count_session_approve(new WP_REST_Request(array('session_id' => $sessionId), array())), 'mgws_conflict', 409);
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'repeat approval or posted-session mutation must not create duplicate movements');
            },
        ),
        array(
            'name' => 'inventory_counts_failure',
            'description' => 'count sessions reject unauthorized, malformed, unknown, and missing operations without stock writes',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                MGWS_DB::create_or_update_tables();
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_forbidden', 403);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true, 'mgws_stock_move' => true);

                mgws_contract_assert_error($api->route_inventory_count_session_create(new WP_REST_Request(array(), array('site_id' => 1, 'warehouse_id' => 10))), 'mgws_bad_request', 400);
                $created = $api->route_inventory_count_session_create(new WP_REST_Request(array(), array('site_id' => 1, 'warehouse_id' => 10, 'document_number' => 'COUNT-20260731-02')));
                $sessionId = (int) ($created['id'] ?? 0);
                mgws_contract_assert_error($api->route_inventory_count_session_line_create(new WP_REST_Request(array('session_id' => $sessionId), array('barcode' => 'UNKNOWN-TAG', 'physical_qty' => 1))), 'mgws_product_not_found', 404);
                mgws_contract_assert_error($api->route_inventory_count_session_line_create(new WP_REST_Request(array('session_id' => $sessionId), array('product_id' => 101, 'physical_qty' => -1))), 'mgws_bad_request', 400);
                mgws_contract_assert_error($api->route_inventory_count_session_approve(new WP_REST_Request(array('session_id' => 999999), array())), 'mgws_not_found', 404);
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 7 && count($GLOBALS['wpdb']->moves) === 0, 'invalid count payloads must not mutate stock or movements');
            },
        ),
        array(
            'name' => 'suppliers_purchase_orders',
            'description' => 'supplier CRUD, low-stock reorder intent, and draft purchase orders remain stock-neutral before receipt posting',
            'run' => static function () use ($api): void {
                $GLOBALS['mgws_test_logged_in'] = false;
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_inventory_restock_read(), 'mgws_not_logged_in', 401);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_not_logged_in', 401);
                $GLOBALS['mgws_test_logged_in'] = true;
                mgws_contract_assert_error($api->perm_inventory_restock_read(), 'mgws_forbidden', 403);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_forbidden', 403);

                mgws_contract_reset_restock_fixture();
                $levels_before = serialize($GLOBALS['wpdb']->levels);
                $moves_before = count($GLOBALS['wpdb']->moves);
                $nameless_supplier = $api->route_inventory_suppliers_create(new WP_REST_Request(array(), array('email' => 'orders@example.test')));
                mgws_contract_assert_error($nameless_supplier, 'mgws_bad_request', 400);
                mgws_contract_assert_no_sensitive_error_text($nameless_supplier);
                $invalid_supplier = $api->route_inventory_suppliers_create(new WP_REST_Request(array(), array('name' => 'Acme Italia', 'email' => 'not-an-email')));
                mgws_contract_assert_error($invalid_supplier, 'mgws_bad_request', 400);
                mgws_contract_assert_no_sensitive_error_text($invalid_supplier);

                $supplier = $api->route_inventory_suppliers_create(new WP_REST_Request(array(), array(
                    'name' => 'Acme Italia',
                    'email' => 'orders@example.test',
                    'phone' => '+39 123',
                    'notes' => 'Preferred for sample variants',
                )));
                mgws_contract_assert(!is_wp_error($supplier) && (int) ($supplier['id'] ?? 0) > 0 && ($supplier['active'] ?? null) === true, 'supplier creation must return an active typed supplier');
                $supplier_id = (int) $supplier['id'];
                $supplier_list = $api->route_inventory_suppliers_list(new WP_REST_Request());
                mgws_contract_assert(count($supplier_list) === 1 && (int) $supplier_list[0]['id'] === $supplier_id, 'supplier list must return the created supplier');
                $supplier_detail = $api->route_inventory_supplier_get(new WP_REST_Request(array('supplier_id' => $supplier_id)));
                mgws_contract_assert(!array_key_exists('supplier_code', $supplier_detail), 'supplier detail must not expose supplier_code');
                mgws_contract_assert(!array_key_exists('woo_customer_id', $supplier_detail), 'supplier detail must not expose a WooCommerce customer link');
                $email_less_supplier = $api->route_inventory_suppliers_create(new WP_REST_Request(array(), array('name' => 'Spedizioni Rapide')));
                mgws_contract_assert(!is_wp_error($email_less_supplier) && (string) ($email_less_supplier['email'] ?? '') === '', 'supplier creation must not require an email address');
                $email_less_deleted = $api->route_inventory_supplier_delete(new WP_REST_Request(array('supplier_id' => (int) $email_less_supplier['id'])));
                mgws_contract_assert(!is_wp_error($email_less_deleted) && ($email_less_deleted['deleted'] ?? true) === false, 'an unused supplier must be soft-inactivated instead of deleted');
                $supplier_updated = $api->route_inventory_supplier_update(new WP_REST_Request(array('supplier_id' => $supplier_id), array('name' => 'Acme S.r.l.')));
                mgws_contract_assert(($supplier_updated['name'] ?? '') === 'Acme S.r.l.', 'supplier PATCH must update the supplied field');

                $rule = $api->route_inventory_reorder_rules_create(new WP_REST_Request(array(), array(
                    'site_id' => 1,
                    'warehouse_id' => 10,
                    'product_id' => 101,
                    'variation_id' => 102,
                    'supplier_id' => $supplier_id,
                    'reorder_point' => 5,
                    'target_stock' => 12,
                    'reorder_quantity' => 8,
                    'lead_time_days' => 4,
                    'safety_days' => 1,
                )));
                mgws_contract_assert(!is_wp_error($rule) && (int) ($rule['variation_id'] ?? 0) === 102, 'reorder rules must retain the variant identity');
                $suggestions = $api->route_inventory_reorder_suggestions(new WP_REST_Request(array('site_id' => 1, 'warehouse_id' => 10)));
                mgws_contract_assert(count($suggestions) === 1, 'a low variant stock level must produce one reorder suggestion');
                mgws_contract_assert((int) ($suggestions[0]['current_stock'] ?? -1) === 2 && (int) ($suggestions[0]['suggested_qty'] ?? 0) === 8, 'reorder suggestion must report current stock and configured intent');
                mgws_contract_assert_error($api->route_inventory_supplier_delete(new WP_REST_Request(array('supplier_id' => $supplier_id))), 'mgws_supplier_in_use', 409);
                $inactivated = $api->route_inventory_supplier_update(new WP_REST_Request(array('supplier_id' => $supplier_id), array('active' => false)));
                mgws_contract_assert(($inactivated['active'] ?? true) === false, 'an in-use supplier must remain in history but allow explicit inactivation');
                $reactivated = $api->route_inventory_supplier_update(new WP_REST_Request(array('supplier_id' => $supplier_id), array('active' => true)));
                mgws_contract_assert(($reactivated['active'] ?? false) === true, 'supplier reactivation must be explicit');

                $purchase_order = $api->route_inventory_purchase_orders_create(new WP_REST_Request(array(), array(
                    'site_id' => 1,
                    'warehouse_id' => 10,
                    'supplier_id' => $supplier_id,
                    'document_number' => 'PO-2026-0001',
                    'expected_at_gmt' => '2026-08-05T00:00:00Z',
                    'notes' => 'Initial supplier order',
                )));
                mgws_contract_assert(!is_wp_error($purchase_order) && ($purchase_order['status'] ?? '') === 'draft', 'purchase-order creation must return a draft');
                $purchase_order_id = (int) $purchase_order['id'];
                $line = $api->route_inventory_purchase_order_line_upsert(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array(
                    'product_id' => 101,
                    'variation_id' => 102,
                    'ordered_qty' => 12,
                    'expected_at_gmt' => '2026-08-05T00:00:00Z',
                    'unit_cost' => '14.50',
                    'supplier_sku' => 'ACME-102-BLUE',
                    'barcode' => '8001234567890',
                )));
                mgws_contract_assert(!is_wp_error($line) && ($line['stock_effect'] ?? '') === 'incoming' && (int) ($line['received_qty'] ?? -1) === 0, 'draft PO lines must be incoming only before receipt');
                $updated_line = $api->route_inventory_purchase_order_line_upsert(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array(
                    'line_id' => (int) $line['id'],
                    'ordered_qty' => 10,
                    'unit_cost' => '15.00',
                )));
                mgws_contract_assert((int) ($updated_line['ordered_qty'] ?? 0) === 10 && (string) ($updated_line['unit_cost'] ?? '') === '15.0000', 'draft PO lines must update ordered quantity and unit cost');
                $ordered = $api->route_inventory_purchase_order_status(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array('status' => 'ordered')));
                mgws_contract_assert(($ordered['status'] ?? '') === 'ordered' && !empty($ordered['ordered_at_gmt']), 'purchase-order draft must transition to ordered');
                $cancelled_line = $api->route_inventory_purchase_order_line_upsert(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array('line_id' => (int) $line['id'], 'action' => 'cancel')));
                mgws_contract_assert((int) ($cancelled_line['cancelled_qty'] ?? 0) === 10, 'ordered PO lines must be cancellable without deleting their audit identity');
                $cancelled_order = $api->route_inventory_purchase_order_status(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array('status' => 'cancelled')));
                mgws_contract_assert(($cancelled_order['status'] ?? '') === 'cancelled', 'ordered purchase orders must transition to cancelled');
                mgws_contract_assert_error($api->route_inventory_purchase_order_line_upsert(new WP_REST_Request(array('purchase_order_id' => $purchase_order_id), array('product_id' => 101, 'ordered_qty' => 0))), 'mgws_purchase_order_not_editable', 409);
                mgws_contract_assert_error($api->route_inventory_supplier_delete(new WP_REST_Request(array('supplier_id' => $supplier_id))), 'mgws_supplier_in_use', 409);
                mgws_contract_assert(serialize($GLOBALS['wpdb']->levels) === $levels_before && count($GLOBALS['wpdb']->moves) === $moves_before, 'supplier, reorder, and purchase-order intent must not mutate stock or movement ledgers before receipt');
            },
        ),
        array(
            'name' => 'receiving_happy',
            'description' => 'receipt drafts remain stock-neutral while convalida posts one source-linked partial load and opens a backorder exactly once',
            'run' => static function () use ($api): void {
                mgws_contract_reset_restock_fixture();
                $fixture = mgws_contract_create_receiving_purchase_order($api, 'HAPPY-01');
                $receipt = $api->route_inventory_receipts_create(new WP_REST_Request(array(), array(
                    'site_id' => 1,
                    'purchase_order_id' => (int) $fixture['purchase_order']['id'],
                    'document_number' => 'GRN-2026-0001',
                    'idempotency_key' => 'receipt-happy-0001',
                    'notes' => 'Initial delivery',
                    'lines' => array(array(
                        'purchase_order_line_id' => (int) $fixture['line']['id'],
                        'expected_qty' => 10,
                        'received_qty' => 6,
                        'rejected_qty' => 1,
                        'backorder_qty' => 3,
                        'reason_code' => 'supplier_short',
                    )),
                )));
                mgws_contract_assert(!is_wp_error($receipt) && ($receipt['status'] ?? '') === 'draft', 'receipt creation must return a stock-neutral draft');
                $receiptId = (int) ($receipt['id'] ?? 0);
                mgws_contract_assert($receiptId > 0 && (int) $GLOBALS['wpdb']->levels[0]['qty'] === 2 && count($GLOBALS['wpdb']->moves) === 0, 'draft receipt must not mutate stock or movements');
                mgws_contract_assert(count($receipt['lines'] ?? array()) === 1 && (int) ($receipt['lines'][0]['backorder_qty'] ?? 0) === 3, 'receipt draft must retain expected, received, rejected, and backorder quantities');

                $listed = $api->route_inventory_receipts_list(new WP_REST_Request(array('site_id' => 1)));
                $detail = $api->route_inventory_receipt_get(new WP_REST_Request(array('receipt_id' => $receiptId)));
                $patched = $api->route_inventory_receipt_patch(new WP_REST_Request(array('receipt_id' => $receiptId), array('notes' => 'QC reviewed')));
                mgws_contract_assert(count($listed) === 1 && ($detail['document_number'] ?? '') === 'GRN-2026-0001' && ($patched['notes'] ?? '') === 'QC reviewed', 'receipt list, detail, and draft patch must expose the stored document without stock mutation');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 2 && count($GLOBALS['wpdb']->moves) === 0, 'receipt patch must remain stock-neutral');

                $posted = $api->route_inventory_receipt_convalida(new WP_REST_Request(array('receipt_id' => $receiptId), array()));
                mgws_contract_assert(!is_wp_error($posted) && ($posted['status'] ?? '') === 'posted', 'convalida must post the receipt');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 8 && count($GLOBALS['wpdb']->moves) === 1, 'posted receipt must load only accepted quantity exactly once');
                $move = $GLOBALS['wpdb']->moves[0];
                mgws_contract_assert(($move['type'] ?? '') === 'in' && ($move['stock_effect'] ?? '') === 'load' && ($move['source_type'] ?? '') === 'receipt' && (int) ($move['source_id'] ?? 0) === $receiptId && (int) ($move['source_line_id'] ?? 0) === (int) $receipt['lines'][0]['id'], 'receipt movement must retain receipt and receipt-line audit links');
                mgws_contract_assert($GLOBALS['mgws_test_products'][102]->stockQuantity === 8, 'posted receipt must project the committed stock total to Woo');
                $backorders = $api->route_inventory_backorders_list(new WP_REST_Request(array('site_id' => 1)));
                mgws_contract_assert(count($backorders) === 1 && (int) ($backorders[0]['remaining_qty'] ?? 0) === 3 && ($backorders[0]['status'] ?? '') === 'open', 'partial receipt must create an open backorder for the remaining quantity');

                $replayedCreate = $api->route_inventory_receipts_create(new WP_REST_Request(array(), array(
                    'site_id' => 1,
                    'purchase_order_id' => (int) $fixture['purchase_order']['id'],
                    'document_number' => 'GRN-2026-0001',
                    'idempotency_key' => 'receipt-happy-0001',
                    'lines' => array(),
                )));
                $replayedPost = $api->route_inventory_receipt_convalida(new WP_REST_Request(array('receipt_id' => $receiptId), array()));
                mgws_contract_assert(!is_wp_error($replayedCreate) && (int) ($replayedCreate['id'] ?? 0) === $receiptId && !is_wp_error($replayedPost), 'idempotent receipt retries must return the existing document');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 8 && count($GLOBALS['wpdb']->moves) === 1, 'duplicate idempotency and convalida retries must not double-load stock');
            },
        ),
        array(
            'name' => 'receiving_failure',
            'description' => 'receipts reject absent capability, malformed payloads, and PO over-receipts without stock mutation',
            'run' => static function () use ($api): void {
                mgws_contract_reset_restock_fixture();
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true);
                $unauthorizedLevels = serialize($GLOBALS['wpdb']->levels);
                $unauthorizedMoves = count($GLOBALS['wpdb']->moves);
                mgws_contract_assert_error($api->perm_inventory_restock_mutate(), 'mgws_forbidden', 403);
                mgws_contract_assert(serialize($GLOBALS['wpdb']->levels) === $unauthorizedLevels && count($GLOBALS['wpdb']->moves) === $unauthorizedMoves, 'denied receipt capability must not mutate stock or movements');
                $GLOBALS['mgws_test_capabilities']['mgws_stock_move'] = true;
                $fixture = mgws_contract_create_receiving_purchase_order($api, 'FAIL-01');
                $levelsBefore = serialize($GLOBALS['wpdb']->levels);
                $movesBefore = count($GLOBALS['wpdb']->moves);
                $invalid = array(
                    new WP_REST_Request(array(), 'not-an-object'),
                    new WP_REST_Request(array(), array('site_id' => 1, 'purchase_order_id' => (int) $fixture['purchase_order']['id'], 'document_number' => 'GRN-BAD-01', 'lines' => array())),
                    new WP_REST_Request(array(), array('site_id' => 1, 'purchase_order_id' => (int) $fixture['purchase_order']['id'], 'document_number' => 'GRN-BAD-02', 'lines' => array(array('purchase_order_line_id' => (int) $fixture['line']['id'], 'expected_qty' => 11, 'received_qty' => 11, 'rejected_qty' => 0, 'backorder_qty' => 0)))),
                );
                foreach ($invalid as $request) {
                    $result = $api->route_inventory_receipts_create($request);
                    mgws_contract_assert_error($result, 'mgws_bad_request', 400);
                    mgws_contract_assert_no_sensitive_error_text($result);
                }
                mgws_contract_assert(serialize($GLOBALS['wpdb']->levels) === $levelsBefore && count($GLOBALS['wpdb']->moves) === $movesBefore, 'rejected receipt payloads and missing capability must not mutate stock or movements');
            },
        ),
        array(
            'name' => 'pos_idempotency_first_checkout',
            'description' => 'a keyed checkout reserves idempotency before order creation and persists its success result',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload();
                $payload['meta_data'][] = array('key' => '_id_scontrino_locale', 'value' => 'fallback-must-not-win');
                $response = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                $record = $GLOBALS['wpdb']->idempotency['mgws-test-checkout-0001'] ?? null;
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 1, 'first checkout must create exactly one Woo order');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'first checkout must create exactly one stock movement');
                mgws_contract_assert(is_array($record) && ($record['status'] ?? '') === 'succeeded', 'first checkout must persist succeeded idempotency state');
                mgws_contract_assert((int) $record['order_id'] === (int) $response['order_id'], 'idempotency order id must match checkout response');
                mgws_contract_assert(($response['idempotency']['key'] ?? '') === 'mgws-test-checkout-0001', 'root idempotency_key must win over local receipt metadata');
            },
        ),
        array(
            'name' => 'pos_idempotency_replay',
            'description' => 'same key and normalized payload replays the stored checkout result without new Woo or stock writes',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $first = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), mgws_contract_checkout_payload())));
                $replayPayload = mgws_contract_checkout_payload();
                $replayPayload['timestamp'] = '2026-07-18T10:01:00Z';
                $replayPayload['meta_data'][1]['value'] = '2026-07-18T10:01:00Z';
                $replay = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $replayPayload)));
                mgws_contract_assert((int) $replay['order_id'] === (int) $first['order_id'], 'replay must return the original order id');
                mgws_contract_assert(($replay['idempotency']['replayed'] ?? false) === true, 'replay response must identify itself as replayed');
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 1, 'replay must not create another Woo order');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'replay must not create another stock movement');
            },
        ),
        array(
            'name' => 'pos_idempotency_conflict',
            'description' => 'same key with changed product payload returns a conflict without Woo or stock mutation',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), mgws_contract_checkout_payload())));
                $conflictPayload = mgws_contract_checkout_payload();
                $conflictPayload['sale_items'][0]['quantity'] = 2;
                $conflictPayload['sale_items'][0]['subtotal'] = 20.0;
                $conflictPayload['totals']['totale'] = 20.0;
                $result = $api->route_pos_checkout(new WP_REST_Request(array(), $conflictPayload));
                mgws_contract_assert_error($result, 'mgws_idempotency_conflict', 409);
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 1, 'idempotency conflict must not create another Woo order');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'idempotency conflict must not create another stock movement');
            },
        ),
        array(
            'name' => 'pos_idempotency_failure_after_reservation',
            'description' => 'a post-reservation stock failure is persisted as failed and cannot replay as success',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload('mgws-test-failed-0001');
                $GLOBALS['wpdb']->reservation_hook = static function (): void {
                    $GLOBALS['wpdb']->levels[0]['qty'] = 0;
                };
                $result = $api->route_pos_checkout(new WP_REST_Request(array(), $payload));
                mgws_contract_assert_error($result, 'mgws_checkout_failed', 409);
                $record = $GLOBALS['wpdb']->idempotency['mgws-test-failed-0001'] ?? array();
                mgws_contract_assert(($record['status'] ?? '') === 'failed', 'post-reservation failure must persist failed state');
                mgws_contract_assert(($record['recovery_state'] ?? '') === 'order_deleted', 'failed checkout must record order cleanup state');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 0, 'failed checkout must not persist stock movements');
                $retry = $api->route_pos_checkout(new WP_REST_Request(array(), $payload));
                mgws_contract_assert_error($retry, 'mgws_checkout_failed', 409);
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 1, 'failed idempotency replay must not create another Woo order');
            },
        ),
        array(
            'name' => 'pos_idempotency_concurrent_duplicate',
            'description' => 'a duplicate arriving during atomic reservation receives deterministic in-progress state and cannot create duplicate writes',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload('mgws-test-race-0001');
                $duplicateResult = null;
                $GLOBALS['wpdb']->reservation_hook = static function () use ($api, $payload, &$duplicateResult): void {
                    $duplicateResult = $api->route_pos_checkout(new WP_REST_Request(array(), $payload));
                };
                $first = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                mgws_contract_assert_error($duplicateResult, 'mgws_idempotency_in_progress', 409);
                $replay = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                mgws_contract_assert((int) $replay['order_id'] === (int) $first['order_id'], 'post-race replay must return the first order id');
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 1, 'concurrent duplicate simulation must create one Woo order');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 1, 'concurrent duplicate simulation must create one stock movement');
            },
        ),
        array(
            'name' => 'pos_idempotency_invalid_pre_write',
            'description' => 'an invalid product is rejected before reservation, Woo order creation, or stock movement writes',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload('mgws-test-invalid-0001');
                $payload['sale_items'][0]['product_id'] = 999999;
                $result = $api->route_pos_checkout(new WP_REST_Request(array(), $payload));
                mgws_contract_assert_error($result, 'mgws_bad_request', 400);
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 0, 'invalid pre-write payload must not create a Woo order');
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === 0, 'invalid pre-write payload must not create stock movements');
                mgws_contract_assert($GLOBALS['wpdb']->idempotency === array(), 'invalid pre-write payload must not reserve idempotency state');
            },
        ),
        array(
            'name' => 'pos_idempotency_fallback_local_receipt_id',
            'description' => 'the local receipt metadata key is used when the root idempotency key is absent',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload();
                unset($payload['idempotency_key']);
                $payload['meta_data'][] = array('key' => '_id_scontrino_locale', 'value' => 'local-receipt-0001');
                $response = mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                mgws_contract_assert(($response['idempotency']['key'] ?? '') === 'local-receipt-0001', 'fallback local receipt id must become the idempotency key');
                mgws_contract_assert(isset($GLOBALS['wpdb']->idempotency['local-receipt-0001']), 'fallback local receipt id must be persisted');
            },
        ),
        array(
            'name' => 'pos_idempotency_movement_count_no_duplication',
            'description' => 'replaying a successful checkout keeps the stock movement count unchanged',
            'run' => static function () use ($api): void {
                mgws_contract_reset_pos_fixture();
                $payload = mgws_contract_checkout_payload('mgws-test-moves-0001');
                mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                $movementCount = count($GLOBALS['wpdb']->moves);
                mgws_contract_assert_checkout_success($api->route_pos_checkout(new WP_REST_Request(array(), $payload)));
                mgws_contract_assert(count($GLOBALS['wpdb']->moves) === $movementCount, 'replay must preserve movement count');
            },
        ),
        array(
            'name' => 'inventory_happy',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                $routes = mgws_contract_register_routes($api);
                $expected = array(
                    '/inventory/status' => array('GET', 'perm_stock_read'),
                    '/inventory/stock/product/(?P<product_id>\\d+)' => array('GET', 'perm_stock_read'),
                    '/inventory/stock/all' => array('GET', 'perm_stock_read'),
                    '/inventory/statistics' => array('GET', 'perm_stock_read'),
                    '/inventory/low-stock' => array('GET', 'perm_stock_read'),
                    '/inventory/stock/sync' => array('POST', 'perm_stock_move'),
                    '/inventory/stock/reconcile' => array('PUT', 'perm_stock_move'),
                    '/inventory/stock/move' => array('POST', 'perm_stock_move'),
                    '/inventory/rfid/scan' => array('POST', 'perm_stock_move'),
                );
                foreach ($expected as $route => $definition) {
                    mgws_contract_assert(mgws_contract_route_exists($routes, 'mgws/v1', $route, $definition[0]), 'missing inventory route ' . $route);
                    mgws_contract_assert(($routes['mgws/v1'][$route]['permission_callback'] ?? null) === array($api, $definition[1]), 'wrong inventory permission callback ' . $route);
                }
                $status = $api->route_inventory_status(new WP_REST_Request());
                mgws_contract_assert(($status['enabled'] ?? false) === true, 'inventory status must be enabled');
                $product = $api->route_inventory_product_stock(new WP_REST_Request(array('product_id' => 101, 'variation_id' => 0)));
                mgws_contract_assert((int) ($product['current_stock'] ?? 0) === 7, 'product stock must be 7');
                $all = $api->route_inventory_stock_all(new WP_REST_Request());
                mgws_contract_assert(count($all) === 1 && (int) $all[0]['product_id'] === 101, 'all stock must include product 101');
                $statistics = $api->route_inventory_statistics(new WP_REST_Request());
                mgws_contract_assert((int) ($statistics['total_products'] ?? 0) >= 1, 'statistics must count products');
                mgws_contract_assert($api->route_inventory_low_stock(new WP_REST_Request(array('threshold' => 6))) === array(), 'low stock must exclude qty 7 at threshold 6');
                mgws_contract_reset_inventory_fixture(false);
                mgws_contract_assert($api->route_inventory_stock_all(new WP_REST_Request()) === array(), 'empty stock table must return an empty list');
            },
        ),
        array(
            'name' => 'inventory_failure',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                mgws_contract_assert_error($api->route_inventory_product_stock(new WP_REST_Request(array('product_id' => 999999))), 'mgws_product_not_found', 404);
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_stock_move(), 'mgws_forbidden', 403);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_move' => true);
                mgws_contract_assert_error($api->route_inventory_stock_reconcile(new WP_REST_Request(array(), array('product_id' => 101, 'correct_stock' => -1))), 'mgws_bad_request', 400);
            },
        ),
        array(
            'name' => 'inventory_final_mutations',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                $sync = $api->route_inventory_stock_sync(new WP_REST_Request(array(), array('product_id' => 101, 'woo_stock' => 9, 'sync_type' => 'full')));
                mgws_contract_assert(!is_wp_error($sync), 'stock sync must succeed');
                mgws_contract_assert(($sync['operation'] ?? '') === 'stock_sync' && (int) ($sync['previous_stock'] ?? -1) === 7 && (int) ($sync['current_stock'] ?? -1) === 9 && (int) ($sync['delta'] ?? 0) === 2, 'stock sync must report the MGWS total delta');
                mgws_contract_assert((int) ($sync['movement_count'] ?? 0) === 1 && count($GLOBALS['wpdb']->moves) === 1, 'stock sync must create one audit movement');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 9, 'stock sync must update the authoritative MGWS level');
                mgws_contract_assert(str_contains((string) $GLOBALS['wpdb']->moves[0]['note'], 'Inventory Woo sync (full): total 7 -> 9 (delta 2)'), 'stock sync audit must identify its imported total');
                mgws_contract_assert($GLOBALS['mgws_test_products'][101]->stockQuantity === 9 && $GLOBALS['mgws_test_products'][101]->stockStatus === 'instock', 'stock sync must project the MGWS total back to Woo');

                $reconcile = $api->route_inventory_stock_reconcile(new WP_REST_Request(array(), array('product_id' => 101, 'correct_stock' => 3, 'reason' => 'Cycle count')));
                mgws_contract_assert(!is_wp_error($reconcile), 'stock reconcile must succeed');
                mgws_contract_assert(($reconcile['operation'] ?? '') === 'stock_reconcile' && (int) ($reconcile['previous_stock'] ?? -1) === 9 && (int) ($reconcile['current_stock'] ?? -1) === 3 && (int) ($reconcile['delta'] ?? 0) === -6, 'stock reconcile must report the correction delta');
                mgws_contract_assert((int) ($reconcile['movement_count'] ?? 0) === 1 && count($GLOBALS['wpdb']->moves) === 2, 'stock reconcile must append one audit movement');
                mgws_contract_assert((int) $GLOBALS['wpdb']->levels[0]['qty'] === 3, 'stock reconcile must update the authoritative MGWS level');
                mgws_contract_assert(str_contains((string) $GLOBALS['wpdb']->moves[1]['note'], 'Inventory reconcile: Cycle count: total 9 -> 3 (delta -6)'), 'stock reconcile audit must retain its reason');
                mgws_contract_assert($GLOBALS['mgws_test_products'][101]->stockQuantity === 3, 'stock reconcile must project the corrected total back to Woo');

                $GLOBALS['wpdb']->barcodes = array('TAG-101' => 101);
                $scan = $api->route_inventory_rfid_scan(new WP_REST_Request(array(), array('tags' => array('TAG-101', 'MISSING-TAG'))));
                mgws_contract_assert(!is_wp_error($scan), 'RFID scan must return a structured resolution result');
                mgws_contract_assert(($scan['mode'] ?? '') === 'resolve_only' && count($scan['resolved'] ?? array()) === 1 && count($scan['unresolved'] ?? array()) === 1, 'RFID scan must separate resolved and unresolved tags');
                mgws_contract_assert(($scan['resolved'][0]['tag'] ?? '') === 'TAG-101' && (int) ($scan['resolved'][0]['product_id'] ?? 0) === 101, 'RFID scan must resolve tags through the barcode lookup');
                mgws_contract_assert((int) ($scan['summary']['stock_updates'] ?? -1) === 0 && (int) ($scan['summary']['movement_count'] ?? -1) === 0 && count($GLOBALS['wpdb']->moves) === 2, 'RFID resolution must not infer a stock mutation');
            },
        ),
        array(
            'name' => 'loyalty_happy_routes_status_and_lookup',
            'description' => 'all Flutter loyalty routes are registered and expose the configured customer/card/email contract',
            'run' => static function () use ($api): void {
                mgws_contract_reset_loyalty_fixture();
                $routes = mgws_contract_register_routes($api);
                foreach (array(
                    array('/loyalty/status', 'GET', 'perm_loyalty_read'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)', 'GET', 'perm_loyalty_read'),
                    array('/loyalty/lookup/card/(?P<card_number>[^/]+)', 'GET', 'perm_loyalty_read'),
                    array('/loyalty/lookup/email/(?P<email>[^/]+)', 'GET', 'perm_loyalty_read'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)/card', 'PUT', 'perm_loyalty_mutate'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)/card', 'DELETE', 'perm_loyalty_mutate'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)/points/add', 'POST', 'perm_loyalty_mutate'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)/points/deduct', 'POST', 'perm_loyalty_mutate'),
                    array('/loyalty/customers/(?P<customer_id>\\d+)/history', 'GET', 'perm_loyalty_read'),
                    array('/loyalty/stats', 'GET', 'perm_loyalty_read'),
                ) as $route) {
                    mgws_contract_assert(mgws_contract_route_exists($routes, 'mgws/v1', $route[0], $route[1]), 'missing loyalty route ' . $route[1] . ' ' . $route[0]);
                    mgws_contract_assert(mgws_contract_route_permission_callback($routes, 'mgws/v1', $route[0], $route[1]) === array($api, $route[2]), 'loyalty route permission callback differs for ' . $route[1] . ' ' . $route[0]);
                }
                $status = $api->route_loyalty_status(new WP_REST_Request());
                mgws_contract_assert(($status['ok'] ?? false) === true && ($status['enabled'] ?? false) === true && ($status['configured'] ?? false) === true, 'loyalty status must report configured storage');
                $created = $api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 201), array('card_number' => 'CARD-201', 'tier' => 'silver')));
                mgws_contract_assert(!is_wp_error($created), 'loyalty card PUT must succeed');
                mgws_contract_assert_loyalty_customer_shape($created, 201, 'CARD-201', 0);
                mgws_contract_assert(($created['tier'] ?? '') === 'silver', 'loyalty card PUT must persist tier');
                $updated = $api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 201), array('card_number' => 'CARD-201B', 'tier' => 'gold')));
                mgws_contract_assert(!is_wp_error($updated), 'loyalty card PUT must update an existing card');
                mgws_contract_assert_loyalty_customer_shape($updated, 201, 'CARD-201B', 0);
                mgws_contract_assert(($updated['tier'] ?? '') === 'gold', 'loyalty card PUT must update tier');
                $by_card = $api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => 'CARD-201B')));
                mgws_contract_assert_loyalty_customer_shape($by_card, 201, 'CARD-201B', 0);
                $by_email = $api->route_loyalty_lookup_email(new WP_REST_Request(array('email' => 'ada@example.test')));
                mgws_contract_assert_loyalty_customer_shape($by_email, 201, 'CARD-201B', 0);
                $stats = $api->route_loyalty_stats(new WP_REST_Request());
                mgws_contract_assert((int) ($stats['customers'] ?? 0) === 1 && (int) ($stats['cards'] ?? 0) === 1 && (int) ($stats['points'] ?? -1) === 0, 'loyalty stats must report card-backed account totals');
            },
        ),
        array(
            'name' => 'loyalty_happy_points_history_and_card_delete',
            'description' => 'point add/deduct append a stable ledger and deleting a card preserves customer history',
            'run' => static function () use ($api): void {
                mgws_contract_reset_loyalty_fixture();
                $created = $api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 201), array('card_number' => 'CARD-201B')));
                mgws_contract_assert(!is_wp_error($created), 'loyalty card must be created before lifecycle test');
                $added = $api->route_loyalty_points_add(new WP_REST_Request(array('customer_id' => 201), array('points' => 10, 'reference' => 'SALE-1', 'note' => 'sale')));
                mgws_contract_assert(!is_wp_error($added) && (int) ($added['points'] ?? -1) === 10, 'adding 10 points must return balance 10');
                $deducted = $api->route_loyalty_points_deduct(new WP_REST_Request(array('customer_id' => 201), array('points' => 3, 'reference' => 'REDEEM-1', 'note' => 'redeem')));
                mgws_contract_assert(!is_wp_error($deducted) && (int) ($deducted['points'] ?? -1) === 7, 'deducting 3 points must return balance 7');
                $history = $api->route_loyalty_history(new WP_REST_Request(array('customer_id' => 201, 'page' => 1, 'per_page' => 20)));
                mgws_contract_assert(count($history) === 2, 'loyalty history must contain two movements');
                mgws_contract_assert(($history[0]['direction'] ?? '') === 'deduct' && (int) ($history[0]['balance_after'] ?? -1) === 7, 'history must be newest-first with the final balance');
                mgws_contract_assert(($history[1]['direction'] ?? '') === 'add' && (int) ($history[1]['balance_after'] ?? -1) === 10, 'history must retain the initial addition');
                $deleted = $api->route_loyalty_card_delete(new WP_REST_Request(array('customer_id' => 201)));
                mgws_contract_assert(!is_wp_error($deleted) && ($deleted['deleted'] ?? false) === true, 'loyalty card DELETE must report deletion');
                mgws_contract_assert_error($api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => 'CARD-201B'))), 'mgws_loyalty_card_not_found', 404);
                $history_after_delete = $api->route_loyalty_history(new WP_REST_Request(array('customer_id' => 201)));
                mgws_contract_assert(count($history_after_delete) === 2, 'deleting a card must not delete loyalty history');
            },
        ),
        array(
            'name' => 'loyalty_failure_missing_customer_and_card',
            'description' => 'missing customer and card requests return deterministic not-found errors',
            'run' => static function () use ($api): void {
                mgws_contract_reset_loyalty_fixture();
                mgws_contract_assert_error($api->route_loyalty_customer(new WP_REST_Request(array('customer_id' => 999999))), 'mgws_loyalty_customer_not_found', 404);
                mgws_contract_assert_error($api->route_loyalty_points_add(new WP_REST_Request(array('customer_id' => 999999), array('points' => 10))), 'mgws_loyalty_customer_not_found', 404);
                mgws_contract_assert_error($api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => 'MISSING-CARD'))), 'mgws_loyalty_card_not_found', 404);
                mgws_contract_assert_error($api->route_loyalty_card_delete(new WP_REST_Request(array('customer_id' => 201))), 'mgws_loyalty_card_not_found', 404);
            },
        ),
        array(
            'name' => 'loyalty_failure_mutation_validation_and_authorization',
            'description' => 'duplicate cards, negative point adds, and unauthorized mutations are rejected before state changes',
            'run' => static function () use ($api): void {
                mgws_contract_reset_loyalty_fixture();
                mgws_contract_assert(!is_wp_error($api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 201), array('card_number' => 'CARD-201')))), 'fixture card must be created');
                mgws_contract_assert_error($api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 202), array('card_number' => 'CARD-201'))), 'mgws_loyalty_card_conflict', 409);
                mgws_contract_assert_error($api->route_loyalty_points_add(new WP_REST_Request(array('customer_id' => 201), array('points' => -1))), 'mgws_bad_request', 400);
                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_read' => true);
                mgws_contract_assert_error($api->perm_loyalty_mutate(), 'mgws_forbidden', 403);
            },
        ),
        array(
            'name' => 'loyalty_failure_special_character_lookup_privacy',
            'description' => 'malformed raw lookup path values fail without echoing customer data or input',
            'run' => static function () use ($api): void {
                mgws_contract_reset_loyalty_fixture();
                $card_input = "CARD-201' OR 1=1";
                $card_error = $api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => $card_input)));
                mgws_contract_assert_error($card_error, 'mgws_bad_request', 400);
                $email_input = 'not-an-email/../../ada@example.test';
                $email_error = $api->route_loyalty_lookup_email(new WP_REST_Request(array('email' => $email_input)));
                mgws_contract_assert_error($email_error, 'mgws_bad_request', 400);
                foreach (array($card_error->get_error_message(), $email_error->get_error_message()) as $message) {
                    mgws_contract_assert(!str_contains($message, $card_input) && !str_contains($message, $email_input) && !str_contains($message, 'ada@example.test') && !str_contains($message, 'Ada'), 'lookup errors must not leak PII or raw input');
                }
            },
        ),
        array(
            'name' => 'security_happy',
            'description' => 'authorized callers receive scoped read and mutation access with explicit validation schemas and encoded loyalty lookups',
            'run' => static function () use ($api): void {
                mgws_contract_reset_inventory_fixture();
                $routes = mgws_contract_register_routes($api);
                $posArgs = $routes['mgws/v1']['/pos/checkout']['args'] ?? array();
                foreach (array('idempotency_key', 'sale_items', 'return_items', 'customer', 'totals', 'meta_data') as $argument) {
                    mgws_contract_assert(isset($posArgs[$argument]), 'POS route must declare ' . $argument . ' args');
                }
                mgws_contract_assert(isset($posArgs['idempotency_key']['sanitize_callback'], $posArgs['idempotency_key']['validate_callback']), 'POS idempotency key must be sanitized and validated');
                foreach (array('/inventory/stock/product/(?P<product_id>\\d+)', '/inventory/stock/all', '/inventory/statistics', '/inventory/low-stock', '/inventory/stock/sync', '/inventory/stock/reconcile', '/inventory/stock/move', '/inventory/rfid/scan') as $route) {
                    mgws_contract_assert(!empty($routes['mgws/v1'][$route]['args']), 'inventory route must declare validation args: ' . $route);
                }
                mgws_contract_assert($api->perm_pos_checkout() === true, 'stock-move operator must be authorized for checkout');
                mgws_contract_assert($api->perm_loyalty_read() === true, 'stock-read operator must be authorized for loyalty reads');
                mgws_contract_assert($api->perm_loyalty_mutate() === true, 'stock-move operator must be authorized for loyalty mutations');
                $stock = $api->route_inventory_stock_all(new WP_REST_Request());
                mgws_contract_assert(is_array($stock) && count($stock) === 1, 'authorized inventory read must succeed');

                mgws_contract_reset_loyalty_fixture();
                $GLOBALS['mgws_test_users'][203] = array(
                    'email' => 'ada+vip@example.test',
                    'meta' => array('first_name' => 'Ada', 'last_name' => 'VIP'),
                );
                $created = $api->route_loyalty_card_put(new WP_REST_Request(array('customer_id' => 203), array('card_number' => 'CARD:VIP-203')));
                mgws_contract_assert(!is_wp_error($created), 'authorized loyalty card mutation must succeed');
                $byCard = $api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => 'CARD%3AVIP-203')));
                mgws_contract_assert(!is_wp_error($byCard) && ($byCard['card_number'] ?? '') === 'CARD:VIP-203', 'encoded card path must decode once and resolve');
                $byEmail = $api->route_loyalty_lookup_email(new WP_REST_Request(array('email' => 'ada%2Bvip%40example.test')));
                mgws_contract_assert(!is_wp_error($byEmail) && ($byEmail['email'] ?? '') === 'ada+vip@example.test', 'encoded email path must decode once and resolve');
            },
        ),
        array(
            'name' => 'security_failure',
            'description' => 'authz, malformed payloads, SQL-like input, and checkout failures produce stable errors before writes without leaking secrets or PII',
            'run' => static function () use ($api): void {
                $GLOBALS['mgws_test_logged_in'] = false;
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_pos_checkout(), 'mgws_not_logged_in', 401);
                mgws_contract_assert_error($api->perm_loyalty_mutate(), 'mgws_not_logged_in', 401);

                $GLOBALS['mgws_test_logged_in'] = true;
                mgws_contract_assert_error($api->perm_pos_checkout(), 'mgws_forbidden', 403);
                mgws_contract_assert_error($api->perm_loyalty_mutate(), 'mgws_forbidden', 403);

                mgws_contract_reset_pos_fixture();
                $invalidProduct = mgws_contract_checkout_payload('mgws-security-invalid-product');
                $invalidProduct['sale_items'][0]['product_id'] = '101 OR 1=1';
                $invalidProductError = $api->route_pos_checkout(new WP_REST_Request(array(), $invalidProduct));
                mgws_contract_assert_error($invalidProductError, 'mgws_bad_request', 400);
                mgws_contract_assert_no_sensitive_error_text($invalidProductError);
                mgws_contract_assert($GLOBALS['mgws_test_order_creations'] === 0 && count($GLOBALS['wpdb']->moves) === 0 && $GLOBALS['wpdb']->idempotency === array(), 'invalid product id must fail before Woo, stock, or idempotency writes');

                $sqlLikePayload = mgws_contract_checkout_payload("key' OR 1=1");
                $sqlLikeError = $api->route_pos_checkout(new WP_REST_Request(array(), $sqlLikePayload));
                mgws_contract_assert_error($sqlLikeError, 'mgws_bad_request', 400);
                mgws_contract_assert_no_sensitive_error_text($sqlLikeError);

                mgws_contract_reset_loyalty_fixture();
                $rawCard = "CARD-201' OR 1=1";
                $invalidCardError = $api->route_loyalty_lookup_card(new WP_REST_Request(array('card_number' => $rawCard)));
                mgws_contract_assert_error($invalidCardError, 'mgws_bad_request', 400);
                mgws_contract_assert_no_sensitive_error_text($invalidCardError);
                mgws_contract_assert(!str_contains($invalidCardError->get_error_message(), $rawCard), 'invalid card error must not echo the malformed value');

                mgws_contract_reset_pos_fixture();
                $GLOBALS['wpdb']->reservation_hook = static function (): void {
                    $GLOBALS['wpdb']->levels[0]['qty'] = 0;
                };
                $checkoutFailure = $api->route_pos_checkout(new WP_REST_Request(array(), mgws_contract_checkout_payload('mgws-security-runtime-failure')));
                mgws_contract_assert_error($checkoutFailure, 'mgws_checkout_failed', 409);
                mgws_contract_assert_no_sensitive_error_text($checkoutFailure);
                mgws_contract_assert($checkoutFailure->get_error_message() === 'Checkout could not be completed', 'checkout failures must use a stable public message');
            },
        ),
        array(
            'name' => 'permissions',
            'description' => 'actual POS permission callback distinguishes unauthenticated, forbidden, and allowed callers',
            'run' => static function () use ($api): void {
                $GLOBALS['mgws_test_logged_in'] = false;
                $GLOBALS['mgws_test_capabilities'] = array();
                mgws_contract_assert_error($api->perm_pos_checkout(), 'mgws_not_logged_in', 401);

                $GLOBALS['mgws_test_logged_in'] = true;
                mgws_contract_assert_error($api->perm_pos_checkout(), 'mgws_forbidden', 403);

                $GLOBALS['mgws_test_capabilities'] = array('mgws_stock_move' => true);
                mgws_contract_assert($api->perm_pos_checkout() === true, 'mgws_stock_move must permit POS checkout');
            },
        ),
        array(
            'name' => 'woocommerce_stock_authority',
            'description' => 'WooCommerce stock reduction is left alone unless the store explicitly makes MGWS its single stock authority',
            'run' => static function (): void {
                $option = MGWS_Plugin::OPTION_WOOCOMMERCE_STOCK_AUTHORITY;
                unset($GLOBALS['mgws_test_options'][$option]);

                mgws_contract_assert(
                    MGWS_Plugin::filter_can_reduce_order_stock(true, null) === true,
                    'a fresh install must leave WooCommerce stock reduction enabled: suppressing it site-wide breaks every other stock plugin'
                );
                mgws_contract_assert(
                    MGWS_Plugin::filter_can_reduce_order_stock(false, null) === false,
                    'the filter must not force reduction on when something upstream already refused it'
                );

                $GLOBALS['mgws_test_options'][$option] = '1';
                mgws_contract_assert(
                    MGWS_Plugin::filter_can_reduce_order_stock(true, null) === false,
                    'opting in must suppress WooCommerce stock reduction so stock is not decremented twice'
                );

                $GLOBALS['mgws_test_options'][$option] = '0';
                mgws_contract_assert(
                    MGWS_Plugin::filter_can_reduce_order_stock(true, null) === true,
                    'opting back out must restore WooCommerce stock reduction'
                );
                unset($GLOBALS['mgws_test_options'][$option]);

                $source = (string) file_get_contents(dirname(__DIR__) . '/includes/class-mgws-plugin.php');
                mgws_contract_assert(
                    !str_contains($source, "'woocommerce_can_reduce_order_stock', '__return_false'"),
                    'the site-wide __return_false on WooCommerce stock reduction must not come back'
                );
            },
        ),
        array(
            'name' => 'invalid_payloads',
            'description' => 'actual POS checkout rejects malformed items before WooCommerce is invoked',
            'run' => static function () use ($api): void {
                $request = new WP_REST_Request(array(), array(
                    'sale_items' => array(array('product_id' => 0, 'quantity' => 1)),
                    'return_items' => array(),
                ));
                mgws_contract_assert_error($api->route_pos_checkout($request), 'mgws_bad_request', 400);
            },
        ),
    );
}

try {
    $options = mgws_contract_parse_arguments($argv);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, 'usage: php tests/mgws_contract_test.php [--list] [--filter <name>] [--self-check-failure] [--fail-on-pending]' . PHP_EOL);
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
}

if ($options['list']) {
    $listedApi = mgws_contract_boot_plugin();
    foreach (mgws_contract_tests($listedApi) as $test) {
        $status = isset($test['pending']) ? 'PENDING until todo ' . $test['pending'] : 'READY';
        fwrite(STDOUT, $test['name'] . ' - ' . $status . PHP_EOL);
    }
    exit(0);
}

$api = mgws_contract_boot_plugin();

if ($options['self_check_failure']) {
    $routes = mgws_contract_register_routes($api);
    $missingRoute = '/self-check/missing-route';
    if (!mgws_contract_route_exists($routes, 'mgws/v1', $missingRoute, 'POST')) {
        fwrite(STDERR, 'intentional missing-route assertion: missing POST /mgws/v1' . $missingRoute . PHP_EOL);
        fwrite(STDERR, 'SUMMARY failures=1 pending=0' . PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, 'self-check failure did not detect the intentional missing route' . PHP_EOL);
    exit(1);
}

$selectedTests = array();
foreach (mgws_contract_tests($api) as $test) {
    if ($options['filter'] === null || str_contains($test['name'], (string) $options['filter'])) {
        $selectedTests[] = $test;
    }
}

if ($selectedTests === array()) {
    fwrite(STDERR, 'no tests match filter: ' . $options['filter'] . PHP_EOL);
    exit(2);
}

$failures = 0;
$pending = 0;
foreach ($selectedTests as $test) {
    if (isset($test['pending'])) {
        $pending++;
        fwrite(STDOUT, '[PENDING] ' . $test['name'] . ': PENDING until todo ' . $test['pending'] . PHP_EOL);
        continue;
    }

    try {
        $test['run']();
        fwrite(STDOUT, '[PASS] ' . $test['name'] . PHP_EOL);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $test['name'] . ': ' . $error->getMessage() . PHP_EOL);
    }
}

fwrite(STDOUT, 'SUMMARY failures=' . $failures . ' pending=' . $pending . PHP_EOL);
if ($failures > 0 || ($options['fail_on_pending'] && $pending > 0)) {
    if ($options['fail_on_pending'] && $pending > 0) {
        fwrite(STDERR, 'pending tests are blocking because --fail-on-pending was supplied' . PHP_EOL);
    }
    exit(1);
}
