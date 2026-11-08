<?php

/**
 * Minimal WordPress/WooCommerce stand-in for the S3 unit tests.
 *
 * The environment has no composer, no phpunit, no mysqli and no sqlite, so the
 * tests cannot boot WordPress or reach a database. That is not a limitation
 * here: the code under test was split on purpose so that the decisions
 * (`MGWS_Stock_Plan`) need nothing, and the two WordPress-facing pieces only
 * need the handful of functions below. Each stub reads its data from
 * `$GLOBALS['mgws_test_*']`, so a test sets the world up before calling and
 * resets it after.
 *
 * This file is loaded once by `tests/run.php`.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

$GLOBALS['mgws_test'] = array(
    'passed' => 0,
    'failed' => 0,
    'failures' => array(),
    'current' => '',
);

$GLOBALS['mgws_test_products'] = array();
$GLOBALS['mgws_test_meta'] = array();
$GLOBALS['mgws_test_posts'] = array();
$GLOBALS['mgws_test_post_lists'] = array();
$GLOBALS['mgws_test_warehouse_sites'] = array();
$GLOBALS['mgws_test_stock_calls'] = array();

/**
 * Runs one named test and records the outcome.
 */
function mgws_test(string $name, callable $test): void {
    $GLOBALS['mgws_test']['current'] = $name;
    try {
        $test();
        $GLOBALS['mgws_test']['passed']++;
        fwrite(STDOUT, "  ok    {$name}\n");
    } catch (\Throwable $error) {
        $GLOBALS['mgws_test']['failed']++;
        $GLOBALS['mgws_test']['failures'][] = array('name' => $name, 'message' => $error->getMessage());
        fwrite(STDOUT, "  FAIL  {$name}\n        {$error->getMessage()}\n");
    }
    $GLOBALS['mgws_test']['current'] = '';
}

function mgws_assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message === '' ? '' : $message . ': ',
            mgws_export($expected),
            mgws_export($actual)
        ));
    }
}

function mgws_assert_true($condition, string $message = ''): void {
    if ($condition !== true) {
        throw new \RuntimeException(($message === '' ? 'expected true' : $message));
    }
}

function mgws_assert_false($condition, string $message = ''): void {
    if ($condition !== false) {
        throw new \RuntimeException(($message === '' ? 'expected false' : $message));
    }
}

function mgws_assert_null($value, string $message = ''): void {
    if ($value !== null) {
        throw new \RuntimeException(sprintf('%sexpected null, got %s', $message === '' ? '' : $message . ': ', mgws_export($value)));
    }
}

function mgws_export($value): string {
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }
    return (string) $value;
}

/**
 * Resets the fake world between tests. The reconciler caches which warehouses
 * are system ones in a static, and that cache has to go with the rest.
 */
function mgws_test_reset_world(): void {
    $GLOBALS['mgws_test_products'] = array();
    $GLOBALS['mgws_test_meta'] = array();
    $GLOBALS['mgws_test_posts'] = array();
    $GLOBALS['mgws_test_post_lists'] = array();
    $GLOBALS['mgws_test_warehouse_sites'] = array();
    $GLOBALS['mgws_test_stock_calls'] = array();
    $GLOBALS['mgws_test_throw_on_update'] = false;

    $property = new \ReflectionProperty(MGWS_Stock_Reconciler::class, 'system_cache');
    // Needed on PHP 8.0 only; from 8.1 it is a no-op and 8.5 deprecates it.
    if (PHP_VERSION_ID < 80100) {
        $property->setAccessible(true);
    }
    $property->setValue(null, array());
}

/* -------------------------------------------------------------------------
 * WordPress
 * ---------------------------------------------------------------------- */

