<?php
/**
 * Plugin Name: Warehouse Stock Manager for WooCommerce
 * Plugin URI: https://github.com/Vincenzoferrara/mgws-inventory-wordpress-plugin
 * Description: Multi-site and multi-warehouse stock, picking, movement ledger, purchase orders and inventory counts for WooCommerce.
 * Version: 2.4.0
 * Author: MG
 * Author URI: https://github.com/Vincenzoferrara
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mg-warehouse-stock
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MGWS_PLUGIN_FILE', __FILE__);
define('MGWS_PLUGIN_DIR', __DIR__);
define('MGWS_PLUGIN_VERSION', '2.4.0');

require_once MGWS_PLUGIN_DIR . '/includes/class-mgws-plugin.php';

// Loaded on init rather than at file scope: WordPress 6.7 and later refuse
// early text domain loading, and this is where the .mo files are expected to
// be found. WordPress.org also auto-loads translations when the text domain
// matches the plugin slug, so this is the fallback that keeps local and
// self-hosted installs working.
add_action('init', static function (): void {
    load_plugin_textdomain(
        'mg-warehouse-stock',
        false,
        dirname(plugin_basename(MGWS_PLUGIN_FILE)) . '/languages'
    );
});

// HPOS (High-Performance Order Storage) compatibility has to be declared before
// WooCommerce initialises. Registering this from the plugin singleton would be
// too late: WooCommerce fires the hook while handling plugins_loaded, and
// "Requires Plugins: woocommerce" makes this plugin load after it.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MGWS_PLUGIN_FILE, true);
    }
});

register_activation_hook(MGWS_PLUGIN_FILE, array('MGWS_Plugin', 'activate'));

// The callback is defined in uninstall.php, which WordPress includes before
// calling it. Nothing here may reference the function at load time.
register_uninstall_hook(MGWS_PLUGIN_FILE, 'mgws_uninstall_all');

add_action('plugins_loaded', array('MGWS_Plugin', 'instance'));
