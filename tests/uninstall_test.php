<?php

declare(strict_types=1);

/**
 * Uninstall test bank for uninstall.php.
 *
 * uninstall.php cannot be included in-process: its first executable statement is
 * an `exit` guard for WP_UNINSTALL_PLUGIN, which would terminate the test runner.
 * Every case therefore runs in a separate PHP process and inspects the recording
 * that the probe writes from a shutdown handler, which survives `exit`.
 *
 * Expected values are hardcoded on purpose. Deriving them from MGWS_DB would make
 * the test agree with any table list uninstall.php happens to build, including a
 * list that forgot `mg_pos_shifts`.
 */

const MGWS_UNINSTALL_TABLES = array(
    'mg_stock_levels',
    'mg_stock_moves',
    'mg_stock_movements',
    'mg_pos_idempotency',
    'mg_pos_shifts',
    'mg_loyalty_cards',
    'mg_loyalty_movements',
    'mg_fornitori',
    'mg_reorder_rules',
    'mg_purchase_orders',
    'mg_purchase_order_lines',
    'mg_receipts',
    'mg_receipt_lines',
    'mg_backorders',
    'mg_inventory_count_sessions',
    'mg_inventory_count_lines',
    'mg_stock_reason_codes',
    'mg_employees',
);

const MGWS_UNINSTALL_OPTIONS = array(
    'mgws_caps_version',
    'mgws_db_schema_version',
    'mgws_pos_turno_obbligatorio',
    'mgws_shared_dashboards',
);

