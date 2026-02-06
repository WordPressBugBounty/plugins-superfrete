<?php 
namespace SuperFrete_API\Admin;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;
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

        if (strlen($token_production) < 1 && isset($legacy_settings['superfrete_api_token'])) {
            update_option('superfrete_api_token', $legacy_settings['superfrete_api_token']);
        }

        if (strlen($token_sandbox) < 1 && isset($legacy_settings['superfrete_api_token_sandbox'])) {
            update_option('superfrete_api_token_sandbox', $legacy_settings['superfrete_api_token_sandbox']);
        }

        // Auto-register webhooks for existing installations (migration from pre-OAuth to OAuth)
        $webhook_migrated = get_option('superfrete_webhook_migrated', 'no');
        $webhook_registered = get_option('superfrete_webhook_registered', 'no');
        
        if ($webhook_migrated !== 'yes' && $webhook_registered !== 'yes') {
            // Check if we have existing tokens (indicating this is an existing installation)
            $current_sandbox_mode = get_option('superfrete_sandbox_mode', 'no');
            $current_token = ($current_sandbox_mode === 'yes') ? 
                get_option('superfrete_api_token_sandbox') : 
                get_option('superfrete_api_token');

            if (!empty($current_token)) {
                Logger::log('SuperFrete', 'Migration: Found existing API token, attempting webhook auto-registration');
                
                try {
                    $request = new Request();
                    
                    // Validate token first
                    $user_response = $request->call_superfrete_api('/api/v0/user', 'GET', [], true);
                    
                    if ($user_response && isset($user_response['id'])) {
                        Logger::log('SuperFrete', 'Migration: Token validated, registering webhook');
                        
                        // Register webhook
                        $webhook_url = rest_url('superfrete/v1/webhook');
                        $webhook_result = $request->register_webhook($webhook_url);
                        
                        if ($webhook_result) {
                            update_option('superfrete_webhook_registered', 'yes');
                            update_option('superfrete_webhook_url', $webhook_url);
                            Logger::log('SuperFrete', 'Migration: Webhook registered successfully: ' . wp_json_encode($webhook_result));
                        } else {
                            Logger::log('SuperFrete', 'Migration: Webhook registration failed');
                        }
                    } else {
                        Logger::log('SuperFrete', 'Migration: Token validation failed, skipping webhook registration');
                    }
                } catch (Exception $e) {
                    Logger::log('SuperFrete', 'Migration: Webhook registration error: ' . $e->getMessage());
                    // Don't break migration for webhook issues
                } catch (Error $e) {
                    Logger::log('SuperFrete', 'Migration: Webhook registration fatal error: ' . $e->getMessage());
                }
                
                // Mark migration as attempted regardless of success/failure
                update_option('superfrete_webhook_migrated', 'yes');
            } else {
                Logger::log('SuperFrete', 'Migration: No existing token found, skipping webhook auto-registration');
                // Mark as migrated since there's nothing to migrate
                update_option('superfrete_webhook_migrated', 'yes');
            }
        }
    }

    /**
     * Adiciona a aba de configuração do SuperFrete no WooCommerce > Configurações > Entrega
     */
    public static function add_superfrete_settings($settings) {
        
        // Add custom field renderer for webhook status
        add_action('woocommerce_admin_field_superfrete_webhook_status', [__CLASS__, 'render_webhook_status_field']);
        
        // Add custom field renderer for preview
        add_action('woocommerce_admin_field_superfrete_preview', [__CLASS__, 'render_preview_field']);
        
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/user', 'GET', [], true);
        
        $is_connected = ($response && isset($response['id']));
        $user_name = $is_connected ? $response['firstname'] . " " . $response['lastname'] : '';
        
        // Check webhook status
        $webhook_status = self::get_webhook_status();
        
        // Garante que as configurações antigas sejam migradas antes de exibir a página de configurações
        self::migrate_old_settings();

        $settings[] = [
            'title' => 'Configuração do SuperFrete',
            'type'  => 'title',
            'desc'  => 'Defina suas credenciais da SuperFrete.',
            'id'    => 'superfrete_settings_section'
        ];

        if ($is_connected) {
            // Show connected status and reconnect option
            $settings[] = [
                'type'  => 'title',
                'title' => '✅ Conectado como: ' . $user_name,
                'id'    => 'superfrete_connected_notice'
            ];

            $settings[] = [
                'title'    => 'Gerenciar Conexão',
                'desc'     => 'Sua conta SuperFrete está conectada e funcionando.<br><br>' . 
                             '<button type="button" id="superfrete-oauth-btn" class="button" style="margin-top:15px; padding:6px 12px; background:#f0ad4e; color:white; text-decoration:none; border-radius:4px; border:none; cursor:pointer;" onclick="return confirm(\'Tem certeza que deseja reconectar? Isso irá substituir a conexão atual.\');">' .
                             'Reconectar Integração' .
                             '</button>' .
                             '<div id="superfrete-oauth-status" style="margin-top:10px;"></div>',
                'id'       => 'superfrete_oauth_reconnection',
                'type'     => 'title',
                'desc_tip' => 'Use apenas se houver problemas com a conexão atual.',
            ];
        } else {
            // Show connection setup
            $settings[] = [
                'type'  => 'title',
                'title' => '❌ Não Conectado',
                'id'    => 'superfrete_disconnected_notice'
            ];

            $settings[] = [
                'title'    => 'Conexão SuperFrete',
                'desc'     => 'Conecte sua conta SuperFrete automaticamente via OAuth.<br><br>' . 
                             '<button type="button" id="superfrete-oauth-btn" class="button button-primary" style="margin-top:15px; padding:6px 12px; background:#0fae79; color:white; text-decoration:none; border-radius:4px; border:none; cursor:pointer;">' .
                             'Conectar com SuperFrete' .
                             '</button>' .
                             '<div id="superfrete-oauth-status" style="margin-top:10px;"></div>',
                'id'       => 'superfrete_oauth_connection',
                'type'     => 'title',
                'desc_tip' => 'Use o botão acima para conectar sua conta SuperFrete de forma segura.',
            ];
        }
   
        $settings[] = [
            'title'    => 'Ativar Calculadora',
            'desc'     => 'Habilitar calculadora de frete na página do produto',
            'id'       => 'superfrete_enable_calculator',
            'type'     => 'checkbox',
            'default'  => 'yes',
            'desc_tip' => 'Ativar a calculadora de frete na página do produto.',
        ];

        $settings[] = [
            'title'    => 'Cálculo Automático',
            'desc'     => 'Calcular frete automaticamente ao carregar a página do produto',
            'id'       => 'superfrete_auto_calculation',
            'type'     => 'checkbox',
            'default'  => 'no', // Disabled by default for better performance
            'desc_tip' => 'Quando desabilitado, o frete só será calculado quando o usuário clicar no botão. Recomendado para melhor performance.',
        ];



        $settings[] = [
            'type' => 'sectionend',
            'id'   => 'superfrete_settings_section'
        ];

        // Visual Customization Section
        $settings[] = [
            'title' => 'Personalização Visual',
            'type'  => 'title',
            'desc'  => 'Personalize as cores e aparência da calculadora de frete.<br><br>' . 
                      '<div style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-radius: 5px;">' .
                      '<strong>Presets de Tema:</strong><br>' .
                      '<button type="button" id="superfrete-preset-light" class="button" style="margin: 5px 10px 5px 0;">🌞 Tema Claro</button>' .
                      '<button type="button" id="superfrete-preset-dark" class="button" style="margin: 5px 10px 5px 0;">🌙 Tema Escuro</button>' .
                      '<button type="button" id="superfrete-preset-auto" class="button" style="margin: 5px 0;">🎨 Auto-Detectar</button>' .
                      '</div>',
            'id'    => 'superfrete_visual_section'
        ];

        $settings[] = [
            'title'    => 'Pré-visualização',
            'desc'     => 'Veja como a calculadora ficará com suas personalizações',
            'id'       => 'superfrete_preview',
            'type'     => 'superfrete_preview',
        ];

        $settings[] = [
            'title'    => 'Cor Principal',
            'desc'     => 'Cor principal dos botões, preços e elementos interativos',
            'id'       => 'superfrete_custom_primary_color',
            'type'     => 'color',
            'default'  => '#0fae79',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Usada para botões, preços, bordas de foco e elementos de destaque.',
        ];

        $settings[] = [
            'title'    => 'Cor de Erro',
            'desc'     => 'Cor para mensagens de erro e alertas',
            'id'       => 'superfrete_custom_error_color',
            'type'     => 'color',
            'default'  => '#e74c3c',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Usada para indicar erros e alertas.',
        ];

        $settings[] = [
            'title'    => 'Fundo da Calculadora',
            'desc'     => 'Cor de fundo principal da calculadora',
            'id'       => 'superfrete_custom_bg_color',
            'type'     => 'color',
            'default'  => '#ffffff',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Cor de fundo do container principal da calculadora.',
        ];

        $settings[] = [
            'title'    => 'Fundo dos Resultados',
            'desc'     => 'Cor de fundo da área de resultados',
            'id'       => 'superfrete_custom_results_bg_color',
            'type'     => 'color',
            'default'  => '#ffffff',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Cor de fundo onde são exibidos os métodos de envio.',
        ];

        $settings[] = [
            'title'    => 'Cor do Texto Principal',
            'desc'     => 'Cor do texto principal e títulos',
            'id'       => 'superfrete_custom_text_color',
            'type'     => 'color',
            'default'  => '#1a1a1a',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Cor do texto principal, títulos e labels.',
        ];

        $settings[] = [
            'title'    => 'Cor do Texto Secundário',
            'desc'     => 'Cor do texto secundário e placeholders',
            'id'       => 'superfrete_custom_text_light_color',
            'type'     => 'color',
            'default'  => '#777777',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Cor do texto secundário, placeholders e descrições.',
        ];

        $settings[] = [
            'title'    => 'Cor das Bordas',
            'desc'     => 'Cor das bordas dos elementos',
            'id'       => 'superfrete_custom_border_color',
            'type'     => 'color',
            'default'  => '#e0e0e0',
            'css'      => 'width: 6em;',
            'desc_tip' => 'Cor das bordas dos campos de input e containers.',
        ];

        $settings[] = [
            'title'    => 'Tamanho da Fonte',
            'desc'     => 'Tamanho base da fonte na calculadora',
            'id'       => 'superfrete_custom_font_size',
            'type'     => 'select',
            'default'  => '14px',
            'options'  => [
                '12px' => 'Pequeno (12px)',
                '14px' => 'Médio (14px)',
                '16px' => 'Grande (16px)',
                '18px' => 'Muito Grande (18px)',
            ],
            'desc_tip' => 'Ajuste o tamanho da fonte para melhor legibilidade.',
        ];

        $settings[] = [
            'title'    => 'Bordas Arredondadas',
            'desc'     => 'Nível de arredondamento das bordas',
            'id'       => 'superfrete_custom_border_radius',
            'type'     => 'select',
            'default'  => '4px',
            'options'  => [
                '0px' => 'Sem Arredondamento',
                '2px' => 'Pouco Arredondado',
                '4px' => 'Médio',
                '8px' => 'Muito Arredondado',
                '12px' => 'Extremamente Arredondado',
            ],
            'desc_tip' => 'Ajuste o estilo das bordas dos elementos.',
        ];

        $settings[] = [
            'title'    => 'Resetar Personalização',
            'desc'     => 'Voltar às configurações visuais padrão do SuperFrete',
            'id'       => 'superfrete_reset_customization',
            'type'     => 'button',
            'desc_tip' => 'Clique para restaurar todas as configurações visuais para os valores padrão.',
            'custom'   => '<button type="button" id="superfrete-reset-customization" class="button button-secondary">Resetar para Padrão</button>',
        ];

        $settings[] = [
            'type' => 'sectionend',
            'id'   => 'superfrete_visual_section'
        ];

        // Advanced Configuration Section (Accordion)
        $settings[] = [
            'title' => 'Configurações Avançadas',
            'type'  => 'title',
            'desc'  => '<div id="superfrete-advanced-toggle" style="cursor: pointer; padding: 10px; background: #f1f1f1; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0;">' .
                      '<span style="font-weight: bold;">▼ Mostrar Configurações Avançadas</span>' .
                      '</div>',
            'id'    => 'superfrete_advanced_section'
        ];

        $settings[] = [
            'title'    => 'Ativar Sandbox',
            'desc'     => 'Habilitar ambiente de testes',
            'id'       => 'superfrete_sandbox_mode',
            'type'     => 'checkbox',
            'default'  => get_option('superfrete_sandbox_mode', 'no'),
            'desc_tip' => 'Ao ativar o modo sandbox, a API usará o ambiente de testes da SuperFrete.'
        ];

        $settings[] = [
            'title'    => 'Modo Debug',
            'desc'     => 'Ativar logs detalhados de debug',
            'id'       => 'superfrete_debug_mode',
            'type'     => 'select',
            'default'  => get_option('superfrete_debug_mode', 'auto'),
            'options'  => [
                'auto'     => 'Automático (respeita WP_DEBUG)',
                'enabled'  => 'Sempre ativado',
                'disabled' => 'Sempre desativado',
            ],
            'desc_tip' => 'Controla a geração de logs de debug. "Automático" respeita a constante WP_DEBUG do WordPress. Logs de debug podem impactar a performance em produção.',
            'class'    => 'superfrete-advanced-field'
        ];

        $settings[] = [
            'type'  => 'superfrete_webhook_status',
            'title' => 'Status dos Webhooks',
            'id'    => 'superfrete_webhook_status',
            'webhook_status' => $webhook_status,
            'class' => 'superfrete-advanced-field'
        ];

        $settings[] = [
            'type' => 'sectionend',
            'id'   => 'superfrete_advanced_section'
        ];

        return $settings;
    }

    /**
     * Adiciona JavaScript para exibir/esconder o campo de Token de Sandbox dinamicamente.
     */
    public static function enqueue_admin_scripts() {
        // Check if we're on a WooCommerce settings page
        if (self::is_woocommerce_settings_page()) {
            // Ensure jQuery is loaded
            wp_enqueue_script('jquery');
            
            // Enqueue calculator CSS for preview
            wp_enqueue_style('superfrete-calculator-css', plugin_dir_url(__FILE__) . '../../../assets/styles/superfrete-calculator.css', [], '1.0');
            
            // Add inline script
            add_action('admin_footer', function() {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    console.log('SuperFrete admin scripts loading...'); // Debug log
                    
                    // Make sure ajaxurl is available
                    if (typeof ajaxurl === 'undefined') {
                        window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    }
                    
                    // Advanced settings accordion toggle
                    $('#superfrete-advanced-toggle').on('click', function() {
                        // Target specific field IDs for advanced settings
                        var $sandboxField = $('#superfrete_sandbox_mode').closest('tr');
                        var $debugField = $('#superfrete_debug_mode').closest('tr');
                        var $webhookField = $('.superfrete-advanced-field').closest('tr');
                        var $advancedFields = $sandboxField.add($debugField).add($webhookField);
                        var $toggle = $(this).find('span');

                        if ($advancedFields.is(':visible')) {
                            $advancedFields.slideUp();
                            $toggle.text('▼ Mostrar Configurações Avançadas');
                        } else {
                            $advancedFields.slideDown();
                            $toggle.text('▲ Ocultar Configurações Avançadas');
                        }
                    });

                    // Initially hide advanced fields
                    // $('#superfrete_sandbox_mode').closest('tr').hide(); // Show sandbox mode by default
                    $('#superfrete_debug_mode').closest('tr').hide();
                    $('.superfrete-advanced-field').closest('tr').hide();
                    
                    // Add OAuth functionality
                    $('#superfrete-oauth-btn').on('click', function() {
                        console.log('SuperFrete OAuth button clicked');
                        
                        var $button = $(this);
                        var $status = $('#superfrete-oauth-status');
                        
                        // Disable button and show loading
                        var originalText = $button.text();
                        var isReconnecting = originalText.includes('Reconectar');
                        $button.prop('disabled', true).text(isReconnecting ? 'Reconectando...' : 'Conectando...');
                        $status.html('<span style="color: #0073aa;">Iniciando ' + (isReconnecting ? 'reconexão' : 'conexão') + ' com SuperFrete...</span>');
                        
                        // Get API URL based on environment settings
                        <?php
                        $use_dev_env = get_option('superfrete_sandbox_mode', 'no') === 'yes';
                        $api_url = $use_dev_env ? 'https://api.dev.superintegrador.superfrete.com' : 'https://api.superintegrador.superfrete.com';
                        ?>
                        
                        var apiUrl = '<?php echo $api_url; ?>';
                        var siteUrl = '<?php echo get_site_url(); ?>';
                        var oauthUrl = apiUrl + '/headless/oauth/init?callback_url=' + encodeURIComponent(siteUrl + '/wp-admin/admin-ajax.php?action=superfrete_oauth_callback');
                        
                        // Open OAuth popup
                        var popup = window.open(
                            oauthUrl,
                            'superfrete_oauth',
                            'width=600,height=700,scrollbars=yes,resizable=yes'
                        );
                        
                        // Extract session ID from the OAuth URL for polling
                        var sessionId = null;
                        
                        // Listen for session ID from popup
                        var sessionListener = function(event) {
                            if (event.origin !== apiUrl) {
                                return;
                            }
                            
                            if (event.data.type === 'superfrete_session_id') {
                                sessionId = event.data.session_id;
                                console.log('Received session ID:', sessionId);
                                // Start polling now that we have the session ID
                                setTimeout(pollForToken, 2000);
                            }
                        };
                        
                        window.addEventListener('message', sessionListener);
                        
                        // Poll for token completion
                        var pollForToken = function() {
                            if (!sessionId) {
                                console.log('No session ID yet, waiting...');
                                return;
                            }
                            
                            // Poll the WordPress proxy for token (bypasses CORS)
                            // Try REST API first, fallback to AJAX if needed
                            var restUrl = '<?php echo rest_url('superfrete/v1/oauth/token'); ?>?session_id=' + sessionId;
                            var ajaxUrl = ajaxurl + '?action=superfrete_oauth_proxy&session_id=' + sessionId + '&nonce=' + encodeURIComponent('<?php echo wp_create_nonce('wp_rest'); ?>');
                            
                            $.ajax({
                                url: restUrl,
                                type: 'GET',
                                beforeSend: function(xhr) {
                                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                                },
                                success: function(response) {
                                    if (response.ready && response.access_token) {
                                        // Token is ready - validate and save it
                                        $status.html('<span style="color: #0073aa;">Validando token...</span>');
                                        
                                        // Send token to WordPress for validation and storage
                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'superfrete_oauth_callback',
                                                token: response.access_token,
                                                session_id: sessionId,
                                                nonce: '<?php echo wp_create_nonce('superfrete_oauth_nonce'); ?>'
                                            },
                                            success: function(wpResponse) {
                                                if (wpResponse.success) {
                                                    oauthSuccessful = true; // Mark OAuth as successful
                                                    $status.html('<span style="color: #46b450;">✓ ' + wpResponse.data.message + '</span>');
                                                    $button.prop('disabled', false).text('Reconectar');
                                                    
                                                    // Show success message with user info
                                                    var userInfo = wpResponse.data.user_info;
                                                    var successMsg = 'SuperFrete conectado com sucesso!';
                                                    if (userInfo && userInfo.name) {
                                                        successMsg += '<br>Usuário: ' + userInfo.name;
                                                        if (userInfo.email) {
                                                            successMsg += ' (' + userInfo.email + ')';
                                                        }
                                                        if (userInfo.balance !== undefined) {
                                                            successMsg += '<br>Saldo: R$ ' + parseFloat(userInfo.balance).toFixed(2);
                                                        }
                                                    }
                                                    if (wpResponse.data.webhook_registered) {
                                                        successMsg += '<br>✅ Webhooks registrados automaticamente';
                                                    }
                                                    
                                                    $('<div class="notice notice-success is-dismissible"><p>' + successMsg + '</p></div>')
                                                        .insertAfter('.wrap h1');
                                                        
                                                    // Refresh the page to update connection status
                                                    setTimeout(function() {
                                                        location.reload();
                                                    }, 3000);
                                                } else {
                                                    $status.html('<span style="color: #dc3232;">✗ Erro: ' + wpResponse.data + '</span>');
                                                    $button.prop('disabled', false).text('Tentar Novamente');
                                                }
                                            },
                                            error: function(xhr, status, error) {
                                                $status.html('<span style="color: #dc3232;">✗ Erro de comunicação: ' + error + '</span>');
                                                $button.prop('disabled', false).text('Tentar Novamente');
                                            }
                                        });
                                        
                                        // Close popup
                                        popup.close();
                                    } else if (!response.ready) {
                                        // Token not ready yet, continue polling
                                        setTimeout(pollForToken, 2000);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    if (xhr.status === 404 && xhr.responseJSON && xhr.responseJSON.message === 'Not Found') {
                                        // REST API endpoint not found, try AJAX fallback
                                        console.log('REST API failed, trying AJAX fallback...');
                                        $.ajax({
                                            url: ajaxUrl,
                                            type: 'GET',
                                            success: function(response) {
                                                // Handle the same way as REST API response
                                                if (response.ready && response.access_token) {
                                                    // Process token the same way
                                                    $status.html('<span style="color: #0073aa;">Validando token...</span>');
                                                    
                                                    // Send token to WordPress for validation and storage
                                                    $.ajax({
                                                        url: ajaxurl,
                                                        type: 'POST',
                                                        data: {
                                                            action: 'superfrete_oauth_callback',
                                                            token: response.access_token,
                                                            session_id: sessionId,
                                                            nonce: '<?php echo wp_create_nonce('superfrete_oauth_nonce'); ?>'
                                                        },
                                                        success: function(wpResponse) {
                                                            if (wpResponse.success) {
                                                                oauthSuccessful = true; // Mark OAuth as successful
                                                                $status.html('<span style="color: #46b450;">✓ ' + wpResponse.data.message + '</span>');
                                                                $button.prop('disabled', false).text('Reconectar');
                                                                
                                                                var userInfo = wpResponse.data.user_info;
                                                                var successMsg = 'SuperFrete conectado com sucesso!';
                                                                if (userInfo && userInfo.name) {
                                                                    successMsg += '<br>Usuário: ' + userInfo.name;
                                                                    if (userInfo.email) {
                                                                        successMsg += ' (' + userInfo.email + ')';
                                                                    }
                                                                    if (userInfo.balance !== undefined) {
                                                                        successMsg += '<br>Saldo: R$ ' + parseFloat(userInfo.balance).toFixed(2);
                                                                    }
                                                                }
                                                                if (wpResponse.data.webhook_registered) {
                                                                    successMsg += '<br>✅ Webhooks registrados automaticamente';
                                                                }
                                                                
                                                                $('<div class="notice notice-success is-dismissible"><p>' + successMsg + '</p></div>')
                                                                    .insertAfter('.wrap h1');
                                                                    
                                                                setTimeout(function() {
                                                                    location.reload();
                                                                }, 3000);
                                                            } else {
                                                                $status.html('<span style="color: #dc3232;">✗ Erro: ' + wpResponse.data + '</span>');
                                                                $button.prop('disabled', false).text('Tentar Novamente');
                                                            }
                                                        },
                                                        error: function(xhr, status, error) {
                                                            $status.html('<span style="color: #dc3232;">✗ Erro de comunicação: ' + error + '</span>');
                                                            $button.prop('disabled', false).text('Tentar Novamente');
                                                        }
                                                    });
                                                    
                                                    popup.close();
                                                } else if (!response.ready) {
                                                    // Token not ready yet, continue polling with AJAX
                                                    setTimeout(function() {
                                                        // Switch to AJAX polling
                                                        pollForTokenAjax();
                                                    }, 2000);
                                                }
                                            },
                                            error: function(xhr, status, error) {
                                                if (xhr.status === 404) {
                                                    // Session not found or expired
                                                    $status.html('<span style="color: #dc3232;">✗ Sessão expirada. Tente novamente.</span>');
                                                    $button.prop('disabled', false).text('Tentar Novamente');
                                                    popup.close();
                                                } else {
                                                    // Continue polling on other errors
                                                    setTimeout(pollForToken, 2000);
                                                }
                                            }
                                        });
                                    } else if (xhr.status === 404) {
                                        // Session not found or expired
                                        $status.html('<span style="color: #dc3232;">✗ Sessão expirada. Tente novamente.</span>');
                                        $button.prop('disabled', false).text('Tentar Novamente');
                                        popup.close();
                                    } else {
                                        // Continue polling on other errors
                                        setTimeout(pollForToken, 2000);
                                    }
                                }
                            });
                        };
                        
                        // AJAX polling function (fallback)
                        var pollForTokenAjax = function() {
                            if (!sessionId) {
                                console.log('No session ID yet, waiting...');
                                return;
                            }
                            
                            $.ajax({
                                url: ajaxUrl,
                                type: 'GET',
                                success: function(response) {
                                    if (response.ready && response.access_token) {
                                        // Token is ready - validate and save it
                                        $status.html('<span style="color: #0073aa;">Validando token...</span>');
                                        
                                        // Send token to WordPress for validation and storage
                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'superfrete_oauth_callback',
                                                token: response.access_token,
                                                session_id: sessionId,
                                                nonce: '<?php echo wp_create_nonce('superfrete_oauth_nonce'); ?>'
                                            },
                                            success: function(wpResponse) {
                                                if (wpResponse.success) {
                                                    oauthSuccessful = true; // Mark OAuth as successful
                                                    $status.html('<span style="color: #46b450;">✓ ' + wpResponse.data.message + '</span>');
                                                    $button.prop('disabled', false).text('Reconectar');
                                                    
                                                    var userInfo = wpResponse.data.user_info;
                                                    var successMsg = 'SuperFrete conectado com sucesso!';
                                                    if (userInfo && userInfo.name) {
                                                        successMsg += '<br>Usuário: ' + userInfo.name;
                                                        if (userInfo.email) {
                                                            successMsg += ' (' + userInfo.email + ')';
                                                        }
                                                        if (userInfo.balance !== undefined) {
                                                            successMsg += '<br>Saldo: R$ ' + parseFloat(userInfo.balance).toFixed(2);
                                                        }
                                                    }
                                                    if (wpResponse.data.webhook_registered) {
                                                        successMsg += '<br>✅ Webhooks registrados automaticamente';
                                                    }
                                                    
                                                    $('<div class="notice notice-success is-dismissible"><p>' + successMsg + '</p></div>')
                                                        .insertAfter('.wrap h1');
                                                        
                                                    setTimeout(function() {
                                                        location.reload();
                                                    }, 3000);
                                                } else {
                                                    $status.html('<span style="color: #dc3232;">✗ Erro: ' + wpResponse.data + '</span>');
                                                    $button.prop('disabled', false).text('Tentar Novamente');
                                                }
                                            },
                                            error: function(xhr, status, error) {
                                                $status.html('<span style="color: #dc3232;">✗ Erro de comunicação: ' + error + '</span>');
                                                $button.prop('disabled', false).text('Tentar Novamente');
                                            }
                                        });
                                        
                                        popup.close();
                                    } else if (!response.ready) {
                                        // Token not ready yet, continue polling
                                        setTimeout(pollForTokenAjax, 2000);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    if (xhr.status === 404) {
                                        // Session not found or expired
                                        $status.html('<span style="color: #dc3232;">✗ Sessão expirada. Tente novamente.</span>');
                                        $button.prop('disabled', false).text('Tentar Novamente');
                                        popup.close();
                                    } else {
                                        // Continue polling on other errors
                                        setTimeout(pollForTokenAjax, 2000);
                                    }
                                }
                            });
                        };
                        
                        // Handle popup closed manually
                        var pollingActive = true;
                        var oauthSuccessful = false; // Track if OAuth was successful
                        var manualClose = false; // Track if popup was closed manually
                        var checkClosed = setInterval(function() {
                            if (popup.closed) {
                                clearInterval(checkClosed);
                                pollingActive = false;
                                window.removeEventListener('message', sessionListener);
                                
                                // Only show "Conexão cancelada" if the popup was closed manually
                                // and OAuth wasn't successful
                                if (manualClose && !oauthSuccessful) {
                                    $button.prop('disabled', false).text(originalText);
                                    $status.html('<span style="color: #dc3232;">Conexão cancelada</span>');
                                }
                            }
                        }, 1000);
                        
                        // Track manual popup close
                        popup.onbeforeunload = function() {
                            manualClose = true;
                        };
                        
                        // Update polling function to check if still active
                        var originalPollForToken = pollForToken;
                        pollForToken = function() {
                            if (!pollingActive) {
                                return;
                            }
                            originalPollForToken();
                        };
                    });
                    
                    // Handle visual customization settings
                    function updateCSSVariables() {
                        var primaryColor = $('#superfrete_custom_primary_color').val();
                        var errorColor = $('#superfrete_custom_error_color').val();
                        var fontSize = $('#superfrete_custom_font_size').val();
                        var borderRadius = $('#superfrete_custom_border_radius').val();
                        
                        // Background and text colors
                        var bgColor = $('#superfrete_custom_bg_color').val();
                        var resultsBgColor = $('#superfrete_custom_results_bg_color').val();
                        var textColor = $('#superfrete_custom_text_color').val();
                        var textLightColor = $('#superfrete_custom_text_light_color').val();
                        var borderColor = $('#superfrete_custom_border_color').val();
                        
                        // Update preview immediately
                        updatePreview(primaryColor, errorColor, fontSize, borderRadius, bgColor, resultsBgColor, textColor, textLightColor, borderColor);
                        
                        // Save to database via AJAX
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'superfrete_save_customization',
                                nonce: '<?php echo wp_create_nonce('superfrete_customization_nonce'); ?>',
                                primary_color: primaryColor,
                                error_color: errorColor,
                                font_size: fontSize,
                                border_radius: borderRadius,
                                bg_color: bgColor,
                                results_bg_color: resultsBgColor,
                                text_color: textColor,
                                text_light_color: textLightColor,
                                border_color: borderColor
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Silently saved - no notification needed for real-time updates
                                    console.log('Customization saved successfully');
                                } else {
                                    console.error('Error saving customization:', response.data);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX error:', error);
                            }
                        });
                    }
                    
                    // Function to update the preview in real-time
                    function updatePreview(primaryColor, errorColor, fontSize, borderRadius, bgColor, resultsBgColor, textColor, textLightColor, borderColor) {
                        // Helper function to darken color
                        function darkenColor(color, percent) {
                            var num = parseInt(color.replace('#', ''), 16),
                                amt = Math.round(2.55 * percent),
                                R = (num >> 16) - amt,
                                G = (num >> 8 & 0x00FF) - amt,
                                B = (num & 0x0000FF) - amt;
                            return '#' + (0x1000000 + (R < 255 ? R < 1 ? 0 : R : 255) * 0x10000 +
                                (G < 255 ? G < 1 ? 0 : G : 255) * 0x100 +
                                (B < 255 ? B < 1 ? 0 : B : 255)).toString(16).slice(1);
                        }
                        
                        // Helper function to lighten color
                        function lightenColor(color, percent) {
                            var num = parseInt(color.replace('#', ''), 16),
                                amt = Math.round(2.55 * percent),
                                R = (num >> 16) + amt,
                                G = (num >> 8 & 0x00FF) + amt,
                                B = (num & 0x0000FF) + amt;
                            return '#' + (0x1000000 + (R < 255 ? R < 1 ? 0 : R : 255) * 0x10000 +
                                (G < 255 ? G < 1 ? 0 : G : 255) * 0x100 +
                                (B < 255 ? B < 1 ? 0 : B : 255)).toString(16).slice(1);
                        }
                        
                        // Helper function to scale size
                        function scaleSize(size, multiplier) {
                            var numericValue = parseFloat(size);
                            var unit = size.replace(numericValue, '');
                            return (numericValue * multiplier) + unit;
                        }
                        
                        // Generate comprehensive CSS variables with all necessary variables
                        var css = '#super-frete-shipping-calculator-preview {' +
                            // Primary colors
                            '--superfrete-primary-color: ' + primaryColor + ';' +
                            '--superfrete-primary-hover: ' + darkenColor(primaryColor, 10) + ';' +
                            '--superfrete-error-color: ' + errorColor + ';' +
                            
                            // Background colors
                            '--superfrete-bg-color: ' + bgColor + ';' +
                            '--superfrete-bg-white: ' + resultsBgColor + ';' +
                            '--superfrete-bg-light: ' + lightenColor(bgColor, 5) + ';' +
                            
                            // Text colors
                            '--superfrete-text-color: ' + textColor + ';' +
                            '--superfrete-text-light: ' + textLightColor + ';' +
                            '--superfrete-heading-color: ' + textColor + ';' +
                            
                            // Border colors
                            '--superfrete-border-color: ' + borderColor + ';' +
                            '--superfrete-border-light: ' + lightenColor(borderColor, 10) + ';' +
                            '--superfrete-border-dark: ' + darkenColor(borderColor, 15) + ';' +
                            
                            // Typography
                            '--superfrete-font-size-base: ' + fontSize + ';' +
                            '--superfrete-font-size-small: ' + scaleSize(fontSize, 0.85) + ';' +
                            '--superfrete-font-size-large: ' + scaleSize(fontSize, 1.15) + ';' +
                            '--superfrete-font-family: Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' +
                            '--superfrete-line-height: 1.5;' +
                            
                            // Border radius
                            '--superfrete-radius-sm: ' + borderRadius + ';' +
                            '--superfrete-radius-md: ' + scaleSize(borderRadius, 1.5) + ';' +
                            '--superfrete-radius-lg: ' + scaleSize(borderRadius, 2) + ';' +
                            
                            // Spacing (essential for layout)
                            '--superfrete-spacing-xs: 4px;' +
                            '--superfrete-spacing-sm: 8px;' +
                            '--superfrete-spacing-md: 12px;' +
                            '--superfrete-spacing-lg: 16px;' +
                            '--superfrete-spacing-xl: 24px;' +
                            
                            // Shadows
                            '--superfrete-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);' +
                            '--superfrete-shadow-md: 0 2px 6px rgba(0, 0, 0, 0.1);' +
                            
                            // Z-index
                            '--superfrete-z-base: 1;' +
                            '--superfrete-z-overlay: 100;' +
                            '--superfrete-z-loading: 101;' +
                            
                            // Animation
                            '--superfrete-transition-fast: 0.15s ease;' +
                            '--superfrete-transition-normal: 0.3s ease;' +
                        '}';
                        
                        // Update the preview styles
                        $('#superfrete-preview-styles').html(css);
                    }
                    
                    // Debounce function to prevent excessive updates
                    function debounce(func, wait) {
                        let timeout;
                        return function executedFunction(...args) {
                            const later = () => {
                                clearTimeout(timeout);
                                func(...args);
                            };
                            clearTimeout(timeout);
                            timeout = setTimeout(later, wait);
                        };
                    }
                    
                    // Create debounced update function
                    const debouncedUpdateCSS = debounce(function() {
                        // Save current scroll position
                        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
                        
                        // Update CSS
                        updateCSSVariables();
                        
                        // Restore scroll position
                        window.scrollTo(scrollLeft, scrollTop);
                    }, 300);
                    
                    // Auto-save on color picker changes
                    $('#superfrete_custom_primary_color, #superfrete_custom_error_color, #superfrete_custom_bg_color, #superfrete_custom_results_bg_color, #superfrete_custom_text_color, #superfrete_custom_text_light_color, #superfrete_custom_border_color').on('change', function() {
                        updateCSSVariables();
                    });
                    
                    // Disable WordPress Iris color picker and use HTML5 color inputs
                    $('input[type="color"]').each(function() {
                        var $this = $(this);
                        // Remove any WordPress color picker initialization
                        if ($this.hasClass('wp-color-picker')) {
                            $this.wpColorPicker('destroy');
                        }
                        // Remove wp-color-picker class to prevent auto-initialization
                        $this.removeClass('wp-color-picker');
                    });
                    
                    // Auto-save on dropdown changes
                    $('#superfrete_custom_font_size, #superfrete_custom_border_radius').on('change', function() {
                        updateCSSVariables();
                    });
                    
                    // Theme preset handlers
                    $('#superfrete-preset-light').on('click', function() {
                        // Light theme preset
                        $('#superfrete_custom_bg_color').val('#ffffff').trigger('change');
                        $('#superfrete_custom_results_bg_color').val('#ffffff').trigger('change');
                        $('#superfrete_custom_text_color').val('#1a1a1a').trigger('change');
                        $('#superfrete_custom_text_light_color').val('#777777').trigger('change');
                        $('#superfrete_custom_border_color').val('#e0e0e0').trigger('change');
                        
                        // Force update of color picker UI
                        setTimeout(function() {
                            updateCSSVariables();
                        }, 100);
                    });
                    
                    $('#superfrete-preset-dark').on('click', function() {
                        // Dark theme preset
                        $('#superfrete_custom_bg_color').val('#2a2a2a').trigger('change');
                        $('#superfrete_custom_results_bg_color').val('#333333').trigger('change');
                        $('#superfrete_custom_text_color').val('#ffffff').trigger('change');
                        $('#superfrete_custom_text_light_color').val('#cccccc').trigger('change');
                        $('#superfrete_custom_border_color').val('#555555').trigger('change');
                        
                        // Force update of color picker UI
                        setTimeout(function() {
                            updateCSSVariables();
                        }, 100);
                    });
                    
                    $('#superfrete-preset-auto').on('click', function() {
                        // Auto-detect theme from page background
                        var bodyBg = $('body').css('background-color');
                        var isLightTheme = true;
                        
                        // Simple light/dark detection
                        if (bodyBg && bodyBg !== 'rgba(0, 0, 0, 0)') {
                            var rgb = bodyBg.match(/\\d+/g);
                            if (rgb && rgb.length >= 3) {
                                var brightness = (parseInt(rgb[0]) * 299 + parseInt(rgb[1]) * 587 + parseInt(rgb[2]) * 114) / 1000;
                                isLightTheme = brightness > 128;
                            }
                        }
                        
                        if (isLightTheme) {
                            $('#superfrete-preset-light').click();
                        } else {
                            $('#superfrete-preset-dark').click();
                        }
                    });
                    
                    // Handle reset customization
                    $('#superfrete-reset-customization').on('click', function() {
                        if (confirm('Tem certeza que deseja resetar todas as personalizações visuais?')) {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'superfrete_reset_customization',
                                    nonce: '<?php echo wp_create_nonce('superfrete_customization_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        // Reset form values to defaults and trigger change events
                                        $('#superfrete_custom_primary_color').val('#0fae79').trigger('change');
                                        $('#superfrete_custom_error_color').val('#e74c3c').trigger('change');
                                        $('#superfrete_custom_font_size').val('14px').trigger('change');
                                        $('#superfrete_custom_border_radius').val('4px').trigger('change');
                                        $('#superfrete_custom_bg_color').val('#ffffff').trigger('change');
                                        $('#superfrete_custom_results_bg_color').val('#ffffff').trigger('change');
                                        $('#superfrete_custom_text_color').val('#1a1a1a').trigger('change');
                                        $('#superfrete_custom_text_light_color').val('#777777').trigger('change');
                                        $('#superfrete_custom_border_color').val('#e0e0e0').trigger('change');
                                        
                                        // Update preview with default values
                                        updatePreview('#0fae79', '#e74c3c', '14px', '4px', '#ffffff', '#ffffff', '#1a1a1a', '#777777', '#e0e0e0');
                                        
                                        // Show success notification
                                        $('<div class="notice notice-success is-dismissible"><p>Personalização resetada com sucesso!</p></div>')
                                            .insertAfter('.wrap h1').delay(3000).fadeOut();
                                    } else {
                                        console.error('Error resetting customization:', response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('AJAX error:', error);
                                }
                            });
                        }
                    });
                    
                    // Initialize preview with current values
                    function initializePreview() {
                        var primaryColor = $('#superfrete_custom_primary_color').val() || '#0fae79';
                        var errorColor = $('#superfrete_custom_error_color').val() || '#e74c3c';
                        var fontSize = $('#superfrete_custom_font_size').val() || '14px';
                        var borderRadius = $('#superfrete_custom_border_radius').val() || '4px';
                        var bgColor = $('#superfrete_custom_bg_color').val() || '#ffffff';
                        var resultsBgColor = $('#superfrete_custom_results_bg_color').val() || '#ffffff';
                        var textColor = $('#superfrete_custom_text_color').val() || '#1a1a1a';
                        var textLightColor = $('#superfrete_custom_text_light_color').val() || '#777777';
                        var borderColor = $('#superfrete_custom_border_color').val() || '#e0e0e0';
                        
                        updatePreview(primaryColor, errorColor, fontSize, borderRadius, bgColor, resultsBgColor, textColor, textLightColor, borderColor);
                    }
                    
                    // Initialize preview on page load
                    initializePreview();
                    
                    console.log('SuperFrete admin scripts loaded successfully!');
                });
                </script>
                <?php
            });
        }
    }
    
    /**
     * Check if we're on a WooCommerce settings page
     */
    private static function is_woocommerce_settings_page() {
        global $pagenow;
        
        // Check URL parameters
        $page = $_GET['page'] ?? '';
        $tab = $_GET['tab'] ?? '';
        
        return (
            $pagenow === 'admin.php' && 
            $page === 'wc-settings' && 
            ($tab === 'shipping' || $tab === '')
        );
    }

    /**
     * Render custom webhook status field
     */
    public static function render_webhook_status_field($field) {
        $webhook_status = $field['webhook_status'];
        $css_class = isset($field['class']) ? $field['class'] : '';
        ?>
        <tr valign="top" class="<?php echo esc_attr($css_class); ?>">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['title']); ?></label>
            </th>
            <td class="forminp">
                <div style="<?php echo esc_attr($webhook_status['css']); ?>">
                    <?php if (strpos($webhook_status['message'], 'Registrados e Ativos') !== false): ?>
                        ✅ Webhooks Registrados e Ativos
                        <button type="button" id="superfrete-register-webhook" class="button button-secondary" style="margin-left: 10px;">
                            Reregistrar
                        </button>
                    <?php else: ?>
                        ❌ Webhooks Não Registrados
                        <button type="button" id="superfrete-register-webhook" class="button button-primary" style="margin-left: 10px;">
                            Registrar Agora
                        </button>
                    <?php endif; ?>
                </div>
                <p class="description">Status atual dos webhooks do SuperFrete.</p>
            </td>
        </tr>
        <?php
    }

    /**
     * Render custom preview field
     */
    public static function render_preview_field($field) {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['title']); ?></label>
            </th>
            <td class="forminp">
                <?php echo self::render_preview_html(); ?>
                <p class="description"><?php echo esc_html($field['desc']); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Get webhook status information
     */
    public static function get_webhook_status() {
        $is_registered = get_option('superfrete_webhook_registered') === 'yes';
        $webhook_url = get_option('superfrete_webhook_url');
        
        if ($is_registered && $webhook_url) {
            return [
                'message' => 'Webhooks Registrados e Ativos',
                'css' => 'color: #008000; font-weight: bold;'
            ];
        } else {
            return [
                'message' => 'Webhooks Não Registrados',
                'css' => 'color: #cc0000; font-weight: bold;'
            ];
        }
    }

    /**
     * Handle webhook registration AJAX request
     */
    public static function handle_webhook_registration() {
        // Log the request for debugging
        Logger::log('SuperFrete', 'Webhook registration AJAX request received');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superfrete_webhook_nonce')) {
            Logger::log('SuperFrete', 'Webhook registration failed: Invalid nonce');
            wp_send_json_error('Falha na verificação de segurança');
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            Logger::log('SuperFrete', 'Webhook registration failed: Insufficient permissions');
            wp_send_json_error('Permissões insuficientes');
            return;
        }

        try {
            $webhook_url = rest_url('superfrete/v1/webhook');
            Logger::log('SuperFrete', 'Attempting webhook registration for URL: ' . $webhook_url);
            
            $request = new Request();
            
            // Try to register webhook
            $result = $request->register_webhook($webhook_url);
            
            if ($result) {
                Logger::log('SuperFrete', 'Webhook registration successful: ' . wp_json_encode($result));
                wp_send_json_success('Webhook registrado com sucesso!');
            } else {
                Logger::log('SuperFrete', 'Webhook registration failed: No result returned');
                wp_send_json_error('Falha ao registrar webhook. Verifique suas credenciais da API e conexão.');
            }
        } catch (Exception $e) {
            Logger::log('SuperFrete', 'Webhook registration exception: ' . $e->getMessage());
            wp_send_json_error('Erro: ' . $e->getMessage());
        } catch (Error $e) {
            Logger::log('SuperFrete', 'Webhook registration error: ' . $e->getMessage());
            wp_send_json_error('Erro interno: ' . $e->getMessage());
        }
    }

    /**
     * Handle OAuth callback AJAX request
     */
    public static function handle_oauth_callback() {
        // Verify nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superfrete_oauth_nonce')) {
            wp_send_json_error('Falha na verificação de segurança');
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permissões insuficientes');
            return;
        }

        // Get the token from the request
        $token = sanitize_text_field($_POST['token'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (empty($token)) {
            wp_send_json_error('Token não fornecido');
            return;
        }

        try {
            // Determine which environment we're using
            $sandbox_enabled = get_option('superfrete_sandbox_mode') === 'yes';
            $site_url = get_site_url();
            $is_dev_site = (
                strpos($site_url, 'localhost') !== false || 
                strpos($site_url, '.local') !== false ||
                (
                    strpos($site_url, 'dev.') !== false && 
                    strpos($site_url, 'dev.wordpress.superintegrador.superfrete.com') === false
                )
            );
            $use_dev_env = ($sandbox_enabled || $is_dev_site);
            $option_key = $use_dev_env ? 'superfrete_api_token_sandbox' : 'superfrete_api_token';
            $old_token = get_option($option_key);
            
            // Temporarily set the token to test it
            update_option($option_key, $token);
            
            // Validate token using superfrete-api package
            $request = new Request();
            
            // Add detailed logging before API call
            Logger::log('SuperFrete', 'About to validate token with API call to /api/v0/user');
            Logger::log('SuperFrete', 'Token being validated: ' . substr($token, 0, 20) . '...');
            Logger::log('SuperFrete', 'Environment: ' . ($use_dev_env ? 'dev' : 'production'));
            Logger::log('SuperFrete', 'Option key: ' . $option_key);
            
            $response = $request->call_superfrete_api('/api/v0/user', 'GET', [], true);
            
            // Log the response
            Logger::log('SuperFrete', 'API response received: ' . wp_json_encode($response));
            Logger::log('SuperFrete', 'Response has id: ' . (isset($response['id']) ? 'yes' : 'no'));
            Logger::log('SuperFrete', 'Response is truthy: ' . ($response ? 'yes' : 'no'));
            
            if ($response && isset($response['id'])) {
                // Token is valid, keep it
                Logger::log('SuperFrete', 'OAuth token validated successfully for user: ' . ($response['firstname'] ?? 'Unknown'));
                
                // Register webhooks automatically after successful token validation
                try {
                    $webhook_url = rest_url('superfrete/v1/webhook');
                    $webhook_result = $request->register_webhook($webhook_url);
                    
                    if ($webhook_result) {
                        Logger::log('SuperFrete', 'Webhook registered automatically after OAuth: ' . wp_json_encode($webhook_result));
                        update_option('superfrete_webhook_registered', 'yes');
                        update_option('superfrete_webhook_url', $webhook_url);
                    } else {
                        Logger::log('SuperFrete', 'Webhook registration failed after OAuth');
                    }
                } catch (Exception $webhook_error) {
                    Logger::log('SuperFrete', 'Webhook registration error after OAuth: ' . $webhook_error->getMessage());
                    // Don't fail the OAuth process if webhook registration fails
                }
                
                wp_send_json_success([
                    'message' => 'Token OAuth obtido e validado com sucesso!',
                    'user_info' => [
                        'name' => ($response['firstname'] ?? '') . ' ' . ($response['lastname'] ?? ''),
                        'email' => $response['email'] ?? '',
                        'id' => $response['id'] ?? '',
                        'balance' => $response['balance'] ?? 0,
                        'limits' => $response['limits'] ?? []
                    ],
                    'webhook_registered' => isset($webhook_result) && $webhook_result ? true : false
                ]);
            } else {
                // Token is invalid, restore old token
                update_option($option_key, $old_token);
                Logger::log('SuperFrete', 'OAuth token validation failed');
                wp_send_json_error('Token inválido ou expirado');
            }
        } catch (Exception $e) {
            // Restore old token on error
            if (isset($old_token)) {
                update_option($option_key, $old_token);
            }
            Logger::log('SuperFrete', 'OAuth token validation error: ' . $e->getMessage());
            wp_send_json_error('Erro ao validar token: ' . $e->getMessage());
        }
    }

    /**
     * Handle save customization AJAX request
     */
    public static function handle_save_customization() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superfrete_customization_nonce')) {
            wp_send_json_error('Falha na verificação de segurança');
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permissões insuficientes');
            return;
        }

        try {
            // Get and sanitize input values
            $primary_color = sanitize_hex_color($_POST['primary_color'] ?? '#0fae79');
            $error_color = sanitize_hex_color($_POST['error_color'] ?? '#e74c3c');
            $font_size = sanitize_text_field($_POST['font_size'] ?? '14px');
            $border_radius = sanitize_text_field($_POST['border_radius'] ?? '4px');
            
            // Background and text colors
            $bg_color = sanitize_hex_color($_POST['bg_color'] ?? '#ffffff');
            $results_bg_color = sanitize_hex_color($_POST['results_bg_color'] ?? '#ffffff');
            $text_color = sanitize_hex_color($_POST['text_color'] ?? '#1a1a1a');
            $text_light_color = sanitize_hex_color($_POST['text_light_color'] ?? '#666666');
            $border_color = sanitize_hex_color($_POST['border_color'] ?? '#e0e0e0');

            // Validate values
            $valid_font_sizes = ['12px', '14px', '16px', '18px'];
            $valid_border_radius = ['0px', '2px', '4px', '8px', '12px'];
            
            if (!in_array($font_size, $valid_font_sizes)) {
                $font_size = '14px';
            }
            if (!in_array($border_radius, $valid_border_radius)) {
                $border_radius = '4px';
            }

            // Build comprehensive CSS variables array
            $css_variables = array(
                // Primary colors and variations
                '--superfrete-primary-color' => $primary_color,
                '--superfrete-primary-hover' => self::darken_color($primary_color, 10),
                '--superfrete-error-color' => $error_color,
                
                // Background colors
                '--superfrete-bg-color' => $bg_color,
                '--superfrete-bg-white' => $results_bg_color,
                '--superfrete-bg-light' => self::lighten_color($bg_color, 5),
                
                // Text colors
                '--superfrete-text-color' => $text_color,
                '--superfrete-text-light' => $text_light_color,
                '--superfrete-heading-color' => $text_color,
                
                // Border colors
                '--superfrete-border-color' => $border_color,
                '--superfrete-border-light' => self::lighten_color($border_color, 10),
                '--superfrete-border-dark' => self::darken_color($border_color, 15),
                
                // Interactive element colors (use primary color)
                '--superfrete-interactive-color' => $primary_color,
                '--superfrete-interactive-hover' => self::darken_color($primary_color, 10),
                
                // Typography
                '--superfrete-font-size-base' => $font_size,
                '--superfrete-font-size-small' => self::scale_size($font_size, 0.85),
                '--superfrete-font-size-large' => self::scale_size($font_size, 1.15),
                
                // Border radius
                '--superfrete-radius-sm' => $border_radius,
                '--superfrete-radius-md' => self::scale_size($border_radius, 1.5),
                '--superfrete-radius-lg' => self::scale_size($border_radius, 2),
                
                // Derived colors based on primary color for better theming
                '--superfrete-focus-color' => $primary_color,
                '--superfrete-accent-color' => $primary_color,
            );

            // Save to database
            update_option('superfrete_custom_css_vars', $css_variables);

            wp_send_json_success('Personalização salva com sucesso!');
        } catch (Exception $e) {
            wp_send_json_error('Erro ao salvar personalização: ' . $e->getMessage());
        }
    }

    /**
     * Handle reset customization AJAX request
     */
    public static function handle_reset_customization() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superfrete_customization_nonce')) {
            wp_send_json_error('Falha na verificação de segurança');
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permissões insuficientes');
            return;
        }

        try {
            // Remove custom CSS variables from database
            delete_option('superfrete_custom_css_vars');

            wp_send_json_success('Personalização resetada com sucesso!');
        } catch (Exception $e) {
            wp_send_json_error('Erro ao resetar personalização: ' . $e->getMessage());
        }
    }

    /**
     * Helper function to darken a color
     */
    private static function darken_color($color, $percent) {
        // Remove # if present
        $color = str_replace('#', '', $color);
        
        // Convert to RGB
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        
        // Darken by percent
        $r = max(0, min(255, $r - ($r * $percent / 100)));
        $g = max(0, min(255, $g - ($g * $percent / 100)));
        $b = max(0, min(255, $b - ($b * $percent / 100)));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Helper function to scale a size value
     */
    private static function scale_size($size, $multiplier) {
        $numeric_value = floatval($size);
        $unit = str_replace($numeric_value, '', $size);
        return ($numeric_value * $multiplier) . $unit;
    }

    /**
     * Helper function to lighten a color
     */
    private static function lighten_color($color, $percent) {
        // Remove # if present
        $color = str_replace('#', '', $color);
        
        // Convert to RGB
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        
        // Lighten by percent
        $r = min(255, max(0, $r + (255 - $r) * $percent / 100));
        $g = min(255, max(0, $g + (255 - $g) * $percent / 100));
        $b = min(255, max(0, $b + (255 - $b) * $percent / 100));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Render preview HTML for the freight calculator
     */
    private static function render_preview_html() {
        ob_start();
        ?>
        <div id="superfrete-preview-container" style="margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
            <h4 style="margin-top: 0;">Pré-visualização da Calculadora</h4>
            <div id="superfrete-preview-calculator" style="max-width: 500px;">
                <!-- Exact replica of the real calculator structure -->
                <div id="super-frete-shipping-calculator-preview" class="superfrete-calculator-wrapper">
                    <!-- CEP Input Section - Always Visible -->
                    <div class="superfrete-input-section">
                        <div class="form-row form-row-wide" id="calc_shipping_postcode_field">
                            <input type="text" class="input-text" value="22775-360" 
                                   placeholder="Digite seu CEP (00000-000)" 
                                   readonly style="pointer-events: none;" />
                        </div>
                    </div>

                    <!-- Results Section -->
                    <div id="superfrete-results-container-preview" class="superfrete-results-container">
                        <div class="superfrete-shipping-methods">
                            <h3>Opções de Entrega</h3>
                            <div class="superfrete-shipping-method">
                                <div class="superfrete-shipping-method-name">PAC - (5 dias úteis)</div>
                                <div class="superfrete-shipping-method-price">R$ 20,78</div>
                            </div>
                            <div class="superfrete-shipping-method">
                                <div class="superfrete-shipping-method-name">SEDEX - (1 dia útil)</div>
                                <div class="superfrete-shipping-method-price">R$ 13,20</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p style="font-style: italic; color: #666; margin-bottom: 0;">
                Esta é uma pré-visualização. As alterações são aplicadas automaticamente conforme você modifica as configurações acima.
            </p>
        </div>

        <!-- Ensure the preview gets the same CSS as the real calculator -->
        <style id="superfrete-preview-base-styles">
            /* Copy the calculator styles for preview */
            #super-frete-shipping-calculator-preview {
                background-color: var(--superfrete-bg-color);
                padding: var(--superfrete-spacing-lg);
                border-radius: var(--superfrete-radius-lg);
                margin-bottom: var(--superfrete-spacing-lg);
                max-width: 500px;
                box-shadow: var(--superfrete-shadow-sm);
                font-size: var(--superfrete-font-size-base);
                line-height: var(--superfrete-line-height);
                font-family: var(--superfrete-font-family);
                color: var(--superfrete-text-color);
            }

            #super-frete-shipping-calculator-preview .superfrete-input-section {
                margin-bottom: var(--superfrete-spacing-md);
            }

            #super-frete-shipping-calculator-preview #calc_shipping_postcode_field input {
                width: 100%;
                padding: var(--superfrete-spacing-sm) var(--superfrete-spacing-md);
                border: 1px solid var(--superfrete-border-color);
                border-radius: var(--superfrete-radius-sm);
                font-size: var(--superfrete-font-size-base);
                transition: all 0.3s ease;
                letter-spacing: 0.5px;
                font-weight: 500;
                font-family: var(--superfrete-font-family);
                color: var(--superfrete-text-color);
                background-color: var(--superfrete-bg-white);
                box-sizing: border-box;
            }

            #super-frete-shipping-calculator-preview .superfrete-results-container {
                background-color: var(--superfrete-bg-white);
                border: 1px solid var(--superfrete-border-light);
                border-radius: var(--superfrete-radius-sm);
                padding: var(--superfrete-spacing-md);
                box-shadow: var(--superfrete-shadow-sm);
            }

            #super-frete-shipping-calculator-preview .superfrete-shipping-methods h3 {
                font-size: var(--superfrete-font-size-base);
                margin-bottom: var(--superfrete-spacing-sm);
                border-bottom: 1px solid var(--superfrete-border-light);
                padding-bottom: var(--superfrete-spacing-sm);
                color: var(--superfrete-heading-color);
                font-weight: 600;
                margin-top: 0;
            }

            #super-frete-shipping-calculator-preview .superfrete-shipping-method {
                display: flex;
                justify-content: space-between;
                padding: var(--superfrete-spacing-sm) 0;
                border-bottom: 1px solid var(--superfrete-border-light);
                align-items: center;
            }

            #super-frete-shipping-calculator-preview .superfrete-shipping-method:last-child {
                border-bottom: none;
            }

            #super-frete-shipping-calculator-preview .superfrete-shipping-method-name {
                font-weight: 600;
                color: var(--superfrete-text-color);
                font-size: var(--superfrete-font-size-small);
                flex: 1;
            }

            #super-frete-shipping-calculator-preview .superfrete-shipping-method-price {
                font-weight: 600;
                color: var(--superfrete-primary-color);
                font-size: var(--superfrete-font-size-small);
                text-align: right;
            }
        </style>

        <style id="superfrete-preview-styles">
            /* Dynamic preview styles will be injected here by JavaScript */
        </style>
        <?php
        return ob_get_clean();
    }
}

// Executa a migração assim que o plugin for carregado
add_action('admin_init', ['SuperFrete_API\Admin\SuperFrete_Settings', 'migrate_old_settings']);

// Hook para adicionar a aba dentro de "Entrega"
add_filter('woocommerce_shipping_settings', ['SuperFrete_API\Admin\SuperFrete_Settings', 'add_superfrete_settings']);
add_action('admin_init', ['SuperFrete_API\Admin\SuperFrete_Settings', 'enqueue_admin_scripts']);

// AJAX hooks for webhook management
add_action('wp_ajax_superfrete_register_webhook', ['SuperFrete_API\Admin\SuperFrete_Settings', 'handle_webhook_registration']);
add_action('wp_ajax_superfrete_oauth_callback', ['SuperFrete_API\Admin\SuperFrete_Settings', 'handle_oauth_callback']);

// AJAX hooks for visual customization
add_action('wp_ajax_superfrete_save_customization', ['SuperFrete_API\Admin\SuperFrete_Settings', 'handle_save_customization']);
add_action('wp_ajax_superfrete_reset_customization', ['SuperFrete_API\Admin\SuperFrete_Settings', 'handle_reset_customization']);
