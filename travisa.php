<?php
/**
 * Plugin Name: TraVisa Services
 * Description: Arabic travel services, validated XLSX imports, price versions and WooCommerce integration.
 * Version: 1.1.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: TraVisa
 * Text Domain: travisa
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('TRAVISA_VERSION', '1.1.3');
define('TRAVISA_FILE', __FILE__);
define('TRAVISA_DIR', plugin_dir_path(__FILE__));
foreach (['domain', 'xlsx', 'store', 'admin', 'frontend', 'woocommerce'] as $module) {
    require_once TRAVISA_DIR . 'includes/class-' . $module . '.php';
}
register_activation_hook(__FILE__, ['TraVisa_Store', 'install']);
add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('plugins_loaded', static function () {
    TraVisa_Admin::boot();
    TraVisa_Frontend::boot();
    if (class_exists('WooCommerce')) { TraVisa_WooCommerce::boot(); }
    else { add_action('admin_notices', static function () { echo '<div class="notice notice-error"><p>TraVisa: فعّل WooCommerce لإتاحة السلة والطلبات.</p></div>'; }); }
});
