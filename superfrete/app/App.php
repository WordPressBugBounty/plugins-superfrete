<?php

namespace SuperFrete;

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
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFretePAC.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteSEDEX.php';
                require_once plugin_dir_path(__FILE__) . 'Shipping/SuperFreteMiniEnvio.php';
            }
        });

        add_filter('woocommerce_shipping_methods', function ($methods) {
            if (class_exists('\SuperFrete_API\Shipping\SuperFretePAC') && class_exists('\SuperFrete_API\Shipping\SuperFreteSEDEX') && class_exists('\SuperFrete_API\Shipping\SuperFreteMiniEnvios')) {
                $methods['superfrete_pac'] = '\SuperFrete_API\Shipping\SuperFretePAC';
                $methods['superfrete_sedex'] = '\SuperFrete_API\Shipping\SuperFreteSEDEX';
                $methods['superfrete_mini_envio'] = '\SuperFrete_API\Shipping\SuperFreteMiniEnvios';
            }
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
        require_once plugin_dir_path(__FILE__) . '../api/Http/Request.php';
        require_once plugin_dir_path(__FILE__) . '../api/Helpers/Logger.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/ProductShipping.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/SuperFrete_Order.php';
        require_once plugin_dir_path(__FILE__) . 'Controllers/Admin/SuperFrete_OrderActions.php';
        require_once plugin_dir_path(__FILE__) . '../app/Helpers/SuperFrete_Notice.php';
    }

    /**
     * Inicializa o plugin e adiciona suas funcionalidades
     */
    public function init_plugin()
    {

        new \SuperFrete_API\Admin\SuperFrete_OrderActions();
        new \SuperFrete_API\Admin\SuperFrete_Settings();
        new \SuperFrete_API\Controllers\ProductShipping();
        if (class_exists('\SuperFrete_API\Admin\Admin_Menu')) {
            new \SuperFrete_API\Admin\Admin_Menu();
        }
        new \SuperFrete_API\Controllers\SuperFrete_Order();
        \SuperFrete_API\Helpers\Logger::init();
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp', function () {
            if (!wp_next_scheduled('superfrete_clear_log_event')) {
                wp_schedule_event(time(), 'every_five_days', 'superfrete_clear_log_event');
            }
        });
        add_filter('woocommerce_package_rates', [$this, 'ordenar_metodos_frete_por_preco'], 10, 2);

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
        });
        add_action('init', function () {
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
         // Adiciona os métodos: Mini Envios, PAC e SEDEX
    $methods_to_add = [
        'superfrete_mini_envio',
        'superfrete_pac',
        'superfrete_sedex',
    ];

    foreach ($methods_to_add as $method_id) {
        $zone->add_shipping_method($method_id);
    }

    // Ativa os métodos
    $methods = $zone->get_shipping_methods(true);
    foreach ($methods as $method) {
        if (in_array($method->id, $methods_to_add)) {
            $method->enabled = 'yes';
            $method->save();
        }
    }
        
            error_log('✅ Zona de entrega "Brasil - SuperFrete" criada com o método ativado.');
        });
    }

    public function ordenar_metodos_frete_por_preco($rates, $package)
    {
        if (empty($rates))
            return $rates;

        // Reordena os métodos de frete pelo valor (crescente)
        uasort($rates, function ($a, $b) {
            return $a->cost <=> $b->cost;
        });

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

        wp_enqueue_style('superfrete-popup-css', plugin_dir_url(__FILE__) . '../assets/styles/superfrete.css', [], '1.0');
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
}
