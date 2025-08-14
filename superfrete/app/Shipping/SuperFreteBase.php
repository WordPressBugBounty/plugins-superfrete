<?php

namespace SuperFrete_API\Shipping;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;
use SuperFrete_API\Helpers\AddressHelper;

if (!defined('ABSPATH'))
    exit; // Segurança

abstract class SuperFreteBase extends \WC_Shipping_Method {

    private static $cache = [];
    private static $cache_duration = 1800; // 30 minutes cache for shipping rates
    private static $persistent_cache_key = 'superfrete_shipping_cache';
    private static $api_response_cache = []; // Cache API responses per request
    
    protected $free_shipping;
    protected $extra_days;
    protected $extra_cost;
    protected $extra_cost_type;

    public function __construct($instance_id = 0) {
        $this->instance_id = absint($instance_id);
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->free_shipping = $this->get_option('free_shipping');
        $this->extra_days = $this->get_option('extra_days', 0);
        $this->extra_cost = $this->get_option('extra_cost', 0);
        $this->extra_cost_type = $this->get_option('extra_cost_type', 'fixed');

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize form fields for the shipping method
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Ativar/Desativar'),
                'type' => 'checkbox',
                'label' => __('Ativar este método de entrega', 'superfrete'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Título do Método'),
                'type' => 'text',
                'description' => __('Este título aparecerá no checkout', 'superfrete'),
                'default' => $this->method_title,
                'desc_tip' => true
            ),
            'free_shipping' => array(
                'title' => __('Frete Grátis', 'superfrete'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para este método', 'superfrete'),
                'default' => 'no',
            ),
            'extra_days' => array(
                'title' => __('Dias extras no prazo', 'superfrete'),
                'type' => 'number',
                'description' => __('Dias adicionais ao prazo estimado pela SuperFrete', 'superfrete'),
                'default' => 0,
                'desc_tip' => true
            ),
            'extra_cost' => array(
                'title' => __('Valor adicional no frete', 'superfrete'),
                'type' => 'price',
                'description' => __('Valor extra a ser somado ao custo do frete', 'superfrete'),
                'default' => '0',
                'desc_tip' => true
            ),
            'extra_cost_type' => array(
                'title' => __('Tipo de valor adicional', 'superfrete'),
                'type' => 'select',
                'description' => __('Escolha se o valor adicional será fixo (R$) ou percentual (%) sobre o frete original.', 'superfrete'),
                'default' => 'fixed',
                'options' => array(
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
        );
    }

    /**
     * Get the service ID for this shipping method
     * Must be implemented by child classes
     */
    abstract protected function get_service_id();

    /**
     * Calculate shipping for the package
     */
    public function calculate_shipping($package = []) {
        if (!$this->enabled || empty($package['destination']['postcode'])) {
            return;
        }

        // Only calculate shipping in specific contexts to avoid unnecessary API calls
        if (!$this->should_calculate_shipping()) {
            Logger::log('SuperFrete', 'Skipping shipping calculation for ' . $this->id . ' - not in required context');
            return;
        }

        $cep_destino = $this->clean_postcode($package['destination']['postcode']);
        $cep_origem = $this->clean_postcode(get_option('woocommerce_store_postcode'));

        if (!$cep_origem || !$cep_destino) {
            Logger::log('SuperFrete', 'Origin or destination postcode not configured or invalid');
            return;
        }

        // Get the API response (either from cache or fresh API call)
        $response = $this->get_api_response($package);
        
        if (!empty($response)) {
            $this->process_shipping_response($response);
        }
    }

    /**
     * Get API response with caching
     */
    protected function get_api_response($package) {
        // Create cache key based on package contents and destination
        $cache_key = $this->generate_cache_key($package);
        $destination_cep = $this->clean_postcode($package['destination']['postcode']);
        
        Logger::log('SuperFrete', 'Calculating shipping for ' . $this->id . ' CEP: ' . $destination_cep . ' with cache key: ' . $cache_key);
        
        // Check in-request cache first (for multiple shipping methods in same request)
        if (isset(self::$api_response_cache[$cache_key])) {
            Logger::log('SuperFrete', 'Using in-request cached API response for ' . $this->id);
            return self::$api_response_cache[$cache_key];
        }
        
        // Check persistent cache
        $response = $this->get_cached_response($cache_key);
        
        if ($response === false) {
            Logger::log('SuperFrete', 'Cache MISS for ' . $this->id . ' CEP: ' . $destination_cep . ' - making API call');
            // Make API call for ALL services
            $response = $this->call_superfrete_api($package);
            
            // Cache the response only if successful and contains valid data
            if ($response && $this->is_valid_response($response)) {
                $this->cache_response($cache_key, $response);
                self::$api_response_cache[$cache_key] = $response;
                Logger::log('SuperFrete', 'Cached new shipping rates for ' . $this->id . ' CEP: ' . $destination_cep);
            } elseif ($response) {
                Logger::log('SuperFrete', 'NOT caching response due to errors for ' . $this->id . ' CEP: ' . $destination_cep);
            }
        } else {
            Logger::log('SuperFrete', 'Cache HIT for ' . $this->id . ' CEP: ' . $destination_cep);
            self::$api_response_cache[$cache_key] = $response;
        }

        return $response;
    }

    /**
     * Process the API response and add shipping rates
     */
    protected function process_shipping_response($response) {
        if (!is_array($response)) {
            return;
        }

        $service_id = $this->get_service_id();

        // Find our specific service in the response
        foreach ($response as $frete) {
            if (!isset($frete['id']) || $frete['id'] != $service_id) {
                continue;
            }

            // Check for errors - with detailed logging
            if (isset($frete['has_error']) && $frete['has_error']) {
                Logger::log('SuperFrete', $this->id . ' has error flag set: ' . wp_json_encode($frete));
                continue;
            }

            if (isset($frete['error'])) {
                $error_msg = is_string($frete['error']) ? $frete['error'] : wp_json_encode($frete['error']);
                Logger::log('SuperFrete', $this->id . ' HAS ERROR: "' . $error_msg . '"');
                continue;
            }

            // Validate required fields
            if (!isset($frete['name']) || !isset($frete['price']) || !isset($frete['delivery_time'])) {
                Logger::log('SuperFrete', $this->id . ' missing required fields');
                continue;
            }

            $prazo_total = intval($frete['delivery_time']) + intval($this->extra_days);
            $text_dias = ($prazo_total <= 1) ? "dia útil" : "dias úteis";

            // Calculate cost
            $frete_custo = 0;
            $frete_desc = "";

            if ($this->free_shipping !== 'yes') {
                $frete_base = floatval($frete['price']);
                if ($this->extra_cost_type === 'percent') {
                    $frete_custo = $frete_base + ($frete_base * (floatval($this->extra_cost) / 100));
                } else {
                    $frete_custo = $frete_base + floatval($this->extra_cost);
                }
            } else {
                $frete_desc = " - Frete Grátis";
            }

            $rate = [
                'id' => $this->id,
                'label' => html_entity_decode($this->title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' (' . $prazo_total . ' ' . $text_dias . ')' . $frete_desc,
                'cost' => $frete_custo,
                'meta_data' => [
                    'service_id' => strval($frete['id']), // Ensure it's a string
                    'service_name' => html_entity_decode($frete['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'delivery_time' => $prazo_total,
                    'original_price' => floatval($frete['price']),
                    '_superfrete_service_id' => strval($frete['id']), // Also store with underscore prefix
                ]
            ];
            
            $this->add_rate($rate);
            Logger::log('SuperFrete', 'Added shipping rate for ' . $this->id . ': R$ ' . number_format($frete_custo, 2, ',', '.'));
            break; // We only need one rate for this service
        }
    }

    /**
     * Clean postal code by removing non-numeric characters
     */
    private function clean_postcode($postcode) {
        return preg_replace('/[^0-9]/', '', $postcode);
    }

    /**
     * Call SuperFrete API with all services
     */
    private function call_superfrete_api($package) {
        $cep_destino = $this->clean_postcode($package['destination']['postcode']);
        $cep_origem = $this->clean_postcode(get_option('woocommerce_store_postcode'));
        
        $produtos = [];
        $insurance_value = 0;
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $insurance_value += $product->get_price() * $item['quantity'];
            
            $weight_unit = get_option('woocommerce_weight_unit');
            $dimension_unit = get_option('woocommerce_dimension_unit');

            // Convert and validate product dimensions
            $weight = ($weight_unit === 'g') ? floatval($product->get_weight()) / 1000 : floatval($product->get_weight());
            $height = ($dimension_unit === 'm') ? floatval($product->get_height()) * 100 : floatval($product->get_height());
            $width = ($dimension_unit === 'm') ? floatval($product->get_width()) * 100 : floatval($product->get_width());
            $length = ($dimension_unit === 'm') ? floatval($product->get_length()) * 100 : floatval($product->get_length());
            
            // Apply minimum values to prevent API errors
            $weight = max($weight, 0.1); // Minimum 0.1kg
            $height = max($height, 1.0); // Minimum 1cm
            $width = max($width, 1.0);   // Minimum 1cm
            $length = max($length, 1.0); // Minimum 1cm

            $produtos[] = [
                'quantity' => max(intval($item['quantity']), 1),
                'weight' => round($weight, 3),
                'height' => round($height, 1),
                'width' => round($width, 1),
                'length' => round($length, 1),
            ];
        }

        // Validate required data before API call
        if (empty($produtos)) {
            Logger::log('SuperFrete', 'No valid products found for shipping calculation');
            return false;
        }
        
        if (empty($cep_origem) || empty($cep_destino)) {
            Logger::log('SuperFrete', 'Missing origin or destination postal code');
            return false;
        }

        // Request all services at once for better performance
        $services_string = '1,2,3,17,31'; // PAC, SEDEX, Jadlog, Mini Envios, Loggi

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

        Logger::log('SuperFrete', 'Making consolidated API call for all services to CEP: ' . $cep_destino . ' (cleaned from: ' . $package['destination']['postcode'] . ')');
        Logger::log('SuperFrete', 'Payload products: ' . wp_json_encode($produtos));
        
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/calculator', 'POST', $payload);
        
        if ($response === false) {
            Logger::log('SuperFrete', 'API call failed - no response received');
            return false;
        }
        
        return $response;
    }

    /**
     * Generate cache key for the shipping calculation
     */
    private function generate_cache_key($package) {
        // Clean and normalize postal codes
        $destination_postcode = $this->clean_postcode($package['destination']['postcode']);
        $origin_postcode = $this->clean_postcode(get_option('woocommerce_store_postcode'));
        
        $key_data = [
            'destination' => $destination_postcode,
            'origin' => $origin_postcode,
            'contents' => [],
            'version' => '3.0' // Increment this to invalidate all cache when logic changes
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

        return 'superfrete_v3_' . md5(serialize($key_data));
    }

    /**
     * Determine if we should calculate shipping in the current context
     */
    private function should_calculate_shipping() {
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

        // Skip calculation for all other contexts
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
     * Validate if API response is cacheable (no errors)
     */
    private function is_valid_response($response) {
        if (!is_array($response)) {
            return false;
        }
        
        // Check if any service has errors
        foreach ($response as $service) {
            if (isset($service['has_error']) && $service['has_error']) {
                return false;
            }
            if (isset($service['error']) && !empty($service['error'])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Clear cache when needed
     */
    public static function clear_cache() {
        // Clear memory cache
        self::$cache = [];
        self::$api_response_cache = [];
        
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
        Logger::log('SuperFrete', 'Shipping settings updated for ' . $this->id . ' - cache cleared');
    }
}