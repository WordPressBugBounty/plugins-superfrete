<?php
/*
  Plugin Name: SuperFrete
  Description: Plugin that provides integration with the SuperFrete platform.
  Version:     3.3.2
  Author:      Super Frete
  Author URI:  https://superfrete.com/
  Text Domain: superfrete
  License:     GPLv2 or later
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
  Requires at least: 5.0
  Tested up to: 6.8.1
  Requires PHP: 7.4
  WC requires at least: 3.0
  WC tested up to: 9.4
 */
if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}


// Inclui a classe principal do plugin
include_once __DIR__ . '/app/App.php';

// Inicializa o plugin
new SuperFrete_API\App();


add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['SuperFrete_API\App', 'superfrete_add_settings_link']);

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
// Only declare HPOS compatibility if WooCommerce version supports it (8.2+)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Check if WooCommerce version supports HPOS (8.2+)
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '8.2', '>=')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
});