if (!class_exists('WP_Error')) {
    class WP_Error {
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
}

function is_wp_error($thing): bool {
    return $thing instanceof WP_Error;
}

function __(string $text, string $domain = ''): string {
    return $text;
}

function get_post_meta($post_id, string $key = '', bool $single = false) {
    $post_id = (int) $post_id;
    $meta = $GLOBALS['mgws_test_meta'][$post_id] ?? array();
    if ($key === '') {
        return $single ? '' : $meta;
    }
    if (!array_key_exists($key, $meta)) {
        // Bridge for the reconciler tests: warehouse site id stored in the global.
        if ($key === 'mg_site_id' && isset($GLOBALS['mgws_test_warehouse_sites'][$post_id])) {
            return $single ? $GLOBALS['mgws_test_warehouse_sites'][$post_id] : array($GLOBALS['mgws_test_warehouse_sites'][$post_id]);
        }
        return $single ? '' : array();
    }
    return $single ? $meta[$key] : array($meta[$key]);
}

function get_post($post_id) {
    $post_id = (int) $post_id;
    return $GLOBALS['mgws_test_posts'][$post_id] ?? null;
}

function get_posts(array $args = array()): array {
    $type = (string) ($args['post_type'] ?? '');
    $list = $GLOBALS['mgws_test_post_lists'][$type] ?? array();
    return is_array($list) ? $list : array();
}

/* -------------------------------------------------------------------------
 * WooCommerce
 * ---------------------------------------------------------------------- */

class MGWS_Test_Product {
    public int $id;
    public bool $manages;
    public ?int $quantity;
    public int $managed_by;

    public function __construct(int $id, bool $manages = true, ?int $quantity = 0, ?int $managed_by = null) {
        $this->id = $id;
        $this->manages = $manages;
        $this->quantity = $quantity;
        $this->managed_by = $managed_by ?? $id;
    }

    public function managing_stock(): bool {
        return $this->manages;
    }

    public function get_stock_quantity(): ?int {
        return $this->quantity;
    }

    public function get_stock_managed_by_id(): int {
        return $this->managed_by;
    }

    public function is_type(string $type): bool {
        return false;
    }
}

function wc_get_product($product_id) {
    return $GLOBALS['mgws_test_products'][(int) $product_id] ?? false;
}

function wc_update_product_stock($product, $amount = null, string $operation = 'set', bool $updating = true) {
    $GLOBALS['mgws_test_stock_calls'][] = array(
        'product_id' => $product->id,
        'amount' => $amount,
        'operation' => $operation,
        'updating' => $updating,
        'applying' => MGWS_Woo_Stock::is_applying(),
    );
    if (!empty($GLOBALS['mgws_test_throw_on_update'])) {
        throw new \RuntimeException('WooCommerce failed on purpose');
    }
    if ($product->quantity === null) {
        return false;
    }
    $amount = (int) $amount;
    if ($operation === 'increase') {
        $product->quantity += $amount;
    } elseif ($operation === 'decrease') {
        $product->quantity = max(0, $product->quantity - $amount);
    } else {
        $product->quantity = $amount;
    }
    return $product->quantity;
}

/* -------------------------------------------------------------------------
 * MGWS_DB: load the real implementation (it only needs $wpdb + ABSPATH).
 * ---------------------------------------------------------------------- */

require_once dirname(__DIR__) . '/includes/mgws-db.php';

/* -------------------------------------------------------------------------
 * Classes under test
 * ---------------------------------------------------------------------- */

require_once dirname(__DIR__) . '/includes/class-mgws-stock-plan.php';
require_once dirname(__DIR__) . '/includes/class-mgws-woo-stock.php';
require_once dirname(__DIR__) . '/includes/class-mgws-stock-reconciler.php';

/* -------------------------------------------------------------------------
 * $wpdb stand-in: minimal, in-memory, supports the prepare/get_results/get_row/
 * insert/update/delete patterns used by the classes under test.
 * ---------------------------------------------------------------------- */

if (!class_exists('wpdb')) {
    class wpdb {
        public $prefix = 'wp_';
        public $insert_id = 0;
        public array $tables = array();

        public function __construct() {
            // approval rules table seedable via $GLOBALS['mgws_test_approval_rules']
        }

        public function prepare($query, ...$args) {
            // Resolve %i (identifier), %s (string), %d (int), %f (float).
            // First, handle array-style args passed as a single array.
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            // Replace %i with backtick-quoted identifier.
            $parts = preg_split('/(%[idsf])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
            $out = '';
            $ai = 0;
            foreach ($parts as $part) {
                if (preg_match('/^%[idsf]$/', $part)) {
                    if (!array_key_exists($ai, $args)) {
                        throw new \RuntimeException('prepare: missing arg for ' . $part);
                    }
                    $val = $args[$ai++];
                    switch ($part) {
                        case '%i':
                            $out .= '`' . str_replace('`', '', (string) $val) . '`';
                            break;
                        case '%s':
                            $out .= "'" . addslashes((string) $val) . "'";
                            break;
                        case '%d':
                            $out .= (int) $val;
                            break;
                        case '%f':
                            $out .= (float) $val;
                            break;
                    }
                } else {
                    $out .= $part;
                }
            }
            return $out;
        }

        private function table_from_query($query) {
            if (preg_match('/FROM\s+`?([^`\s]+)`?/i', $query, $m)) {
                return $m[1];
            }
            return '';
        }

        private function where_pairs($query) {
            $pairs = array();
            if (preg_match('/WHERE\s+(.*?)(?:\s+ORDER BY|\s+LIMIT|\s+GROUP BY|$)/is', $query, $m)) {
                foreach (preg_split('/\s+AND\s+/i', $m[1]) as $cond) {
                    $cond = trim($cond);
                    if ($cond === '' || $cond === '1=1') continue;
                    if (preg_match("/^(\w+)\s*=\s*'((?:[^']*))'$/", $cond, $mm)) {
                        $pairs[$mm[1]] = $mm[2];
                    } elseif (preg_match("/^(\w+)\s*=\s*(\d+)$/", $cond, $mm)) {
                        $pairs[$mm[1]] = (int) $mm[2];
                    }
                }
            }
            return $pairs;
        }

        private function row_matches($row, $pairs) {
            foreach ($pairs as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] != $v) return false;
            }
            return true;
        }

        public function get_results($query, $output = OBJECT) {
            $table = $this->table_from_query($query);
            $storage_key = $table;
            $rows = $GLOBALS['mgws_test_tables'][$storage_key] ?? array();
            // Back-compat: approval_rules stored under its own global.
            if (empty($rows) && strpos($table, 'approval_rules') !== false) {
                $rows = $GLOBALS['mgws_test_approval_rules'] ?? array();
            }
            if (empty($rows) && strpos($table, 'return_lines') !== false) {
                $rows = $GLOBALS['mgws_test_return_lines'] ?? array();
            }
            if (empty($rows) && strpos($table, 'mg_returns') !== false && strpos($table, 'return_lines') === false) {
                $rows = $GLOBALS['mgws_test_returns'] ?? array();
            }
            $pairs = $this->where_pairs($query);
            $filtered = array();
            foreach ($rows as $row) {
                if ($this->row_matches($row, $pairs)) $filtered[] = $row;
            }
            if (preg_match('/ORDER BY\s+(\w+)(?:\s+(ASC|DESC))?/i', $query, $m)) {
                $col = $m[1];
                $dir = isset($m[2]) && strtoupper($m[2]) === 'DESC' ? -1 : 1;
                usort($filtered, function ($a, $b) use ($col, $dir) {
                    $va = $a[$col] ?? null; $vb = $b[$col] ?? null;
                    if ($va == $vb) return 0;
                    return ($va < $vb ? -1 : 1) * $dir;
                });
            }
            if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $query, $m)) {
                $filtered = array_slice($filtered, (int) $m[2], (int) $m[1]);
            } elseif (preg_match('/LIMIT\s+(\d+)/i', $query, $m)) {
                $filtered = array_slice($filtered, 0, (int) $m[1]);
            }
            return array_values($filtered);
        }

        public function get_row($query, $output = OBJECT) {
            $rows = $this->get_results($query, $output);
            return $rows ? $rows[0] : null;
        }
        public function get_var($query) {
            $rows = $this->get_results($query);
            if (empty($rows)) return null;
            $row = $rows[0];
            return array_values($row)[0] ?? null;
        }

        public function insert($table, $data, $format = null) {
            $GLOBALS['mgws_test_tables'][$table] = $GLOBALS['mgws_test_tables'][$table] ?? array();
            // back-compat keys
            if (strpos($table, 'approval_rules') !== false && empty($GLOBALS['mgws_test_tables'][$table])) {
                $GLOBALS['mgws_test_tables'][$table] = $GLOBALS['mgws_test_approval_rules'] ?? array();
            }
            if (strpos($table, 'mg_returns') !== false && strpos($table, 'return_lines') === false && empty($GLOBALS['mgws_test_tables'][$table])) {
                $GLOBALS['mgws_test_tables'][$table] = $GLOBALS['mgws_test_returns'] ?? array();
            }
            if (strpos($table, 'return_lines') !== false && empty($GLOBALS['mgws_test_tables'][$table])) {
                $GLOBALS['mgws_test_tables'][$table] = $GLOBALS['mgws_test_return_lines'] ?? array();
            }
            $id = count($GLOBALS['mgws_test_tables'][$table]) + 1;
            if (!isset($data['id'])) $data['id'] = $id;
            $GLOBALS['mgws_test_tables'][$table][] = $data;
            // mirror to back-compat globals
            if (strpos($table, 'approval_rules') !== false) $GLOBALS['mgws_test_approval_rules'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'mg_returns') !== false && strpos($table, 'return_lines') === false) $GLOBALS['mgws_test_returns'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'return_lines') !== false) $GLOBALS['mgws_test_return_lines'] = $GLOBALS['mgws_test_tables'][$table];
            $this->insert_id = (int) $data['id'];
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null) {
            $rows = $GLOBALS['mgws_test_tables'][$table] ?? array();
            // back-compat
            if (empty($rows)) {
                if (strpos($table, 'approval_rules') !== false) $rows = $GLOBALS['mgws_test_approval_rules'] ?? array();
                elseif (strpos($table, 'return_lines') !== false) $rows = $GLOBALS['mgws_test_return_lines'] ?? array();
                elseif (strpos($table, 'mg_returns') !== false) $rows = $GLOBALS['mgws_test_returns'] ?? array();
            }
            foreach ($rows as $i => $row) {
                if ($this->row_matches($row, $where)) {
                    $rows[$i] = array_merge($row, $data);
                }
            }
            $GLOBALS['mgws_test_tables'][$table] = array_values($rows);
            if (strpos($table, 'approval_rules') !== false) $GLOBALS['mgws_test_approval_rules'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'mg_returns') !== false && strpos($table, 'return_lines') === false) $GLOBALS['mgws_test_returns'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'return_lines') !== false) $GLOBALS['mgws_test_return_lines'] = $GLOBALS['mgws_test_tables'][$table];
            return 1;
        }

        public function delete($table, $where, $where_format = null) {
            $rows = $GLOBALS['mgws_test_tables'][$table] ?? array();
            if (empty($rows)) {
                if (strpos($table, 'approval_rules') !== false) $rows = $GLOBALS['mgws_test_approval_rules'] ?? array();
                elseif (strpos($table, 'return_lines') !== false) $rows = $GLOBALS['mgws_test_return_lines'] ?? array();
                elseif (strpos($table, 'mg_returns') !== false) $rows = $GLOBALS['mgws_test_returns'] ?? array();
            }
            $out = array();
            foreach ($rows as $row) {
                if (!$this->row_matches($row, $where)) $out[] = $row;
            }
            $GLOBALS['mgws_test_tables'][$table] = array_values($out);
            if (strpos($table, 'approval_rules') !== false) $GLOBALS['mgws_test_approval_rules'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'mg_returns') !== false && strpos($table, 'return_lines') === false) $GLOBALS['mgws_test_returns'] = $GLOBALS['mgws_test_tables'][$table];
            if (strpos($table, 'return_lines') !== false) $GLOBALS['mgws_test_return_lines'] = $GLOBALS['mgws_test_tables'][$table];
            return 1;
        }

        /**
         * Minimal query handler: transaction control is a no-op (returns true),
         * other raw queries return 0. Tests needing specific query behavior
         * should extend this stub or assert against table state.
         */
        public function query($query) {
            $q = strtoupper(trim((string) $query));
            if (strpos($q, 'START TRANSACTION') === 0 || strpos($q, 'BEGIN') === 0 || strpos($q, 'COMMIT') === 0 || strpos($q, 'ROLLBACK') === 0) {
                return true;
            }
            return 0;
        }
    }
}

