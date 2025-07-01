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
        if (!class_exists('WC_Shortcode_Cart')) {
            include_once WC_ABSPATH . 'includes/shortcodes/class-wc-shortcode-cart.php';
        }
    
        
        if (self::doingCalculation()) {

            
              if ((isset($_POST['action_auto_load']) && self::disableAutoLoadEstimate()) || empty($_POST['calc_shipping_postcode']) || !isset($_POST['calc_shipping_postcode'])) {
            
                $return['shipping_methods'] = sprintf('<div class="superfrete-alert">%s</div>', esc_html(get_option('superfrete_no_address_added_yet', 'Informe seu Endereço para calcular')));
                wp_send_json($return);
            }
                   
            /*
              $return = array();
              WC_Shortcode_Cart::calculate_shipping();
              WC()->cart->calculate_totals();


              $item_key = self::addTestProductForProperShippingCost();


              if(WC()->cart->get_cart_contents_count() == 0 ){
              $blank_package = self::get_shipping_packages();
              WC()->shipping()->calculate_shipping($blank_package );
              }
              $packages = WC()->shipping()->get_packages();
              $shipping_methods = self::getShippingMethods($packages);
              $return['error'] = wc_print_notices(true);
              //error_log(print_r($return,true));
              //wc_clear_notices();
              $return['shipping_methods'] = self::messageTemplate($shipping_methods);
              echo json_encode($return);

              if($item_key){
              WC()->cart->remove_cart_item($item_key);
              }
             */

            $return = array();
            \WC_Shortcode_Cart::calculate_shipping();
            WC()->cart->calculate_totals();
 
         

   
                    $original_cart = WC()->cart->get_cart();
                    WC()->cart->empty_cart();
                    $item_key = self::addTestProductForProperShippingCost();

                    WC()->shipping()->calculate_shipping();
                    WC()->cart->calculate_totals();
                    $packages = WC()->shipping()->get_packages();
                                
   
           
            

            $shipping_methods = self::getShippingMethods($packages);
            $return['error'] = wc_print_notices(true);
            $return['shipping_methods'] = self::messageTemplate($shipping_methods);
            echo wp_json_encode($return);

            if (!empty($original_cart)) {
                WC()->cart->set_cart_contents($original_cart);
                WC()->cart->calculate_totals();
            }

            if ($item_key) {
                WC()->cart->remove_cart_item($item_key);
            }
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
        $auto_loading = get_option('superfrete_auto_calculation', 'enabled');

        if ($auto_loading == 'enabled')
            return false;

        return true;
    }

    static function messageTemplate($shipping_methods) {



        $message_above = get_option('superfrete_above_shipping_methods', 'Metódos de Envio Disponíveis');

        $message_above = self::shortCode($message_above);

        if (is_array($shipping_methods) && !empty($shipping_methods)) {
            $html = '';
            foreach ($shipping_methods as $id => $method) {
                $html .= sprintf('<li id="%s">%s</li>', esc_attr($id), $method);
            }
            if (!empty($html)) {
                $shipping_methods_msg = '<ul class="superfrete-methods">' . $html . '</ul>';
            } else {
                $shipping_methods_msg = "";
            }
        } else {
            $shipping_methods_msg = "";
        }

        $when_shipping_msg = wp_kses_post($message_above) . '<br>' . $shipping_methods_msg;

        $msg = is_array($shipping_methods) && !empty($shipping_methods) ? $when_shipping_msg : self::noMethodAvailableMsg();

        return sprintf('<div class="superfrete-alert">%s</div>', $msg);
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
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
          $plugin_data = get_plugin_data( $plugin_file );
    $plugin_version = $plugin_data['Version'];
        wp_enqueue_script(
                'superfrete-calculator',
                plugin_dir_url(__FILE__) . '../../assets/scripts/superfrete-calculator.js',
                ['jquery'],
              $plugin_version, // Versão do script
                null,
                true
        );

        wp_localize_script('superfrete-calculator', 'superfrete_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }
}