const MGWS_UNINSTALL_CAPS = array(
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

const MGWS_UNINSTALL_PROBE_TEMPLATE = <<<'PROBE'
<?php
{{DEFINE}}
require __DIR__ . '/wp-stub/uninstall-stubs.php';
$GLOBALS['mgws_uninstall_state_path'] = {{STATE}};
$GLOBALS['mgws_uninstall_fixture_path'] = {{FIXTURE}};
$mgws_probe_fixture = MGWS_Uninstall_State::fixture();
$GLOBALS['wpdb'] = new MGWS_Uninstall_WPDB();
$GLOBALS['mgws_uninstall_roles'] = new WP_Roles();
foreach (($mgws_probe_fixture['roles'] ?? array()) as $mgws_probe_name => $mgws_probe_caps) {
    $GLOBALS['mgws_uninstall_roles']->add_role((string) $mgws_probe_name, (array) $mgws_probe_caps);
}
$GLOBALS['mgws_uninstall_posts'] = $mgws_probe_fixture['posts'] ?? array();
$GLOBALS['mgws_uninstall_deleted_options'] = array();
$GLOBALS['mgws_uninstall_removed_caps'] = array();
$GLOBALS['mgws_uninstall_deleted_posts'] = array();
$GLOBALS['mgws_uninstall_deleted_user_meta'] = array();
$GLOBALS['mgws_uninstall_flushed'] = false;
register_shutdown_function(static function (): void { MGWS_Uninstall_State::write(); });
echo class_exists('WooCommerce') ? 'MGWS_PROBE_WOO_PRESENT' : 'MGWS_PROBE_WOO_ABSENT';
require dirname(__DIR__) . '/uninstall.php';
// uninstall.php only defines the callback: WordPress's uninstall_plugin() is
// what invokes it, after including the file. The probe models that.
if (!function_exists('mgws_uninstall_all')) {
    echo 'MGWS_PROBE_MISSING_CALLBACK';
} else {
    call_user_func('mgws_uninstall_all');
}
echo 'MGWS_PROBE_REACHED_END';
PROBE;

/**
 * Runs a probe in its own process and returns its exit code, output and recording.
 */
function mgws_uninstall_probe(bool $defineConstant, array $fixture = array()): array {
    $tmp = __DIR__ . '/_tmp_uninstall_probe_' . getmypid() . '_' . count($GLOBALS['mgws_uninstall_tmp_seq'] ?? array());
    $GLOBALS['mgws_uninstall_tmp_seq'][] = $tmp;
    $statePath = $tmp . '.json';
    $fixturePath = $tmp . '.fixture.json';
    $probePath = $tmp . '.php';

    file_put_contents($fixturePath, json_encode($fixture));
    $probe = str_replace(
        array('{{DEFINE}}', '{{STATE}}', '{{FIXTURE}}'),
        array(
            $defineConstant
                ? "define('WP_UNINSTALL_PLUGIN', 'mg-warehouse-stock/mg-warehouse-stock.php');"
                : '// WP_UNINSTALL_PLUGIN intentionally left undefined',
            var_export($statePath, true),
            var_export($fixturePath, true),
        ),
        MGWS_UNINSTALL_PROBE_TEMPLATE
    );
    file_put_contents($probePath, $probe);

    $lines = array();
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath) . ' 2>&1', $lines, $exit);
    $output = implode(PHP_EOL, $lines);

    $state = array();
    if (is_file($statePath)) {
        $decoded = json_decode((string) file_get_contents($statePath), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    foreach (array($statePath, $fixturePath, $probePath) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    return array('exit' => $exit, 'output' => $output, 'state' => $state);
}

function mgws_uninstall_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mgws_uninstall_assert_no_fatal(array $probe): void {
    mgws_uninstall_assert(
        !str_contains($probe['output'], 'Fatal error')
        && !str_contains($probe['output'], 'Uncaught')
        && !str_contains($probe['output'], 'Parse error'),
        'probe must not fail with a PHP error, output was: ' . $probe['output']
    );
}

function mgws_uninstall_drop_queries(array $probe): array {
    $drops = array();
    foreach ($probe['state']['queries'] ?? array() as $query) {
        // Backticks around the identifier are quoting style, not identity: the
        // assertion is about which tables are dropped, not how they are quoted.
        if (preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', (string) $query, $matches) === 1) {
            $drops[] = $matches[1];
        }
    }
    return $drops;
}

function mgws_uninstall_tests(): array {
    return array(
        array(
            'name' => 'uninstall_guards_without_constant',
            'description' => 'without WP_UNINSTALL_PLUGIN, uninstall.php destroys nothing',
            'run' => static function (): void {
                $probe = mgws_uninstall_probe(false, array());

                mgws_uninstall_assert_no_fatal($probe);
                mgws_uninstall_assert(
                    !str_contains($probe['output'], 'MGWS_PROBE_REACHED_END'),
                    'uninstall.php must exit before its own body when WP_UNINSTALL_PLUGIN is undefined'
                );
                mgws_uninstall_assert(
                    $probe['state']['queries'] === array(),
                    'no query may run without WP_UNINSTALL_PLUGIN, ran: ' . implode(' | ', $probe['state']['queries'] ?? array())
                );
                mgws_uninstall_assert(
                    ($probe['state']['deleted_options'] ?? null) === array(),
                    'no option may be deleted without WP_UNINSTALL_PLUGIN'
                );
            },
        ),
        array(
            'name' => 'uninstall_drops_all_18_tables',
            'description' => 'all 18 tables are dropped, including the ones no other task touches',
            'run' => static function (): void {
                $probe = mgws_uninstall_probe(true, array());
                mgws_uninstall_assert_no_fatal($probe);
                mgws_uninstall_assert(
                    str_contains($probe['output'], 'MGWS_PROBE_REACHED_END'),
                    'uninstall.php must return normally when WP_UNINSTALL_PLUGIN is defined'
                );

                $drops = mgws_uninstall_drop_queries($probe);
                foreach (MGWS_UNINSTALL_TABLES as $suffix) {
                    mgws_uninstall_assert(
                        in_array('wp_' . $suffix, $drops, true),
                        'table wp_' . $suffix . ' must be dropped, dropped set: ' . implode(', ', $drops)
                    );
                }
                mgws_uninstall_assert(
                    count($drops) === count(MGWS_UNINSTALL_TABLES),
                    'exactly ' . count(MGWS_UNINSTALL_TABLES) . ' tables must be dropped, got ' . count($drops) . ': ' . implode(', ', $drops)
                );
            },
        ),
        array(
            'name' => 'uninstall_works_without_woocommerce',
            'description' => 'cleanup completes with WooCommerce absent, not just on a Woo store',
            'run' => static function (): void {
                $probe = mgws_uninstall_probe(true, array());

                mgws_uninstall_assert_no_fatal($probe);
                mgws_uninstall_assert(
                    str_contains($probe['output'], 'MGWS_PROBE_WOO_ABSENT'),
                    'this case is only meaningful with WooCommerce absent'
                );
                mgws_uninstall_assert(
                    $probe['exit'] === 0,
                    'uninstall.php must exit 0 without WooCommerce, got ' . $probe['exit'] . ': ' . $probe['output']
                );
                mgws_uninstall_assert(
                    count(mgws_uninstall_drop_queries($probe)) === count(MGWS_UNINSTALL_TABLES),
                    'all tables must be dropped even without WooCommerce'
                );
                foreach (MGWS_UNINSTALL_OPTIONS as $option) {
                    mgws_uninstall_assert(
                        in_array($option, $probe['state']['deleted_options'] ?? array(), true),
                        'option ' . $option . ' must be deleted even without WooCommerce'
                    );
                }
            },
        ),
        array(
            'name' => 'uninstall_removes_options_and_capabilities',
            'description' => 'the 3 options and the 7 capabilities are removed, and no capability that is not ours',
            'run' => static function (): void {
                $probe = mgws_uninstall_probe(true, array(
                    'roles' => array(
                        'administrator' => array(
                            'edit_posts' => true,
                            'manage_options' => true,
                            'mgws_stock_read' => true,
                            'mgws_manage_user_permissions' => true,
                        ),
                        'shop_manager' => array(
                            'edit_products' => true,
                            'mgws_stock_move' => true,
                            'mgws_order_accept' => true,
                        ),
                    ),
                ));
                mgws_uninstall_assert_no_fatal($probe);

                $deleted = $probe['state']['deleted_options'] ?? array();
                foreach (MGWS_UNINSTALL_OPTIONS as $option) {
                    mgws_uninstall_assert(
                        in_array($option, $deleted, true),
                        'option ' . $option . ' must be deleted, deleted: ' . implode(', ', $deleted)
                    );
                }

                $removed = $probe['state']['removed_caps'] ?? array();
                foreach (array('administrator', 'shop_manager') as $role) {
                    foreach (MGWS_UNINSTALL_CAPS as $cap) {
                        mgws_uninstall_assert(
                            in_array($role . ':' . $cap, $removed, true),
                            'capability ' . $cap . ' must be removed from role ' . $role
                        );
                    }
                }
                foreach ($removed as $entry) {
                    $cap = substr((string) $entry, strpos((string) $entry, ':') + 1);
                    mgws_uninstall_assert(
                        in_array($cap, MGWS_UNINSTALL_CAPS, true),
                        'uninstall must not touch capabilities it does not own, found ' . $cap
                    );
                }
            },
        ),
        array(
            'name' => 'uninstall_removes_cpts_user_meta_and_rewrites',
            'description' => 'mg_warehouse posts go before mg_site posts, user meta goes, unrelated posts stay',
            'run' => static function (): void {
                $probe = mgws_uninstall_probe(true, array(
                    'posts' => array(
                        10 => array('ID' => 10, 'post_type' => 'mg_site'),
                        20 => array('ID' => 20, 'post_type' => 'mg_warehouse'),
                        21 => array('ID' => 21, 'post_type' => 'mg_warehouse'),
                        30 => array('ID' => 30, 'post_type' => 'product'),
                        31 => array('ID' => 31, 'post_type' => 'shop_order'),
                    ),
                ));
                mgws_uninstall_assert_no_fatal($probe);

                $deleted = array_map('intval', $probe['state']['deleted_posts'] ?? array());
                foreach (array(20, 21, 10) as $expectedId) {
                    mgws_uninstall_assert(
                        in_array($expectedId, $deleted, true),
                        'post ' . $expectedId . ' must be deleted, deleted: ' . implode(', ', $deleted)
                    );
                }
                foreach (array(30, 31) as $unrelatedId) {
                    mgws_uninstall_assert(
                        !in_array($unrelatedId, $deleted, true),
                        'post ' . $unrelatedId . ' is not ours and must survive'
                    );
                }
                mgws_uninstall_assert(
                    $deleted === array(20, 21, 10),
                    'mg_warehouse posts must be deleted before mg_site posts, got order: ' . implode(', ', $deleted)
                );

                mgws_uninstall_assert(
                    in_array('mg_default_site_id', $probe['state']['deleted_user_meta'] ?? array(), true),
                    'user meta mg_default_site_id must be removed'
                );
                mgws_uninstall_assert(
                    ($probe['state']['flushed_rewrite_rules'] ?? false) === true,
                    'rewrite rules must be flushed so the removed post types leave no routes'
                );
            },
        ),
        array(
            'name' => 'uninstall_hook_is_registered',
            'description' => 'the main plugin file registers the uninstall hook against the shared callback',
            'run' => static function (): void {
                $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/mg-warehouse-stock.php');

                mgws_uninstall_assert(
                    str_contains($bootstrap, 'register_uninstall_hook('),
                    'mg-warehouse-stock.php must call register_uninstall_hook()'
                );
                mgws_uninstall_assert(
                    preg_match('/register_uninstall_hook\(\s*MGWS_PLUGIN_FILE\s*,\s*[\'"]mgws_uninstall_all[\'"]\s*\)/', $bootstrap) === 1,
                    'register_uninstall_hook must be called as register_uninstall_hook(MGWS_PLUGIN_FILE, \'mgws_uninstall_all\')'
                );

                $uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
                mgws_uninstall_assert(
                    str_contains($uninstall, "includes/mgws-db.php"),
                    'uninstall.php must load includes/mgws-db.php: the plugin bootstrap is not active during uninstall, so MGWS_DB has to be required here'
                );
            },
        ),
    );
}

$mgws_uninstall_tests = mgws_uninstall_tests();
$mgws_uninstall_failures = 0;

foreach ($mgws_uninstall_tests as $mgws_uninstall_test) {
    try {
        $mgws_uninstall_test['run']();
        fwrite(STDOUT, '[PASS] ' . $mgws_uninstall_test['name'] . PHP_EOL);
    } catch (Throwable $error) {
        $mgws_uninstall_failures++;
        fwrite(STDERR, '[FAIL] ' . $mgws_uninstall_test['name'] . ': ' . $error->getMessage() . PHP_EOL);
    }
}

fwrite(STDOUT, 'SUMMARY failures=' . $mgws_uninstall_failures . PHP_EOL);
exit($mgws_uninstall_failures > 0 ? 1 : 0);
