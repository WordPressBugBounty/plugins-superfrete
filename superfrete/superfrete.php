<?php
/*
  Plugin Name: SuperFrete
  Description: Plugin that provides integration with the SuperFrete platform.
  Version:     2.1.6
  Author:      Super Frete
  Author URI:  https://zafarie.com.br/
  Text Domain: superfrete
  License:     GPLv2 or later
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}

// Inclui a classe principal do plugin
include_once __DIR__ . '/app/App.php';

// Inicializa o plugin
new SuperFrete\App();


add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['SuperFrete\App', 'superfrete_add_settings_link']);
