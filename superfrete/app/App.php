<?php

namespace SuperFrete_API;

use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Segurança para evitar acesso direto
}

class App
{

    /**
     * Construtor que inicializa o plugin
     */
    public function __construct()
    {
        $this->includes();
        add_action('plugins_loaded', [$this, 'init_plugin']);
        $this->register_ajax_actions();
        add_action('woocommerce_shipping_init', function () {
            if (class_exists('WC_Shipping_Method')) {
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteBase.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFretePAC.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteSEDEX.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteMiniEnvio.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteJadlog.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteLoggi.php';
            }
        });

        add_filter('woocommerce_shipping_methods', function ($methods) {
            // Register all individual shipping methods
            $methods['superfrete_pac'] = '\SuperFrete_API\Shipping\SuperFretePAC';
            $methods['superfrete_sedex'] = '\SuperFrete_API\Shipping\SuperFreteSEDEX';
            $methods['superfrete_mini_envio'] = '\SuperFrete_API\Shipping\SuperFreteMiniEnvios';
            $methods['superfrete_jadlog'] = '\SuperFrete_API\Shipping\SuperFreteJadlog';
            $methods['superfrete_loggi'] = '\SuperFrete_API\Shipping\SuperFreteLoggi';
            
            // Remove the consolidated method if it exists
            unset($methods['superfrete_shipping']);
            
            return $methods;
        });


    }

    /**
     * Inclui os arquivos necessários do plugin
     */
    private function includes()
    {
        require_once plugin_dir_path(__FILE__) . 'Controllers/Admin/SuperFrete_Settings.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/Admin/Admin_Menu.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/Admin/WebhookAdmin.php';
        require_once plugin_dir_path(__FILE__) . '../api/Http/Request.php';
        require_once plugin_dir_path(__FILE__) . '../api/Http/WebhookVerifier.php';
        require_once plugin_dir_path(__FILE__) . '../api/Helpers/Logger.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/ProductShipping.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/SuperFrete_Order.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/Admin/SuperFrete_OrderActions.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/WebhookController.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/WebhookRetryManager.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/OAuthController.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/DocumentFields.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/CheckoutFields.php';
        require_once plugin_dir_path(__FILE__) . 'Helpers/AddressHelper.php';
        require_once plugin_dir_path(__FILE__) . 'Helpers/ShippingMigration.php';
        require_once plugin_dir_path(__FILE__) . 'Helpers/SuperFrete_Notice.php';
        
        // Include database migrations if file exists
        $migrations_file = plugin_dir_path(__FILE__) . '../database/webhook_migrations.php';
        if (file_exists($migrations_file)) {
            require_once $migrations_file;
        }
    }

    /**
     * Check if WooFunnels Aero Checkout is active
     */
    private function is_woofunnels_checkout_active()
    {
        return class_exists('WFACP_Common') || class_exists('WFACP_Template_Common');
    }

