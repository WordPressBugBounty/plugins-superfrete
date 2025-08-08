<?php

namespace SuperFrete_API\Shipping;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;
use SuperFrete_API\Helpers\AddressHelper;

if (!defined('ABSPATH'))
    exit; // Segurança

class SuperFreteShipping extends \WC_Shipping_Method {

    private static $cache = [];
    private static $cache_duration = 1800; // 30 minutes cache for shipping rates
    private static $persistent_cache_key = 'superfrete_shipping_cache';

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('SuperFrete Shipping');
        $this->method_description = __('Calcula frete usando a API da SuperFrete');
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
        );

        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Ativar/Desativar'),
                'type' => 'checkbox',
                'label' => __('Ativar SuperFrete nas áreas de entrega', 'superfrete'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Título do Método'),
                'type' => 'text',
                'description' => __('Este título aparecerá no checkout', 'superfrete'),
                'default' => __('SuperFrete', 'superfrete'),
                'desc_tip' => true
            ),
            'services' => array(
                'title' => __('Serviços Ativos'),
                'type' => 'multiselect',
                'description' => __('Selecione quais serviços da SuperFrete devem estar disponíveis', 'superfrete'),
                'default' => array('1', '2', '17'),
                'options' => array(
                    '1' => __('PAC', 'superfrete'),
                    '2' => __('SEDEX', 'superfrete'),
                    '3' => __('Jadlog', 'superfrete'),
                    '17' => __('Mini Envios', 'superfrete'),
                    '31' => __('Loggi', 'superfrete'),
                ),
                'desc_tip' => true
            ),
            
            // Global settings section
            'global_settings' => array(
                'title' => __('Configurações Globais (aplicam-se a todos os serviços se não configurados individualmente)', 'superfrete'),
                'type' => 'title',
            ),
            'extra_days' => array(
                'title' => __('Dias extras no prazo'),
                'type' => 'number',
                'description' => __('Dias adicionais ao prazo estimado pela SuperFrete'),
                'default' => 0,
                'desc_tip' => true
            ),
            'extra_cost' => array(
                'title' => __('Valor adicional no frete'),
                'type' => 'price',
                'description' => __('Valor extra a ser somado ao custo do frete'),
                'default' => '0',
                'desc_tip' => true
            ),
            'extra_cost_type' => array(
                'title' => __('Tipo de valor adicional'),
                'type' => 'select',
                'description' => __('Escolha se o valor adicional será fixo (R$) ou percentual (%) sobre o frete original.'),
                'default' => 'fixed',
                'options' => array(
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
            
            // PAC individual settings
            'pac_settings' => array(
                'title' => __('Configurações PAC', 'superfrete'),
                'type' => 'title',
                'description' => __('Configurações específicas para o serviço PAC (deixe vazio para usar configurações globais)', 'superfrete'),
            ),
            'pac_free_shipping' => array(
                'title' => __('Frete Grátis PAC'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para PAC', 'superfrete'),
                'default' => 'no',
            ),
            'pac_extra_days' => array(
                'title' => __('Dias extras PAC'),
                'type' => 'number',
                'description' => __('Dias adicionais específicos para PAC (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'pac_extra_cost' => array(
                'title' => __('Valor adicional PAC'),
                'type' => 'price',
                'description' => __('Valor extra específico para PAC (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'pac_extra_cost_type' => array(
                'title' => __('Tipo de valor adicional PAC'),
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('Usar configuração global', 'superfrete'),
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
            
            // SEDEX individual settings
            'sedex_settings' => array(
                'title' => __('Configurações SEDEX', 'superfrete'),
                'type' => 'title',
                'description' => __('Configurações específicas para o serviço SEDEX (deixe vazio para usar configurações globais)', 'superfrete'),
            ),
            'sedex_free_shipping' => array(
                'title' => __('Frete Grátis SEDEX'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para SEDEX', 'superfrete'),
                'default' => 'no',
            ),
            'sedex_extra_days' => array(
                'title' => __('Dias extras SEDEX'),
                'type' => 'number',
                'description' => __('Dias adicionais específicos para SEDEX (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'sedex_extra_cost' => array(
                'title' => __('Valor adicional SEDEX'),
                'type' => 'price',
                'description' => __('Valor extra específico para SEDEX (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'sedex_extra_cost_type' => array(
                'title' => __('Tipo de valor adicional SEDEX'),
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('Usar configuração global', 'superfrete'),
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
            
            // Jadlog individual settings
            'jadlog_settings' => array(
                'title' => __('Configurações Jadlog', 'superfrete'),
                'type' => 'title',
                'description' => __('Configurações específicas para o serviço Jadlog (deixe vazio para usar configurações globais)', 'superfrete'),
            ),
            'jadlog_free_shipping' => array(
                'title' => __('Frete Grátis Jadlog'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para Jadlog', 'superfrete'),
                'default' => 'no',
            ),
            'jadlog_extra_days' => array(
                'title' => __('Dias extras Jadlog'),
                'type' => 'number',
                'description' => __('Dias adicionais específicos para Jadlog (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'jadlog_extra_cost' => array(
                'title' => __('Valor adicional Jadlog'),
                'type' => 'price',
                'description' => __('Valor extra específico para Jadlog (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'jadlog_extra_cost_type' => array(
                'title' => __('Tipo de valor adicional Jadlog'),
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('Usar configuração global', 'superfrete'),
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
            
            // Mini Envios individual settings
            'mini_envios_settings' => array(
                'title' => __('Configurações Mini Envios', 'superfrete'),
                'type' => 'title',
                'description' => __('Configurações específicas para o serviço Mini Envios (deixe vazio para usar configurações globais)', 'superfrete'),
            ),
            'mini_envios_free_shipping' => array(
                'title' => __('Frete Grátis Mini Envios'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para Mini Envios', 'superfrete'),
                'default' => 'no',
            ),
            'mini_envios_extra_days' => array(
                'title' => __('Dias extras Mini Envios'),
                'type' => 'number',
                'description' => __('Dias adicionais específicos para Mini Envios (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'mini_envios_extra_cost' => array(
                'title' => __('Valor adicional Mini Envios'),
                'type' => 'price',
                'description' => __('Valor extra específico para Mini Envios (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'mini_envios_extra_cost_type' => array(
                'title' => __('Tipo de valor adicional Mini Envios'),
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('Usar configuração global', 'superfrete'),
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
            
            // Loggi individual settings
            'loggi_settings' => array(
                'title' => __('Configurações Loggi', 'superfrete'),
                'type' => 'title',
                'description' => __('Configurações específicas para o serviço Loggi (deixe vazio para usar configurações globais)', 'superfrete'),
            ),
            'loggi_free_shipping' => array(
                'title' => __('Frete Grátis Loggi'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para Loggi', 'superfrete'),
                'default' => 'no',
            ),
            'loggi_extra_days' => array(
                'title' => __('Dias extras Loggi'),
                'type' => 'number',
                'description' => __('Dias adicionais específicos para Loggi (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'loggi_extra_cost' => array(
                'title' => __('Valor adicional Loggi'),
                'type' => 'price',
                'description' => __('Valor extra específico para Loggi (vazio = usar global)', 'superfrete'),
                'default' => '',
                'desc_tip' => true
            ),
            'loggi_extra_cost_type' => array(
                'title' => __('Tipo de valor adicional Loggi'),
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('Usar configuração global', 'superfrete'),
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
        );

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Calculate shipping rates for the package
     */
    public function calculate_shipping($package = []) {
        if (!$this->enabled || empty($package['destination']['postcode'])) {
            return;
        }

        // Only calculate shipping in specific contexts to avoid unnecessary API calls
        if (!$this->should_calculate_shipping()) {
            Logger::log('SuperFrete', 'Skipping shipping calculation - not in required context');
            return;
        }

        $cep_destino = $package['destination']['postcode'];
        $cep_origem = get_option('woocommerce_store_postcode');

        if (!$cep_origem) {
            Logger::log('SuperFrete', 'Origin postcode not configured');
            return;
        }

        // Create cache key based on package contents and destination
        $cache_key = $this->generate_cache_key($package);
        $destination_cep = $package['destination']['postcode'];
        
        Logger::log('SuperFrete', 'Calculating shipping for CEP: ' . $destination_cep . ' with cache key: ' . $cache_key);
        
        // Check cache first (both memory and persistent)
        $response = $this->get_cached_response($cache_key);
        
        if ($response === false) {
            Logger::log('SuperFrete', 'Cache MISS for CEP: ' . $destination_cep . ' - making API call');
            // Make API call
            $response = $this->call_superfrete_api($package);
            
            // Cache the response if successful
            if ($response) {
                $this->cache_response($cache_key, $response);
                Logger::log('SuperFrete', 'Cached new shipping rates for CEP: ' . $destination_cep . ' with key: ' . $cache_key);
            }
        } else {
            Logger::log('SuperFrete', 'Cache HIT for CEP: ' . $destination_cep . ' - using cached rates with key: ' . $cache_key);
        }

        if (!empty($response)) {
            $this->process_shipping_response($response);
        }
    }

    /**
     * Generate cache key for the shipping calculation
     */
    private function generate_cache_key($package) {
        // Clean and normalize postal codes
        $destination_postcode = preg_replace('/[^0-9]/', '', $package['destination']['postcode']);
        $origin_postcode = preg_replace('/[^0-9]/', '', get_option('woocommerce_store_postcode'));
        
        $key_data = [
            'destination' => $destination_postcode,
            'origin' => $origin_postcode,
            'services' => $this->get_option('services', array('1', '2', '17')),
            'contents' => [],
            'version' => '2.0' // Increment this to invalidate all cache when logic changes
        ];

        // Sort contents by product ID for consistent cache keys
        $contents = [];
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $contents[] = [
                'id' => $product->get_id(),
                'quantity' => $item['quantity'],
                'weight' => floatval($product->get_weight()),
                'dimensions' => [
                    'height' => floatval($product->get_height()),
                    'width' => floatval($product->get_width()),
                    'length' => floatval($product->get_length()),
                ]
            ];
        }
        
        // Sort by product ID for consistent cache keys regardless of order
        usort($contents, function($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        
        $key_data['contents'] = $contents;

        return 'superfrete_v2_' . md5(serialize($key_data));
    }

    /**
     * Call SuperFrete API with consolidated request
     */
    private function call_superfrete_api($package) {
        $cep_destino = $package['destination']['postcode'];
        $cep_origem = get_option('woocommerce_store_postcode');
        
        $produtos = [];
        $insurance_value = 0;
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $insurance_value += $product->get_price() * $item['quantity'];
            
            $weight_unit = get_option('woocommerce_weight_unit');
            $dimension_unit = get_option('woocommerce_dimension_unit');

            $produtos[] = [
                'quantity' => $item['quantity'],
                'weight' => ($weight_unit === 'g') ? floatval($product->get_weight()) / 1000 : floatval($product->get_weight()),
                'height' => ($dimension_unit === 'm') ? floatval($product->get_height()) * 100 : floatval($product->get_height()),
                'width' => ($dimension_unit === 'm') ? floatval($product->get_width()) * 100 : floatval($product->get_width()),
                'length' => ($dimension_unit === 'm') ? floatval($product->get_length()) * 100 : floatval($product->get_length()),
            ];
        }

        // Get active services from settings
        $active_services = $this->get_option('services', array('1', '2', '17'));
        $services_string = implode(',', $active_services);

        $payload = [
            'from' => ['postal_code' => $cep_origem],
            'to' => ['postal_code' => $cep_destino],
            'services' => $services_string,
            'options' => [
                "own_hand" => false,
                "receipt" => false,
                "insurance_value" => $insurance_value,
                "use_insurance_value" => false
            ],
            'products' => $produtos,
        ];

        Logger::log('SuperFrete', 'Making consolidated API call for services: ' . $services_string . ' to CEP: ' . $cep_destino);
        Logger::log('SuperFrete', 'API payload: ' . wp_json_encode($payload));
        
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/calculator', 'POST', $payload);
        
        if ($response === false) {
            Logger::log('SuperFrete', 'API call failed - no response received');
            return false;
        }
        
        if (empty($response)) {
            Logger::log('SuperFrete', 'API call returned empty response');
            return false;
        }
        
        Logger::log('SuperFrete', 'API response received: ' . wp_json_encode($response));
        return $response;
    }

    /**
     * Get service-specific settings or fall back to global settings
     */
    private function get_service_settings($service_id) {
        $service_map = [
            '1' => 'pac',
            '2' => 'sedex',
            '3' => 'jadlog',
            '17' => 'mini_envios',
            '31' => 'loggi',
        ];
        
        $service_prefix = isset($service_map[$service_id]) ? $service_map[$service_id] : null;
        
        if (!$service_prefix) {
            // Unknown service, use global settings
            return [
                'free_shipping' => false,
                'extra_days' => $this->get_option('extra_days', 0),
                'extra_cost' => $this->get_option('extra_cost', 0),
                'extra_cost_type' => $this->get_option('extra_cost_type', 'fixed'),
            ];
        }
        
        // Get service-specific settings with fallback to global
        $free_shipping = $this->get_option($service_prefix . '_free_shipping', 'no') === 'yes';
        
        $extra_days = $this->get_option($service_prefix . '_extra_days', '');
        if ($extra_days === '') {
            $extra_days = $this->get_option('extra_days', 0);
        }
        
        $extra_cost = $this->get_option($service_prefix . '_extra_cost', '');
        if ($extra_cost === '') {
            $extra_cost = $this->get_option('extra_cost', 0);
        }
        
        $extra_cost_type = $this->get_option($service_prefix . '_extra_cost_type', '');
        if ($extra_cost_type === '') {
            $extra_cost_type = $this->get_option('extra_cost_type', 'fixed');
        }
        
        return [
            'free_shipping' => $free_shipping,
            'extra_days' => intval($extra_days),
            'extra_cost' => floatval($extra_cost),
            'extra_cost_type' => $extra_cost_type,
        ];
    }

    /**
     * Process the API response and add shipping rates
     */
    private function process_shipping_response($response) {
        if (!is_array($response)) {
            Logger::log('SuperFrete', 'Invalid response format - expected array, got: ' . gettype($response));
            return;
        }

        if (empty($response)) {
            Logger::log('SuperFrete', 'Empty response array - no shipping options available');
            return;
        }

        Logger::log('SuperFrete', 'Processing ' . count($response) . ' shipping options');

        $rates_added = 0;

        foreach ($response as $index => $frete) {
            Logger::log('SuperFrete', 'Processing shipping option ' . $index . ': ' . wp_json_encode($frete));

            // Check for errors in this shipping option
            if (isset($frete['has_error']) && $frete['has_error']) {
                Logger::log('SuperFrete', 'Shipping option ' . $index . ' has error flag set');
                continue;
            }

            if (isset($frete['error'])) {
                Logger::log('SuperFrete', 'Shipping option ' . $index . ' has error: ' . wp_json_encode($frete['error']));
                continue;
            }

            // Validate required fields
            if (!isset($frete['id']) || !isset($frete['name']) || !isset($frete['price']) || !isset($frete['delivery_time'])) {
                Logger::log('SuperFrete', 'Shipping option ' . $index . ' missing required fields: ' . wp_json_encode(array_keys($frete)));
                continue;
            }

            // Get service-specific settings
            $service_settings = $this->get_service_settings($frete['id']);
            
            // Skip if free shipping is enabled for this service
            if ($service_settings['free_shipping']) {
                $frete_custo = 0;
                $frete_desc = " - Frete Grátis";
            } else {
                // Calculate cost with extra fees
                $frete_base = floatval($frete['price']);
                if ($service_settings['extra_cost_type'] === 'percent') {
                    $frete_custo = $frete_base + ($frete_base * ($service_settings['extra_cost'] / 100));
                } else {
                    $frete_custo = $frete_base + $service_settings['extra_cost'];
                }
                $frete_desc = "";
            }

            $prazo_total = intval($frete['delivery_time']) + $service_settings['extra_days'];
            $text_dias = ($prazo_total <= 1) ? "dia útil" : "dias úteis";

            $rate = [
                'id' => $this->id . '_' . $frete['id'],
                'label' => $frete['name'] . ' - (' . $prazo_total . ' ' . $text_dias . ')' . $frete_desc,
                'cost' => $frete_custo,
                'meta_data' => [
                    'service_id' => $frete['id'],
                    'service_name' => $frete['name'],
                    'delivery_time' => $prazo_total,
                    'original_price' => floatval($frete['price']),
                    'free_shipping' => $service_settings['free_shipping'],
                ]
            ];
            
            $this->add_rate($rate);
            $rates_added++;
            Logger::log('SuperFrete', 'Added shipping rate: ' . $frete['name'] . ' - R$ ' . number_format($frete_custo, 2, ',', '.') . ' (' . $prazo_total . ' ' . $text_dias . ')' . $frete_desc);
        }

        Logger::log('SuperFrete', 'Total shipping rates added: ' . $rates_added);
    }

    /**
     * Determine if we should calculate shipping in the current context
     */
    private function should_calculate_shipping() {
        // Always calculate if we're in admin (for testing)
        /* if (is_admin()) {
            return true;
        } */

        // Always calculate on cart and checkout pages
        if (is_cart() || is_checkout()) {
            return true;
        }

        // Check if this is a WooCommerce Store API request (block-based cart/checkout)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            
            // Allow shipping calculations for WooCommerce Store API endpoints
            if (strpos($request_uri, '/wc/store/') !== false) {
                Logger::log('SuperFrete', 'Allowing shipping calculation for WooCommerce Store API: ' . $request_uri);
                return true;
            }
        }

        // Calculate for our specific product shipping calculator AJAX
        if (wp_doing_ajax()) {
            $action = $_REQUEST['action'] ?? '';
            
            // Our specific shipping calculator
            if ($action === 'superfrete_cal_shipping') {
                return true;
            }
            
            // WooCommerce cart/checkout AJAX actions
            if (in_array($action, [
                'woocommerce_update_shipping_method',
                'woocommerce_checkout',
                'woocommerce_update_order_review',
                'woocommerce_apply_coupon',
                'woocommerce_remove_coupon'
            ])) {
                return true;
            }
            
            // Skip for other AJAX actions (like add to cart)
            Logger::log('SuperFrete', 'Skipping shipping calculation for AJAX action: ' . $action);
            return false;
        }

        // Calculate for our specific product shipping calculator
        if (isset($_POST['superfrete_nonce']) && wp_verify_nonce($_POST['superfrete_nonce'], 'superfrete_nonce')) {
            return true;
        }

        // Skip calculation for all other contexts (including product pages, add to cart, etc.)
        $context = 'unknown';
        if (is_product()) $context = 'product page';
        if (is_shop()) $context = 'shop page';
        if (is_home()) $context = 'home page';
        
        Logger::log('SuperFrete', 'Skipping shipping calculation - context: ' . $context);
        return false;
    }

    /**
     * Get cached response from memory or persistent storage
     */
    private function get_cached_response($cache_key) {
        // First check memory cache (fastest)
        if (isset(self::$cache[$cache_key]) && 
            (time() - self::$cache[$cache_key]['timestamp']) < self::$cache_duration) {
            return self::$cache[$cache_key]['data'];
        }
        
        // Then check WordPress transients (persistent across requests)
        $transient_key = self::$persistent_cache_key . '_' . md5($cache_key);
        $cached_data = get_transient($transient_key);
        
        if ($cached_data !== false) {
            // Store in memory cache for subsequent calls in this request
            self::$cache[$cache_key] = [
                'data' => $cached_data,
                'timestamp' => time()
            ];
            return $cached_data;
        }
        
        return false;
    }
    
    /**
     * Cache response in both memory and persistent storage
     */
    private function cache_response($cache_key, $response) {
        // Store in memory cache
        self::$cache[$cache_key] = [
            'data' => $response,
            'timestamp' => time()
        ];
        
        // Store in WordPress transients for persistence
        $transient_key = self::$persistent_cache_key . '_' . md5($cache_key);
        set_transient($transient_key, $response, self::$cache_duration);
    }

    /**
     * Clear cache when needed
     */
    public static function clear_cache() {
        // Clear memory cache
        self::$cache = [];
        
        // Clear persistent cache - we need to delete all transients with our prefix
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::$persistent_cache_key . '_%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . self::$persistent_cache_key . '_%'
            )
        );
        
        Logger::log('SuperFrete', 'All shipping cache cleared (memory + persistent)');
    }

    public function process_admin_options() {
        parent::process_admin_options();
        // Clear cache when settings are updated
        self::clear_cache();
        Logger::log('SuperFrete', 'Shipping settings updated - cache cleared');
    }
    
    /**
     * Get cache statistics for debugging
     */
    public static function get_cache_stats() {
        global $wpdb;
        
        $transient_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::$persistent_cache_key . '_%'
            )
        );
        
        return [
            'memory_cache_entries' => count(self::$cache),
            'persistent_cache_entries' => intval($transient_count),
            'cache_duration_minutes' => self::$cache_duration / 60,
        ];
    }
}