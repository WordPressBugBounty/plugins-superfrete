<?php 
namespace SuperFrete_API\Admin;

use SuperFrete_API\Http\Request;
if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}

class SuperFrete_Settings {
    
    /**
     * Recupera as configurações antigas do plugin.
     */
    public static function get_legacy_settings() {
        return get_option('superfrete-calculator-setting', []);
    }

    /**
     * Inicializa as configurações do SuperFrete se ainda não existirem
     */
    public static function migrate_old_settings() {
        $legacy_settings = self::get_legacy_settings();
     
        // Recupera as novas configurações
        $sandbox_enabled = get_option('superfrete_sandbox_mode', null);
        $token_production = get_option('superfrete_api_token', null);
        $token_sandbox = get_option('superfrete_api_token_sandbox', null);
           

        // Se os novos valores ainda não existem, usa os valores antigos e salva no banco de dados
        if (strlen($sandbox_enabled) < 1&& isset($legacy_settings['superfrete_sandbox_enabled'])) {
            update_option('superfrete_sandbox_mode', $legacy_settings['superfrete_sandbox_enabled'] ? 'yes' : 'no');
        }

        if (strlen($token_production) < 1 && isset($legacy_settings['superfrete_token_production'])) {
            update_option('superfrete_api_token', $legacy_settings['superfrete_token_production']);
        }

        if (strlen($token_sandbox) < 1 && isset($legacy_settings['superfrete_token_sandbox'])) {
            update_option('superfrete_api_token_sandbox', $legacy_settings['superfrete_token_sandbox']);
        }
    }

    /**
     * Adiciona a aba de configuração do SuperFrete no WooCommerce > Configurações > Entrega
     */
    public static function add_superfrete_settings($settings) {
        
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/user', 'GET', [], true);
        
 
        
        $text_conect = ($response)? "Conexão feita para o usuario: ". $response['firstname'] . " " . $response['lastname'] : "Não Conectado";
        
        
        // Garante que as configurações antigas sejam migradas antes de exibir a página de configurações
        self::migrate_old_settings();

        $settings[] = [
            'title' => 'Configuração do SuperFrete',
            'type'  => 'title',
            'desc'  => 'Defina suas credenciais da SuperFrete.',
            'id'    => 'superfrete_settings_section'
        ];

        
        $settings[] = [
    'type'  => 'title',
    'title' => $text_conect,
    'id'    => 'superfrete_connected_notice'
];

        $settings[] = [
            'title'    => 'Token de Produção',
            'desc'     => 'Insira seu token de API para ambiente de produção.<br><br><a target="_blank" href="https://web.superfrete.com/#/integrations" style="display:inline-block; margin-top:15px; padding:6px 12px; background:#0fae79; color:white; text-decoration:none; border-radius:4px;">Gerar Token</a>',
            'id'       => 'superfrete_api_token',
            'type'     => 'text',
            'css'      => 'width: 400px;',
            'desc_tip' => 'Token de autenticação para API em ambiente de produção.',
            'default'  => get_option('superfrete_api_token', ''),
        ];
   
$settings[] = [
            'title'    => 'Ativar Sandbox',
            'desc'     => 'Habilitar ambiente de testes',
            'id'       => 'superfrete_sandbox_mode',
            'type'     => 'checkbox',
            'default'  => get_option('superfrete_sandbox_mode', 'no'),
            'desc_tip' => 'Ao ativar o modo sandbox, a API usará um token de teste.'
        ];
           
$settings[] = [
    'title'    => 'Ativar Calculadora',
    'desc'     => 'Habilitar calculadora de frete na página do produto',
    'id'       => 'superfrete_enable_calculator',
    'type'     => 'checkbox',
    'default'  => 'yes',
    'desc_tip' => 'Ativar a calculadora de frete na página do produto.',
];

        $settings[] = [
            'title'    => 'Token de Sandbox',
            'desc'     => 'Insira seu token de API para o ambiente de teste.',
            'id'       => 'superfrete_api_token_sandbox',
            'type'     => 'text',
            'css'      => 'width: 400px;',
            'desc_tip' => 'Token de autenticação para API no ambiente de testes (sandbox).',
            'class'    => 'superfrete-sandbox-field',
            'default'  => get_option('superfrete_api_token_sandbox', ''),
        ];

        $settings[] = [
            'type' => 'sectionend',
            'id'   => 'superfrete_settings_section'
        ];

        return $settings;
    }

    /**
     * Adiciona JavaScript para exibir/esconder o campo de Token de Sandbox dinamicamente.
     */
    public static function enqueue_admin_scripts() {
        $current_screen = get_current_screen();
        if ($current_screen->id === 'woocommerce_page_wc-settings') {
            ?>
            <script>
                jQuery(document).ready(function($) {
                    function toggleSandboxField() {
                        if ($('#superfrete_sandbox_mode').is(':checked')) {
                            $('.superfrete-sandbox-field').closest('tr').show();
                        } else {
                            $('.superfrete-sandbox-field').closest('tr').hide();
                        }
                    }

                    // Executa ao carregar a página
                    toggleSandboxField();

                    // Monitora mudanças no checkbox
                    $('#superfrete_sandbox_mode').change(function() {
                        toggleSandboxField();
                    });
                });
            </script>
            <?php
        }
    }
}

// Executa a migração assim que o plugin for carregado
add_action('admin_init', ['SuperFrete_API\Admin\SuperFrete_Settings', 'migrate_old_settings']);

// Hook para adicionar a aba dentro de "Entrega"
add_filter('woocommerce_shipping_settings', ['SuperFrete_API\Admin\SuperFrete_Settings', 'add_superfrete_settings']);
add_action('admin_footer', ['SuperFrete_API\Admin\SuperFrete_Settings', 'enqueue_admin_scripts']);