// MGWS_DB: only the approval_rules table name is needed.
if (!class_exists('MGWS_DB')) {
    // Fall back to a minimal stub if the real class isn't loadable.
}

// (mgws-db.php already loaded above)

// Instantiate the global $wpdb stub.
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new wpdb();
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key));
    }
}

if (!function_exists('gmdate')) {
    // gmdate is a PHP built-in; nothing to do.
}

// Load the class under test for approval-rules tests.
require_once dirname(__DIR__) . '/includes/class-mgws-approval-rules.php';

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['mgws_test_options'][$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $GLOBALS['mgws_test_options'][$option] = $value;
        return true;
    }
}
if (!function_exists('wc_get_price_decimals')) {
    function wc_get_price_decimals() {
        return $GLOBALS['mgws_test_price_decimals'] ?? 2;
    }
}

require_once dirname(__DIR__) . '/includes/class-mgws-cost-plan.php';
require_once dirname(__DIR__) . '/includes/class-mgws-cost.php';
require_once dirname(__DIR__) . '/includes/class-mgws-returns.php';

require_once dirname(__DIR__) . '/includes/class-mgws-stock-transfers.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim((string) $str);
    }
}

require_once dirname(__DIR__) . '/includes/class-mgws-stock-lots.php';

require_once dirname(__DIR__) . '/includes/class-mgws-price-lists.php';

// S14 classes.
require_once dirname(__DIR__) . '/includes/class-mgws-stock-reset.php';
require_once dirname(__DIR__) . '/includes/class-mgws-supplier-price-history.php';
require_once dirname(__DIR__) . '/includes/class-mgws-count-schedules.php';
require_once dirname(__DIR__) . '/includes/class-mgws-shipments.php';
require_once dirname(__DIR__) . '/includes/class-mgws-reservations.php';
require_once dirname(__DIR__) . '/includes/class-mgws-payments.php';
require_once dirname(__DIR__) . '/includes/class-mgws-kits.php';
require_once dirname(__DIR__) . '/includes/class-mgws-documents.php';
require_once dirname(__DIR__) . '/includes/class-mgws-product-barcodes.php';