    /**
     * Inicializa o plugin e adiciona suas funcionalidades
     */
    public function init_plugin()
    {

        new \SuperFrete_API\Admin\SuperFrete_OrderActions();
        new \SuperFrete_API\Admin\SuperFrete_Settings();
        new \SuperFrete_API\Admin\WebhookAdmin();
        new \SuperFrete_API\Controllers\ProductShipping();
        if (class_exists('\SuperFrete_API\Admin\Admin_Menu')) {
            new \SuperFrete_API\Admin\Admin_Menu();
        }
        new \SuperFrete_API\Controllers\SuperFrete_Order();
        new \SuperFrete_API\Controllers\WebhookController();
        new \SuperFrete_API\Controllers\WebhookRetryManager();
        new \SuperFrete_API\Controllers\OAuthController();
        \SuperFrete_API\Helpers\Logger::init();
        
        // Initialize webhook database tables (if class exists)
        if (class_exists('\SuperFrete_API\Database\WebhookMigrations')) {
            \SuperFrete_API\Database\WebhookMigrations::run_migrations();
        }
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp', function () {
            if (!wp_next_scheduled('superfrete_clear_log_event')) {
                wp_schedule_event(time(), 'every_five_days', 'superfrete_clear_log_event');
            }
        });
        add_filter('woocommerce_package_rates', [$this, 'ordenar_metodos_frete_por_preco'], 100, 2);
        
        // Also try hooking at an even later stage to ensure our sorting is final
        add_filter('woocommerce_shipping_package_rates', [$this, 'ordenar_metodos_frete_por_preco'], 999, 2);

        // Adiciona os campos 'Número' e 'Bairro' nas configurações da loja
        add_filter('woocommerce_general_settings', [$this, 'add_custom_store_address_fields']);

        add_filter('cron_schedules', function ($schedules) {
            $schedules['every_five_days'] = [
                'interval' => 5 * DAY_IN_SECONDS,
                'display' => __('A cada 5 dias')
            ];
            return $schedules;
        });

        if (!empty(get_option('woocommerce_store_postcode')) && (!empty(get_option('superfrete_api_token')) || (get_option('superfrete_sandbox_mode') === 'yes' && !empty(get_option('superfrete_api_token_sandbox'))))) {
            new \SuperFrete_API\Controllers\ProductShipping();
        } else {
            add_action('admin_notices', [$this, 'superfrete_configs_setup_notice']);
        }
        add_action('superfrete_clear_log_event', function () {
            \SuperFrete_API\Helpers\Logger::clear_log();
            // Also cleanup old webhook logs (if class exists)
            if (class_exists('\SuperFrete_API\Database\WebhookMigrations')) {
                \SuperFrete_API\Database\WebhookMigrations::cleanup_old_logs();
            }
            // Clear old shipping cache entries
            if (class_exists('\SuperFrete_API\Shipping\SuperFreteBase')) {
                \SuperFrete_API\Shipping\SuperFreteBase::clear_cache();
            }
        });
        
        // Register custom order statuses
        add_action('init', [$this, 'register_custom_order_statuses']);
        
        // Run migration and create shipping zone after shipping methods are registered
        add_action('wp_loaded', function () {
            // Make sure WooCommerce is loaded
            if (!class_exists('WooCommerce') || !class_exists('WC_Shipping_Zones')) {
                return;
            }
            // Check if we need to force migration due to plugin update
            $current_version = get_option('superfrete_plugin_version', '0.0.0');
            $plugin_file = plugin_dir_path(__FILE__) . '../superfrete.php';
            
            if (file_exists($plugin_file)) {
                if (!function_exists('get_plugin_data')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $plugin_data = get_plugin_data($plugin_file);
                $new_version = $plugin_data['Version'] ?? '1.0.0';
                
                // If version changed, reset migration to force re-run
                if (version_compare($current_version, $new_version, '<')) {
                    delete_option('superfrete_shipping_migrated');
                    delete_option('superfrete_individual_methods_migrated'); // New migration flag
                    update_option('superfrete_plugin_version', $new_version);
                    Logger::log('SuperFrete', "Plugin updated from $current_version to $new_version - forcing migration");
                }
                
                // Force migration for individual methods (version 3.2.0+)
                if (version_compare($current_version, '3.2.0', '<') && version_compare($new_version, '3.2.0', '>=')) {
                    delete_option('superfrete_shipping_migrated');
                    delete_option('superfrete_individual_methods_migrated');
                    Logger::log('SuperFrete', "Forcing migration to individual shipping methods (v3.2.0)");
                }
            }
            
            // Delay migration to ensure shipping methods are registered
            // Only run migration once per request to avoid loops
            if (!get_transient('superfrete_migration_running')) {
                set_transient('superfrete_migration_running', true, 30); // 30 second lock
                \SuperFrete_API\Helpers\ShippingMigration::migrate_shipping_methods();
                delete_transient('superfrete_migration_running');
            }
            if (!class_exists('\WC_Shipping_Zones')) return;
        
            $zone_name = 'Brasil - SuperFrete';
            $existing_zones = \WC_Shipping_Zones::get_zones();
        
            foreach ($existing_zones as $zone) {
                if ($zone['zone_name'] === $zone_name) return;
            }
        
            $zone = new \WC_Shipping_Zone();
            $zone->set_zone_name($zone_name);
            $zone->save();
        
            $zone_id = $zone->get_id();
            $locations = [
                ['code' => 'BR', 'type' => 'country'],
            ];
        
            global $wpdb;
            foreach ($locations as $location) {
                $wpdb->insert("{$wpdb->prefix}woocommerce_zone_locations", [
                    'zone_id' => $zone_id,
                    'location_code' => $location['code'],
                    'location_type' => $location['type'],
                ]);
            }
            // Add all individual SuperFrete methods
            $method_ids = ['superfrete_pac', 'superfrete_sedex', 'superfrete_jadlog', 'superfrete_mini_envio', 'superfrete_loggi'];
            
            // Check if the shipping methods are registered
            $wc_shipping = \WC_Shipping::instance();
            $available_methods = $wc_shipping->get_shipping_methods();
            
            foreach ($method_ids as $method_id) {
                if (isset($available_methods[$method_id])) {
                    $instance_id = $zone->add_shipping_method($method_id);

                    // Get the method instance and enable it
                    $methods = $zone->get_shipping_methods();
                    foreach ($methods as $method) {
                        if ($method->id === $method_id && $method->get_instance_id() == $instance_id) {
                            $method->enabled = 'yes';
                            $method->update_option('enabled', 'yes');
                            $method->update_option('title', $method->method_title);
                            $method->save();
                            Logger::debug("Shipping method $method_id enabled in zone (Instance ID: $instance_id)", 'App');
                            break;
                        }
                    }
                } else {
                    Logger::debug("Shipping method $method_id not registered yet", 'App');
                }
            }

            Logger::debug('Zona de entrega "Brasil - SuperFrete" criada com o método ativado.', 'App');
        });
    }

    public function ordenar_metodos_frete_por_preco($rates, $package)
    {
        if (empty($rates))
            return $rates;

        // Log original order for debugging
        $original_order = [];
        foreach ($rates as $rate_id => $rate) {
            $original_order[] = $rate->label . ' - R$ ' . number_format(floatval($rate->cost), 2, ',', '.');
        }
        Logger::debug('Original shipping order: ' . implode(' | ', $original_order), 'Shipping');

        // Reordena os métodos de frete pelo valor (crescente - do menor para o maior)
        uasort($rates, function ($a, $b) {
            $cost_a = floatval($a->cost);
            $cost_b = floatval($b->cost);

            // Free shipping (cost = 0) should come first
            if ($cost_a == 0 && $cost_b > 0) return -1;
            if ($cost_b == 0 && $cost_a > 0) return 1;

            // Both free or both paid - sort by cost ascending
            if ($cost_a == $cost_b) {
                // If costs are equal, sort by delivery time (faster first)
                $time_a = isset($a->meta_data['delivery_time']) ? intval($a->meta_data['delivery_time']) : 999;
                $time_b = isset($b->meta_data['delivery_time']) ? intval($b->meta_data['delivery_time']) : 999;
                return $time_a <=> $time_b;
            }

            return $cost_a <=> $cost_b;
        });

        // Log sorted order for debugging
        $sorted_order = [];
        foreach ($rates as $rate_id => $rate) {
            $sorted_order[] = $rate->label . ' - R$ ' . number_format(floatval($rate->cost), 2, ',', '.');
        }
        Logger::debug('Sorted shipping order: ' . implode(' | ', $sorted_order), 'Shipping');

        return $rates;
    }


    function add_custom_store_address_fields($settings)
    {
        $new_settings = [];

        foreach ($settings as $setting) {
            $new_settings[] = $setting;

            // Após o campo de endereço 1
            if (isset($setting['id']) && $setting['id'] === 'woocommerce_store_address') {
                $new_settings[] = [
                    'title' => 'Número',
                    'desc_tip' => 'Número do endereço da loja',
                    'id' => 'woocommerce_store_number',
                    'type' => 'text',
                    'css' => 'min-width:300px;',
                    'default' => '',
                    'autoload' => false,
                ];
            }

            // Após o campo de cidade
            if (isset($setting['id']) && $setting['id'] === 'woocommerce_store_city') {
                $new_settings[] = [
                    'title' => 'Bairro',
                    'desc_tip' => 'Bairro da loja',
                    'id' => 'woocommerce_store_neighborhood',
                    'type' => 'text',
                    'css' => 'min-width:300px;',
                    'default' => '',
                    'autoload' => false,
                ];
            }
        }

        return $new_settings;
    }

    /**
     * Register custom order statuses for better tracking
     */
    public function register_custom_order_statuses()
    {
        // Register custom 'shipped' status
        register_post_status('wc-shipped', [
            'label' => 'Enviado',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Enviado <span class="count">(%s)</span>', 'Enviado <span class="count">(%s)</span>')
        ]);
        
        // Add custom statuses to WooCommerce order statuses
        add_filter('wc_order_statuses', function($order_statuses) {
            $order_statuses['wc-shipped'] = 'Enviado';
            return $order_statuses;
        });
    }

    public function superfrete_configs_setup_notice()
    {
        ?>
        <div class="error notice">
            <p><b>SuperFrete</b></p>
            <p>
                Para utilizar o plugin você deve
                <a
                    href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shipping&section=options#superfrete_settings_section-description')); ?>">
                    configurar seu acesso a SuperFrete
                </a>
                e configurar um
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings')); ?>">
                    endereço
                </a>.
            </p>
        </div>
        <?php
    }

    /**
     * Adiciona um link para as configurações na página de plugins.
     */
    public static function superfrete_add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=options#superfrete_settings_section-description') . '">Configurações</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // Criar a função que limpa os logs



    static function singleShippingCountry()
    {
        if (!function_exists('WC') || !is_object(WC()->countries))
            return false;

        $countries = WC()->countries->get_shipping_countries();

        if (count($countries) == 1) {
            foreach (WC()->countries->get_shipping_countries() as $key => $value) {
                return $key;
            }
        }

        return false;
    }

    public function enqueue_assets()
    {

        wp_localize_script(
            'jquery',
            'superfrete_setting',
            array(
                'wc_ajax_url' => \WC_AJAX::get_endpoint('%%endpoint%%'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'loading' => 'Loading..',
                'auto_select_country' => apply_filters('pisol_ppscw_auto_select_country', self::singleShippingCountry()),
                'load_location_by_ajax' => 1
            )
        );

        wp_enqueue_style('superfrete-popup-css', plugin_dir_url(__FILE__) . '../assets/styles/superfrete.css', [], '1.0');
        
        // Add shipping sorting script on checkout
        if (is_checkout()) {
            add_action('wp_footer', [$this, 'add_shipping_sorting_script']);

            // Enqueue document field script for checkout
            // Skip if WooFunnels Aero Checkout is active (they have their own masking)
            if (!$this->is_woofunnels_checkout_active()) {
                wp_enqueue_script(
                    'superfrete-document-field',
                    plugin_dir_url(__FILE__) . '../assets/js/document-field.js',
                    ['jquery'],
                    '1.0.0',
                    true
                );
            }
        }
        
        // Add theme customization support
        add_action('wp_head', [$this, 'add_theme_customization_styles'], 100);
        wp_enqueue_script(
            'superfrete-popup',
            plugin_dir_url(__FILE__) . '../assets/scripts/superfrete-popup.js',
            ['jquery'],
            '1.0.0', // Versão do script
            true
        );
        wp_localize_script('superfrete-popup', 'superfrete_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }

    public function register_ajax_actions()
    {
        add_action('wp_ajax_superfrete_update_address', [$this, 'handle_superfrete_update_address']);
        add_action('wp_ajax_nopriv_superfrete_update_address', [$this, 'handle_superfrete_update_address']);
    }

    // Criar a função que limpa os logs


    public function handle_superfrete_update_address()
    {
        // Verifica o nonce
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'superfrete_update_address_nonce')) {
            wp_send_json_error(['message' => 'Requisição inválida.'], 403);
        }

        if (!session_id()) {
            session_start();
        }

        if (!isset($_POST['order_id'])) {
            wp_send_json_error(['message' => 'ID do pedido ausente.'], 400);
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => 'Pedido não encontrado.'], 404);
        }

        $current_shipping = $order->get_address('shipping');

        $updated_data = [
            'first_name' => sanitize_text_field($_POST['name']),
            'address_1' => sanitize_text_field($_POST['address']),
            'address_2' => sanitize_text_field($_POST['complement']),
            'number' => sanitize_text_field($_POST['number']),
            'neighborhood' => sanitize_text_field($_POST['district']),
            'city' => sanitize_text_field($_POST['city']),
            'state' => sanitize_text_field($_POST['state_abbr']),
            'postcode' => sanitize_text_field($_POST['postal_code'])
        ];
        if (!empty($updated_data['number'])) {
            $order->update_meta_data('_shipping_number', $updated_data['number']);
        }
        if (!empty($updated_data['neighborhood'])) {
            $order->update_meta_data('_shipping_neighborhood', $updated_data['neighborhood']);
        }

        foreach ($updated_data as $key => $value) {
            if (empty($current_shipping[$key]) && !empty($value)) {
                $current_shipping[$key] = $value;
            }
        }
        $order->set_address($current_shipping, 'shipping');
        $order->save();
        $_SESSION['superfrete_correction'][$order_id] = $current_shipping;

        wp_send_json_success(['message' => 'Campos vazios preenchidos e endereço atualizado!', 'order_id' => $order_id]);
    }

    /**
     * Add theme customization styles with CSS variables
     */
    public function add_theme_customization_styles() {
        // Complete CSS variables with SuperFrete brand defaults
        $default_variables = array(
            // Primary brand colors
            '--superfrete-primary-color' => '#0fae79',
            '--superfrete-primary-hover' => '#0d9969',
            '--superfrete-secondary-color' => '#c3ff01',
            '--superfrete-secondary-hover' => '#b3e600',
            '--superfrete-success-color' => '#4CAF50',
            '--superfrete-error-color' => '#e74c3c',
            '--superfrete-info-color' => '#2196F3',
            '--superfrete-warning-color' => '#ff9800',
            
            // Background colors
            '--superfrete-bg-color' => '#ffffff',
            '--superfrete-bg-white' => '#ffffff',
            '--superfrete-bg-light' => '#f8f9fa',
            '--superfrete-bg-dark' => '#1a1a1a',
            
            // Text colors
            '--superfrete-text-color' => '#1a1a1a',
            '--superfrete-text-light' => '#777777',
            '--superfrete-text-white' => '#ffffff',
            '--superfrete-heading-color' => '#1a1a1a',
            
            // Border colors
            '--superfrete-border-color' => '#e0e0e0',
            '--superfrete-border-light' => '#f0f0f0',
            '--superfrete-border-dark' => '#cccccc',
            
            // Typography
            '--superfrete-font-family' => 'Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            '--superfrete-font-size-small' => '12px',
            '--superfrete-font-size-base' => '14px',
            '--superfrete-font-size-large' => '16px',
            '--superfrete-font-size-xl' => '18px',
            '--superfrete-font-weight-normal' => '400',
            '--superfrete-font-weight-medium' => '500',
            '--superfrete-font-weight-bold' => '600',
            '--superfrete-line-height' => '1.5',
            
            // Spacing
            '--superfrete-spacing-xs' => '4px',
            '--superfrete-spacing-sm' => '8px',
            '--superfrete-spacing-md' => '12px',
            '--superfrete-spacing-lg' => '16px',
            '--superfrete-spacing-xl' => '24px',
            '--superfrete-spacing-xxl' => '32px',
            
            // Border radius
            '--superfrete-radius-sm' => '4px',
            '--superfrete-radius-md' => '6px',
            '--superfrete-radius-lg' => '8px',
            '--superfrete-radius-xl' => '12px',
            '--superfrete-radius-full' => '50px',
            
            // Shadows
            '--superfrete-shadow-sm' => '0 1px 3px rgba(0, 0, 0, 0.1)',
            '--superfrete-shadow-md' => '0 2px 6px rgba(0, 0, 0, 0.1)',
            '--superfrete-shadow-lg' => '0 4px 12px rgba(0, 0, 0, 0.15)',
            
            // Z-index
            '--superfrete-z-base' => '1',
            '--superfrete-z-overlay' => '100',
            '--superfrete-z-loading' => '101',
            '--superfrete-z-modal' => '200',
            
            // Animation
            '--superfrete-transition-fast' => '0.15s ease',
            '--superfrete-transition-normal' => '0.3s ease',
            '--superfrete-transition-slow' => '0.5s ease',
        );

        // Get custom CSS variables from database
        $custom_variables = get_option('superfrete_custom_css_vars', array());
        
        // Merge defaults with custom variables
        $merged_variables = array_merge($default_variables, $custom_variables);
        
        // Allow themes to modify CSS variables
        $css_variables = apply_filters('superfrete_css_variables', $merged_variables);

        // Build CSS string
        $custom_css = ':root {';
        foreach ($css_variables as $variable => $value) {
            $custom_css .= sprintf('%s: %s;', esc_attr($variable), esc_attr($value));
        }
        $custom_css .= '}';

        // Allow themes to add custom CSS
        $additional_css = apply_filters('superfrete_custom_css', '');

        // Output the styles
        if (!empty($custom_css) || !empty($additional_css)) {
            echo '<style id="superfrete-theme-customization">';
            echo $custom_css;
            echo $additional_css;
            echo '</style>';
        }
    }
    
    /**
     * Add JavaScript to sort shipping options by price on the frontend
     */
    public function add_shipping_sorting_script() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function sortShippingOptions() {
                console.log('SuperFrete: Attempting to sort shipping options...');
                
                // Try multiple selectors to find shipping options
                var $containers = [
                    $('#shipping_method'),
                    $('.woocommerce-shipping-methods'),
                    $('ul.woocommerce-shipping-methods'),
                    $('.shipping-methods'),
                    $('[id*="shipping_method"]'),
                    $('ul li input[name^="shipping_method"]').closest('ul')
                ];
                
                var foundContainer = false;
                
                $.each($containers, function(index, $container) {
                    if ($container.length > 0) {
                        console.log('SuperFrete: Found container #' + index + ':', $container);
                        
                        var $options = $container.find('li');
                        if ($options.length <= 1) {
                            console.log('SuperFrete: Not enough options to sort (' + $options.length + ')');
                            return true; // Continue to next container
                        }
                        
                        foundContainer = true;
                        console.log('SuperFrete: Found ' + $options.length + ' shipping options to sort');
                        
                        // Log original order
                        $options.each(function(i) {
                            var text = $(this).text();
                            var price = extractPrice(text);
                            console.log('SuperFrete: Option ' + i + ': ' + text.substring(0, 50) + '... (Price: ' + price + ')');
                        });
                        
                        // Convert to array and sort
                        var sortedOptions = $options.get().sort(function(a, b) {
                            var priceA = extractPrice($(a).text());
                            var priceB = extractPrice($(b).text());
                            
                            // Free shipping (0) comes first
                            if (priceA === 0 && priceB > 0) return -1;
                            if (priceB === 0 && priceA > 0) return 1;
                            
                            // Sort by price ascending
                            return priceA - priceB;
                        });
                        
                        // Reorder the DOM elements
                        $.each(sortedOptions, function(index, element) {
                            $container.append(element);
                        });
                        
                        console.log('SuperFrete: Shipping options sorted successfully!');
                        
                        // Log sorted order
                        $container.find('li').each(function(i) {
                            var text = $(this).text();
                            var price = extractPrice(text);
                            console.log('SuperFrete: Sorted option ' + i + ': ' + text.substring(0, 50) + '... (Price: ' + price + ')');
                        });
                        
                        return false; // Break out of loop
                    }
                });
                
                if (!foundContainer) {
                    console.log('SuperFrete: No shipping container found. Available elements:');
                    console.log('- #shipping_method:', $('#shipping_method').length);
                    console.log('- .woocommerce-shipping-methods:', $('.woocommerce-shipping-methods').length);
                    console.log('- ul.woocommerce-shipping-methods:', $('ul.woocommerce-shipping-methods').length);
                    console.log('- All shipping method inputs:', $('input[name^="shipping_method"]').length);
                }
            }
            
            function extractPrice(text) {
                // Extract price from text like "R$ 23,72", "R$ 15,49", "Grátis"
                if (text.toLowerCase().includes('grátis') || text.toLowerCase().includes('gratuito') || text.toLowerCase().includes('free')) {
                    return 0;
                }
                
                // Match pattern like "R$ 23,72" or "23,72"
                var match = text.match(/R\$?\s*(\d+)[,.](\d{2})/);
                if (match) {
                    return parseFloat(match[1] + '.' + match[2]);
                }
                
                // Match pattern like "R$ 23" or "23"  
                match = text.match(/R\$?\s*(\d+)/);
                if (match) {
                    return parseFloat(match[1]);
                }
                
                console.log('SuperFrete: Could not extract price from: ' + text);
                return 999999; // Unknown prices go to the end
            }
            
            // Sort on initial load with multiple attempts
            console.log('SuperFrete: Initializing shipping sort...');
            setTimeout(sortShippingOptions, 500);
            setTimeout(sortShippingOptions, 1000);
            setTimeout(sortShippingOptions, 2000);
            setTimeout(sortShippingOptions, 3000);
            
            // Sort when shipping is recalculated
            $(document.body).on('updated_checkout updated_shipping_method wc_checkout_place_order', function(e) {
                console.log('SuperFrete: Checkout updated, re-sorting shipping...', e.type);
                setTimeout(sortShippingOptions, 200);
                setTimeout(sortShippingOptions, 500);
            });
            
            // Also try when any shipping method is changed
            $(document).on('change', 'input[name^="shipping_method"]', function() {
                console.log('SuperFrete: Shipping method changed, re-sorting...');
                setTimeout(sortShippingOptions, 100);
            });
        });
        </script>
        <?php
    }
}
