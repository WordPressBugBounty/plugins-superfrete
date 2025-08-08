<?php

namespace SuperFrete_API\Admin;

if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}

class Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'superfrete_add_admin_menu']);
    }

    /**
     * Adiciona submenus ao WooCommerce para exibir logs do SuperFrete e um link para configurações.
     */
    public function superfrete_add_admin_menu() {
        add_submenu_page(
            'woocommerce', // Adiciona dentro do WooCommerce
            'Configurações SuperFrete', // Título da página
            'Configurações SuperFrete', // Nome no menu
            'manage_woocommerce', // Permissão necessária
            'superfrete-settings', // Slug da página
            [$this, 'superfrete_redirect_to_settings'] // Callback que redireciona para as configurações
        );

        add_submenu_page(
            'woocommerce',
            'Logs SuperFrete',
            'Logs SuperFrete',
            'manage_woocommerce',
            'superfrete-logs',
            [$this, 'superfrete_display_logs']
        );
    }

    /**
     * Redireciona para a aba de configurações da SuperFrete no WooCommerce.
     */
    public function superfrete_redirect_to_settings() {
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=shipping&section=options#superfrete_settings_section-description'));
        exit;
    }

    /**
     * Exibe os logs do SuperFrete no painel do WooCommerce.
     */
    public function superfrete_display_logs() {
        echo '<div class="wrap">';
        echo '<h1>Logs da SuperFrete</h1>';

        $log_content = \SuperFrete_API\Helpers\Logger::get_log();
        echo '<textarea readonly style="width:100%; height:500px; font-family:monospace;">';
        echo esc_textarea($log_content);
        echo '</textarea>';

        echo '<form method="post" action="">';
        wp_nonce_field('clear_log_action'); // Adicionar verificação de nonce
        echo '<button type="submit" name="clear_log" class="button button-danger">Limpar Log</button>';
        echo '</form>';

        echo '</div>';

        // Se o botão de limpar log for pressionado
        if (isset($_POST['clear_log']) && check_admin_referer('clear_log_action')) {
            \SuperFrete_API\Helpers\Logger::clear_log();
            echo '<script>location.reload();</script>'; // Recarrega a página
        }
    }
}
