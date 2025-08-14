<?php

namespace SuperFrete_API\Controllers;

use SuperFrete_API\Http\Request;
use WC_Shipping_Zones;

if (!defined('ABSPATH'))
    exit; // Segurança

class ProductShipping {

    public function __construct() {

        add_action('woocommerce_after_add_to_cart_form', array(__CLASS__, 'calculator'));
		add_shortcode('pi_shipping_calculator', array($this, 'calculator_shortcode'));
		
        add_action('wc_ajax_pi_load_location_by_ajax', array(__CLASS__, 'loadLocation') );
        add_action('woocommerce_after_add_to_cart_form', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_superfrete_calculate', [$this, 'calculate_shipping']);
        add_action('wp_ajax_nopriv_superfrete_calculate', [$this, 'calculate_shipping']); // Para usuários não logados

        add_action('wp_ajax_superfrete_cal_shipping', array(__CLASS__, 'applyShipping'));
        add_action('wp_ajax_nopriv_superfrete_cal_shipping', array(__CLASS__, 'applyShipping'));
        add_action('wc_ajax_superfrete_cal_shipping', array(__CLASS__, 'applyShipping'));
        
        // Hook the hideCalculator method to the filter so virtual products are automatically hidden
        add_filter('superfrete_hide_calculator_on_single_product_page', array(__CLASS__, 'hideCalculator'), 10, 2);
    }

    static function resultHtml() {
        echo '<div id="superfrete-alert-container" class="superfrete-alert-container"></div>';
    }

    function calculator_shortcode() {
        if ($this->position != 'shortcode') {
            return '<div class="error">' . __('Short code is disabled in setting', 'superfrete-product-page-shipping-calculator-woocommerce') . '</di>';
        }

        if (function_exists('is_product') && !is_product()) {
            return '<div class="error">' . __('This shortcode will only work on product page', 'superfrete-product-page-shipping-calculator-woocommerce') . '</di>';
        }

        global $product;

        if (!is_object($product) || $product->is_virtual() || !$product->is_in_stock())
            return;

        ob_start();
        self::calculator();
        $html = ob_get_contents();
        ob_end_clean();
        return $html;
    }

    static function calculator() {
        global $product;

        // Hide calculator for virtual products (they don't need shipping)
        if (is_object($product) && $product->is_virtual()) {
            return;
        }

        if (apply_filters('superfrete_hide_calculator_on_single_product_page', false, $product)) {
            return;
        }

        if (is_object($product)) {
            $product_id = $product->get_id();
        } else {
            $product = "";
        }

        $enable_calculator = get_option('superfrete_enable_calculator');

        if ($enable_calculator === 'no')
            return;

        $button_text = get_option('superfrete_open_drawer_button_text', 'Calcular Entrega');
        $update_address_btn_text = get_option('superfrete_update_button_text', 'Calcular');

          include plugin_dir_path(__FILE__) . '../../templates/woocommerce/shipping-calculator.php';
    }

    static function hideCalculator($val, $product) {
        if (is_object($product) && $product->is_virtual())
            return true;

        return $val;
    }

    static function applyShipping() {
          if (!isset($_POST['superfrete_nonce']) || !wp_verify_nonce($_POST['superfrete_nonce'], 'superfrete_nonce')) {
        wp_send_json_error(['message' => 'Requisição inválida.'], 403);
    }
        // Start timing the entire function execution
        $total_start_time = microtime(true);
        
        // Setup logging
        $log_data = [
            'method' => 'applyShipping',
            'steps' => [],
            'total_time' => 0
        ];
        
        if (!class_exists('WC_Shortcode_Cart')) {
            include_once WC_ABSPATH . 'includes/shortcodes/class-wc-shortcode-cart.php';
        }
    
        
        if (self::doingCalculation()) {
            $step_start = microtime(true);
        
            if ((isset($_POST['action_auto_load']) && self::disableAutoLoadEstimate()) || empty($_POST['calc_shipping_postcode']) || !isset($_POST['calc_shipping_postcode'])) {
                $return['shipping_methods'] = sprintf('<div class="superfrete-alert">%s</div>', esc_html(get_option('superfrete_no_address_added_yet', 'Informe seu Endereço para calcular')));
                wp_send_json($return);
            }
            
            $log_data['steps']['initial_validation'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
            $step_start = microtime(true);
                   
            $return = array();
            \WC_Shortcode_Cart::calculate_shipping();
            WC()->cart->calculate_totals();
 
            $log_data['steps']['calculate_shipping'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
            $step_start = microtime(true);
   
            // OPTIMIZATION: Use a lightweight shipping package instead of manipulating the cart
            // This avoids expensive cart operations that were causing performance issues
            
            // Get product ID and variation ID
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
            $variation_id = filter_input(INPUT_POST, 'variation_id', FILTER_VALIDATE_INT);
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?: 1;
            
            // Get product data directly without cart manipulation
            if ($product_id) {
                $product = wc_get_product($variation_id ?: $product_id);
                
                if ($product) {
                    // Skip calculation for virtual products
                    if ($product->is_virtual()) {
                        $return['shipping_methods'] = sprintf('<div class="superfrete-alert">%s</div>', esc_html__('Produtos virtuais não necessitam de frete.', 'superfrete'));
                        wp_send_json($return);
                    }
                    // Get destination from form data (not customer data)
                    $destination_postcode = sanitize_text_field($_POST['calc_shipping_postcode'] ?? '');
                    $destination_country = sanitize_text_field($_POST['calc_shipping_country'] ?? 'BR');
                    $destination_state = sanitize_text_field($_POST['calc_shipping_state'] ?? '');
                    $destination_city = sanitize_text_field($_POST['calc_shipping_city'] ?? '');
                    
                    // Create a lightweight package for shipping calculation
                    $package = [
                        'contents' => [
                            [
                                'data' => $product,
                                'quantity' => $quantity
                            ]
                        ],
                        'contents_cost' => $product->get_price() * $quantity,
                        'applied_coupons' => [],
                        'user' => [
                            'ID' => get_current_user_id(),
                        ],
                        'destination' => [
                            'country' => $destination_country,
                            'state' => $destination_state,
                            'postcode' => $destination_postcode,
                            'city' => $destination_city,
                        ],
                        'cart_subtotal' => $product->get_price() * $quantity,
                    ];
                    
                    $log_data['steps']['prepare_package'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
                    $step_start = microtime(true);
                    
                    // Debug: Check all registered shipping methods
                    $wc_shipping = \WC_Shipping::instance();
                    $all_shipping_methods = $wc_shipping->get_shipping_methods();
                    $log_data['all_registered_methods'] = array_keys($all_shipping_methods);
                    
                    // Debug: Check all shipping zones
                    $all_zones = \WC_Shipping_Zones::get_zones();
                    $log_data['all_zones'] = [];
                    foreach ($all_zones as $zone_data) {
                        $zone = new \WC_Shipping_Zone($zone_data['zone_id']);
                        $zone_methods = [];
                        foreach ($zone->get_shipping_methods() as $method) {
                            $zone_methods[] = [
                                'id' => $method->id,
                                'enabled' => $method->enabled,
                                'title' => $method->get_title(),
                            ];
                        }
                        $log_data['all_zones'][] = [
                            'id' => $zone->get_id(),
                            'name' => $zone->get_zone_name(),
                            'methods' => $zone_methods,
                        ];
                    }
                    
                    // Calculate shipping rates directly with our package
                    $shipping_zone = \WC_Shipping_Zones::get_zone_matching_package($package);
                    
                    // Add detailed debugging for shipping zone
                    $log_data['package_destination'] = $package['destination'];
                    $log_data['shipping_zone'] = [
                        'id' => $shipping_zone->get_id(),
                        'name' => $shipping_zone->get_zone_name(),
                        'locations' => $shipping_zone->get_zone_locations(),
                    ];
                    
                    // Detailed logging for shipping calculation
                    $shipping_start = microtime(true);
                    
                    // Log shipping providers being used
                    $active_shipping = [];
                    $all_methods = $shipping_zone->get_shipping_methods(true);
                    foreach ($all_methods as $method) {
                        $active_shipping[] = [
                            'id' => $method->id,
                            'enabled' => $method->is_enabled() ? 'yes' : 'no',
                            'title' => $method->get_title(),
                            'instance_id' => $method->get_instance_id(),
                        ];
                    }
                    $log_data['active_shipping_methods'] = $active_shipping;
                    $log_data['total_methods_found'] = count($all_methods);
                    
                    // Calculate shipping directly
                    $rates = [];
                    $method_times = [];
                    
                    foreach ($shipping_zone->get_shipping_methods(true) as $method) {
                        $method_start = microtime(true);
                        $method_id = $method->id;
                        
                        // Log method details
                        $log_data['shipping_methods'][] = [
                            'id' => $method_id,
                            'title' => $method->get_title(),
                            'enabled' => $method->is_enabled() ? 'yes' : 'no',
                            'class' => get_class($method),
                            'instance_id' => $method->get_instance_id(),
                        ];
                        
                        // Time each individual method
                        try {
                            // For SuperFrete shipping method, call calculate_shipping directly
                            if ($method_id === 'superfrete_shipping') {
                                $method->calculate_shipping($package);
                                $method_rates = $method->rates;
                            } else {
                                $method_rates = $method->get_rates_for_package($package);
                            }
                            
                            $log_data['method_results'][$method_id] = [
                                'rates_count' => is_array($method_rates) ? count($method_rates) : 0,
                                'rates' => $method_rates ? array_keys($method_rates) : [],
                            ];
                        } catch (Exception $e) {
                            $log_data['method_errors'][$method_id] = $e->getMessage();
                            $method_rates = [];
                        }
                        
                        $method_time = round((microtime(true) - $method_start) * 1000, 2);
                        $method_times[$method_id] = $method_time . ' ms';
                        
                        // Log any methods taking over 500ms
                        if ($method_time > 500) {
                            $log_data['slow_methods'][$method_id] = $method_time . ' ms';
                        }
                        
                        if ($method_rates) {
                            $rates = array_merge($rates, $method_rates);
                        }
                    }
                    
                    // Add method timing to logs
                    $log_data['method_times'] = $method_times;
                    
                    // Calculate shipping time first
                    $shipping_time = round((microtime(true) - $shipping_start) * 1000, 2);
                    
                    // Check for common API bottlenecks
                    if ($shipping_time > 1000) {
                        // Get the HTTP stats to see external API calls
                        global $wp_version;
                        $http_counts = [
                            'total_requests' => 0,
                            'total_time' => 0,
                            'average_time' => 0
                        ];
                        
                        // If using WordPress HTTP API, we can check the stats
                        if (function_exists('_get_http_stats')) {
                            $http_stats = _get_http_stats();
                            if (isset($http_stats['requests'])) {
                                $http_counts['total_requests'] = count($http_stats['requests']);
                                
                                // Sum up all request times
                                foreach ($http_stats['requests'] as $request) {
                                    if (isset($request['args']['timeout'])) {
                                        // Log timeout values
                                        $http_counts['timeouts'][] = $request['args']['timeout'];
                                    }
                                    if (isset($request['end_time']) && isset($request['start_time'])) {
                                        $request_time = $request['end_time'] - $request['start_time'];
                                        $http_counts['total_time'] += $request_time;
                                        
                                        // Log slow individual requests (over 500ms)
                                        if ($request_time > 0.5) {
                                            $http_counts['slow_requests'][] = [
                                                'url' => isset($request['url']) ? preg_replace('/\?.*/', '', $request['url']) : 'unknown',
                                                'time' => round($request_time * 1000) . ' ms'
                                            ];
                                        }
                                    }
                                }
                                
                                if ($http_counts['total_requests'] > 0) {
                                    $http_counts['average_time'] = round(($http_counts['total_time'] / $http_counts['total_requests']) * 1000, 2) . ' ms';
                                    $http_counts['total_time'] = round($http_counts['total_time'] * 1000, 2) . ' ms';
                                }
                            }
                        }
                        
                        $log_data['http_api'] = $http_counts;
                    }
                    
                    $log_data['steps']['calculate_shipping_only'] = $shipping_time . ' ms';
                    
                    // Log potential slow API calls
                    if ($shipping_time > 2000) { // If over 2 seconds
                        $log_data['warning'] = 'Shipping calculation is slow - may indicate API rate limiting or network issues';
                    }
                    
                    $log_data['steps']['calculate_test_shipping'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
                    $step_start = microtime(true);
                    
                    // Sort rates by price (lowest to highest) before formatting
                    uasort($rates, function($a, $b) {
                        $cost_a = floatval($a->cost);
                        $cost_b = floatval($b->cost);
                        
                        // Free shipping (cost = 0) should come first
                        if ($cost_a == 0 && $cost_b > 0) return -1;
                        if ($cost_b == 0 && $cost_a > 0) return 1;
                        
                        // Both free or both paid - sort by cost ascending (lowest first)
                        if ($cost_a == $cost_b) {
                            // If costs are equal, sort by delivery time (faster first)
                            $time_a = isset($a->meta_data['delivery_time']) ? intval($a->meta_data['delivery_time']) : 999;
                            $time_b = isset($b->meta_data['delivery_time']) ? intval($b->meta_data['delivery_time']) : 999;
                            return $time_a <=> $time_b;
                        }
                        
                        return $cost_a <=> $cost_b;
                    });
                    
                    // Format shipping methods (now sorted by price)
                    $shipping_methods = [];
                    foreach ($rates as $rate_id => $rate) {
                        $title = wc_cart_totals_shipping_method_label($rate);
                        $title = self::modifiedTitle($title, $rate);
                        $shipping_methods[$rate_id] = apply_filters('superfrete_ppscw_shipping_method_name', $title, $rate, $product_id, $variation_id);
                    }
                    
                    $log_data['steps']['get_shipping_methods'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
                    $step_start = microtime(true);
                    
                    $return['error'] = ''; // Empty the error to prevent notifications
                    $return['shipping_methods'] = self::messageTemplate($shipping_methods);
                    
                    $log_data['steps']['format_methods_html'] = round((microtime(true) - $step_start) * 1000, 2) . ' ms';
                    
                    // No need to restore cart or remove items - we never changed it
                    $log_data['steps']['restore_cart'] = 0;
                } else {
                    $return['error'] = __('Produto não encontrado.', 'superfrete');
                    $return['shipping_methods'] = '';
                }
            } else {
                $return['error'] = __('ID do produto não fornecido.', 'superfrete');
                $return['shipping_methods'] = '';
            }
            
            // Calculate total execution time
            $log_data['total_time'] = round((microtime(true) - $total_start_time) * 1000, 2) . ' ms';
            
            // Add log data to the return for debugging
            $return['performance_log'] = $log_data;
            
            // Log to the WordPress error log
            error_log('SuperFrete Performance Log: ' . wp_json_encode($log_data));
            
            echo wp_json_encode($return);
        }
        wp_die();
    }

    static function is_product_present_in_cart() {
        return false;
    }

    static function noShippingLocationInserted() {
        $country = WC()->customer->get_shipping_country();
        if (empty($country) || $country == 'default')
            return true;

        return false;
    }

    static function onlyPassErrorNotice($notice_type) {
        if (self::doingCalculation()) {
            return array('error');
        }
        return $notice_type;
    }

    static function doingCalculation() {
 
    
        if (wp_verify_nonce($_POST['superfrete_nonce'], 'superfrete_nonce') && !empty($_POST['calc_shipping']) ) {
            return true;
        }
        return false;
    }

    static function addTestProductForProperShippingCost() {
        $product_id = filter_input(INPUT_POST, 'product_id');
        $quantity = filter_input(INPUT_POST, 'quantity');
        if (empty($quantity))
            $quantity = 1;

        if ($product_id) {
            $variation_id = filter_input(INPUT_POST, 'variation_id');
            if (!$variation_id) {
                $variation_id = 0;
            }
            $item_key = self::addProductToCart($product_id, $variation_id, $quantity);
        } else {
            $item_key = "";
        }
        return $item_key;
    }

    static function addProductToCart($product_id, $variation_id, $quantity = 1) {
        $consider_product_quantity = apply_filters('superfrete_ppscw_consider_quantity_in_shipping_calculation', get_option('superfrete_consider_quantity_field', 'dont-consider-quantity-field'), $product_id, $variation_id, $quantity);

        if ($consider_product_quantity == 'dont-consider-quantity-field') {
            if (self::productExistInCart($product_id, $variation_id))
                return "";
            $quantity = 1;
        }

        if (!empty($variation_id)) {
            $variation = self::getVariationAttributes($variation_id);
        } else {
            $variation = array();
        }

        $item_key = WC()->cart->add_to_cart(
                $product_id,
                $quantity,
                $variation_id,
                $variation,
                array(
                    'superfrete_test_product_for_calculation' => '1',
                )
        );
        return $item_key;
    }

    static function getVariationAttributes($product_id) {

        if (empty($product_id))
            return array();

        $product = wc_get_product($product_id);

        if (!is_object($product))
            return array();

        $variation = array();
        $type = $product->get_type();
        if ($type == 'variation') {
            $parent_id = $product->get_parent_id();
            $parent_obj = wc_get_product($parent_id);
            $default_attributes = $parent_obj->get_default_attributes();
            $variation_attributes = $product->get_variation_attributes();
            // Get all parent attributes, needed to fetch attribute options.
            $parent_attributes = $parent_obj->get_attributes();
            $variation = self::getAttributes($variation_attributes, $default_attributes, $parent_attributes);
            return $variation;
        }
        return $variation;
    }

    static function getAttributes($variation_attributes, $default_attributes, $parent_attributes) {
        $list = array();
        foreach ($variation_attributes as $name => $value) {
            $att_name = str_replace('attribute_', "", $name);
            if (empty($value)) {
                $value = isset($default_attributes[$att_name]) ? $default_attributes[$att_name] : "";

                if (empty($value) && isset($parent_attributes[$att_name])) {
                    $attribute_obj = $parent_attributes[$att_name];
                    if ($attribute_obj->get_variation()) {
                        $options = $attribute_obj->get_options();
                        if (!empty($options)) {
                            // If taxonomy based, options are term IDs so convert the first one to slug.
                            if ($attribute_obj->is_taxonomy()) {
                                $term = get_term($options[0]);
                                if (!is_wp_error($term) && $term) {
                                    $value = $term->slug;
                                } else {
                                    $value = 'x';
                                }
                            } else {
                                // For custom attributes, simply use the first option.
                                $value = $options[0];
                            }
                        } else {
                            $value = 'x'; // Fallback if no options found.
                        }
                    } else {
                        $value = 'x'; // Fallback if attribute is not variation-enabled.
                    }
                }
            }
            $list[$name] = $value;
        }
        return $list;
    }

    static function productExistInCart($product_id, $variation_id) {
        if (!WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product_id && $cart_item['variation_id'] == $variation_id) {
                    return true;
                }
            }
        }
        return false;
    }

    static function get_shipping_packages() {
        return array(
            array(
                'contents' => array(),
                'contents_cost' => 0,
                'applied_coupons' => '',
                'user' => array(
                    'ID' => get_current_user_id(),
                ),
                'destination' => array(
                    'country' => self::get_customer()->get_shipping_country(),
                    'state' => self::get_customer()->get_shipping_state(),
                    'postcode' => self::get_customer()->get_shipping_postcode(),
                    'city' => self::get_customer()->get_shipping_city(),
                ),
                'cart_subtotal' => 0,
            ),
        );
    }

    static function get_customer() {
        return WC()->customer;
    }

    static function getShippingMethods($packages) {
        $shipping_methods = array();
        $product_id = filter_input(INPUT_POST, 'product_id');
        $variation_id = filter_input(INPUT_POST, 'variation_id');
        foreach ($packages as $package) {
            if (empty($package['rates']) || !is_array($package['rates']))
                break;

            foreach ($package['rates'] as $id => $rate) {
                $title = wc_cart_totals_shipping_method_label($rate);
                $title = self::modifiedTitle($title, $rate);
                $shipping_methods[$id] = apply_filters('superfrete_ppscw_shipping_method_name', $title, $rate, $product_id, $variation_id);
            }
        }
        return $shipping_methods;
    }

    static function noMethodAvailableMsg() {

        if (self::noShippingLocationInserted()) {
            return wp_kses_post(get_option('superfrete_no_address_added_yet', 'Informe seu Endereço para calcular'));
        } else {
            return wp_kses_post(get_option('superfrete_no_shipping_methods_msg', 'Nenhum método de envio encontrado'));
        }
    }

    static function disableAutoLoadEstimate() {
        $auto_loading = get_option('superfrete_auto_calculation', 'disabled'); // Changed default to disabled

        if ($auto_loading == 'enabled')
            return false;

        return true;
    }

    static function messageTemplate($shipping_methods) {
        $html = '';
        if (!empty($shipping_methods)) {
            $html .= '<div class="superfrete-shipping-methods">';
            $html .= '<h3>' . __('Opções de Entrega', 'superfrete') . '</h3>';
            
            foreach ($shipping_methods as $method_id => $method_name) {
                // Extract shipping method name and price
                $html .= '<div class="superfrete-shipping-method">';
                
                // Get just the method name (everything before the colon)
                $method_parts = explode(':', $method_name, 2);
                $method_label = trim(strip_tags($method_parts[0]));
                
                // Add method name to the HTML
                $html .= '<span class="superfrete-shipping-method-name">' . esc_html($method_label) . '</span>';
                
                // Handle price display - keeping the full HTML structure for WooCommerce price formatting
                if (count($method_parts) > 1 && !empty($method_parts[1])) {
                    // Check if the price part contains HTML or not
                    if (strpos($method_parts[1], '<span') !== false) {
                        // Keep the HTML formatting intact for the price
                        $html .= '<span class="superfrete-shipping-method-price">' . wp_kses_post($method_parts[1]) . '</span>';
                    } else {
                        // Add simple text price
                        $html .= '<span class="superfrete-shipping-method-price">' . esc_html(trim($method_parts[1])) . '</span>';
                    }
                } else {
                    // No price found, show as Free
                    $html .= '<span class="superfrete-shipping-method-price">Gratuito</span>';
                }
                
                $html .= '</div>';
            }
            
            $html .= '</div>';
        } else {
            $message = get_option('superfrete_no_rates_message', 'Desculpe, não encontramos métodos de envio para [country]. Por favor, verifique seu endereço ou entre em contato conosco.');
            
            $country = __('Brasil', 'superfrete');

            if (isset(WC()->customer)) {
                $country_code = self::get_customer()->get_shipping_country();
                if (!empty($country_code) && isset(WC()->countries) && $country_code !== 'default') {
                    $country = WC()->countries->countries[$country_code];
                }
            }

            $find_replace = [
                '[country]' => $country
            ];

            $message = str_replace(array_keys($find_replace), array_values($find_replace), $message);
            
            $html .= '<div class="superfrete-no-shipping-methods">' . esc_html($message) . '</div>';
        }

        return $html;
    }

    static function shortCode($message) {

        $country = __('Country', 'superfrete-product-page-shipping-calculator-woocommerce');

        if (isset(WC()->customer)) {
            $country_code = self::get_customer()->get_shipping_country();
            if (!empty($country_code) && isset(WC()->countries) && $country_code !== 'default') {
                $country = WC()->countries->countries[$country_code];
            }
        }

        $find_replace = array(
            '[country]' => $country
        );

        $message = str_replace(array_keys($find_replace), array_values($find_replace), $message);

        return $message;
    }

    function enableShippingCalculationWithoutAddress($val) {
        if (wp_verify_nonce($_POST['superfrete_nonce'], 'superfrete_nonce') && ((isset($_POST['action']) && $_POST['action'] === 'superfrete_cal_shipping') || (isset($_POST['action']) && $_POST['action'] === 'superfrete_save_address_form'))) {
            return null;
        }
        return $val;
    }

    static function modifiedTitle($title, $rate) {

        if (isset($rate->cost) && $rate->cost == 0) {
            $free_display_type = get_option('superfrete_free_shipping_price', 'nothing');

            if ($free_display_type == 'nothing')
                return $title;

            if ($free_display_type == 'zero') {
                $label = $rate->get_label();
                $title = $label . ': ' . wc_price($rate->cost);
            }
        }

        return $title;
    }

    static function loadLocation() {
        $location = ['calc_shipping_country' => '', 'calc_shipping_state' => '', 'calc_shipping_city' => '', 'calc_shipping_postcode' => ''];

        if (function_exists('WC') && isset(WC()->customer) && is_object(WC()->customer)) {
            $location['calc_shipping_country'] = WC()->customer->get_shipping_country();
            $location['calc_shipping_state'] = WC()->customer->get_shipping_state();
            $location['calc_shipping_city'] = WC()->customer->get_shipping_city();
            $location['calc_shipping_postcode'] = WC()->customer->get_shipping_postcode();
        }

        wp_send_json($location);
    }

    /**
     * Exibe o formulário de cálculo de frete na página do produto.
     */
    public function display_calculator_form() {
        include plugin_dir_path(__FILE__) . '../../templates/woocommerce/shipping-calculator.php';
    }
    /**
     * Adiciona os scripts necessários
     */
    public function enqueue_scripts() {
        $plugin_file = plugin_dir_path(__FILE__) . '../../superfrete.php';

        // Inclui função get_plugin_data se ainda não estiver disponível
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($plugin_file);
        $plugin_version = $plugin_data['Version'];
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'superfrete-calculator',
            plugin_dir_url(__FILE__) . '../../assets/scripts/superfrete-calculator.js',
            ['jquery'],
            $plugin_version, // Versão do script
            true
        );

        // Enqueue CSS
        wp_enqueue_style(
            'superfrete-calculator-style',
            plugin_dir_url(__FILE__) . '../../assets/styles/superfrete-calculator.css',
            [],
            $plugin_version
        );

        wp_localize_script('superfrete-calculator', 'superfrete_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
        
        // Localize script with additional settings
        wp_localize_script('superfrete-calculator', 'superfrete_setting', [
            'wc_ajax_url' => add_query_arg(['wc-ajax' => '%%endpoint%%'], home_url('/')),
            'auto_load' => true,
            'country_code' => 'BR',
            'i18n' => [
                'state_label' => __('Estado', 'superfrete'),
                'select_state_text' => __('Selecione um estado', 'superfrete')
            ]
        ]);
    }
}
