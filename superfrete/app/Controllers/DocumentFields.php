<?php

namespace SuperFrete_API\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class DocumentFields
{
    public function __construct()
    {
        error_log('SuperFrete DocumentFields: Constructor called - adding both classic and blocks support');
        
        // Classic checkout support
        add_filter('woocommerce_billing_fields', array($this, 'checkout_billing_fields'), 10);
        add_filter('woocommerce_checkout_fields', array($this, 'add_document_to_checkout_fields'), 10);
        add_action('woocommerce_checkout_process', array($this, 'valid_checkout_fields'), 10);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_document_field'));
        
        // WooCommerce Blocks support
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_field_block'));
        add_action('woocommerce_store_api_checkout_update_customer_from_request', array($this, 'save_document_from_blocks'), 10, 2);
        add_action('woocommerce_rest_checkout_process_payment', array($this, 'validate_document_in_blocks'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'save_document_from_checkout_blocks'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_data', array($this, 'save_document_from_store_api'), 10, 2);
        
        // Display in admin and customer areas
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_document_in_admin'));
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_document_in_order'));
        
        // Add action to check what type of checkout is being used
        add_action('wp_footer', array($this, 'debug_checkout_type'));
        
        error_log('SuperFrete DocumentFields: All hooks added for both classic and blocks checkout');
    }

    /**
     * New checkout billing fields - following reference plugin pattern
     */
    public function checkout_billing_fields($fields)
    {
        error_log('SuperFrete DocumentFields: checkout_billing_fields called with ' . count($fields) . ' fields');
        
        $new_fields = array();

        // Keep existing fields first
        foreach ($fields as $key => $field) {
            $new_fields[$key] = $field;
        }

        // Add document field after first/last name (similar to reference plugin)
        $new_fields['billing_document'] = array(
            'label'    => __('CPF/CNPJ', 'superfrete'),
            'placeholder' => __('Digite seu CPF ou CNPJ', 'superfrete'),
            'class'    => array('form-row-wide'),
            'required' => true,
            'type'     => 'text',
            'priority' => 25,
            'custom_attributes' => array(
                'pattern' => '[0-9\.\-\/]*',
                'maxlength' => '18'
            )
        );

        error_log('SuperFrete DocumentFields: Document field added to billing fields');
        return $new_fields;
    }

    /**
     * Add document field to checkout fields (alternative hook)
     */
    public function add_document_to_checkout_fields($fields)
    {
        error_log('SuperFrete DocumentFields: add_document_to_checkout_fields called');
        
        if (isset($fields['billing'])) {
            $fields['billing']['billing_document'] = array(
                'label'    => __('CPF/CNPJ', 'superfrete'),
                'placeholder' => __('Digite seu CPF ou CNPJ', 'superfrete'),
                'class'    => array('form-row-wide'),
                'required' => true,
                'type'     => 'text',
                'priority' => 25,
                'custom_attributes' => array(
                    'pattern' => '[0-9\.\-\/]*',
                    'maxlength' => '18'
                )
            );
            error_log('SuperFrete DocumentFields: Document field added via checkout_fields hook');
        }
        
        return $fields;
    }

    /**
     * Debug what type of checkout is being used
     */
    public function debug_checkout_type()
    {
        if (is_checkout()) {
            error_log('SuperFrete DocumentFields: On checkout page - checking for blocks');
            
            // Check if blocks checkout is being used
            if (has_block('woocommerce/checkout')) {
                error_log('SuperFrete DocumentFields: WooCommerce Blocks checkout detected');
            } else {
                error_log('SuperFrete DocumentFields: Classic checkout detected');
            }
            
            // Also check for checkout shortcode
            global $post;
            if ($post && has_shortcode($post->post_content, 'woocommerce_checkout')) {
                error_log('SuperFrete DocumentFields: Checkout shortcode found');
            }
        }
    }

    /**
     * Register checkout field for WooCommerce Blocks
     */
    public function register_checkout_field_block()
    {
        error_log('SuperFrete DocumentFields: Registering blocks checkout field using new Checkout Field API');
        
        // Use the new WooCommerce 8.6+ Checkout Field API
        if (function_exists('woocommerce_register_additional_checkout_field')) {
            woocommerce_register_additional_checkout_field(array(
                'id'            => 'superfrete/document',
                'label'         => __('CPF/CNPJ', 'superfrete'),
                'location'      => 'contact',
                'type'          => 'text',
                'required'      => true,
                'attributes'    => array(
                    'data-1p-ignore' => 'true',
                    'data-lpignore'  => 'true',
                    'autocomplete'   => 'off',
                ),
                'validate_callback' => array($this, 'validate_document_callback'),
                'sanitize_callback' => 'sanitize_text_field',
            ));
            
            error_log('SuperFrete DocumentFields: Document field registered using Checkout Field API');
        } else {
            error_log('SuperFrete DocumentFields: Checkout Field API not available, trying legacy Store API approach');
            
            // Fallback to legacy Store API approach
            if (function_exists('woocommerce_store_api_register_endpoint_data')) {
                woocommerce_store_api_register_endpoint_data(array(
                    'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
                    'namespace'       => 'superfrete',
                    'data_callback'   => array($this, 'add_checkout_field_data'),
                    'schema_callback' => array($this, 'add_checkout_field_schema'),
                ));
            }
        }
    }
    
    /**
     * Validate document callback for Checkout Field API
     */
    public function validate_document_callback($field_value, $errors)
    {
        error_log('SuperFrete DocumentFields: Validating document via Checkout Field API: ' . $field_value);
        
        if (empty($field_value)) {
            return new \WP_Error('superfrete_document_required', __('CPF/CNPJ é um campo obrigatório.', 'superfrete'));
        }
        
        if (!$this->is_valid_document($field_value)) {
            return new \WP_Error('superfrete_document_invalid', __('CPF/CNPJ não é válido.', 'superfrete'));
        }
        
        return true;
    }

    /**
     * Add checkout field data for blocks
     */
    public function add_checkout_field_data()
    {
        return array(
            'document' => ''
        );
    }

    /**
     * Add checkout field schema for blocks
     */
    public function add_checkout_field_schema()
    {
        return array(
            'document' => array(
                'description' => 'Customer document (CPF/CNPJ)',
                'type'        => 'string',
                'context'     => array('view', 'edit'),
                'required'    => true,
            ),
        );
    }

    /**
     * Save document from blocks checkout
     */
    public function save_document_from_blocks($customer, $request)
    {
        error_log('SuperFrete DocumentFields: Saving document from blocks');
        
        $document = $request->get_param('billing_document');
        if ($document) {
            $customer->update_meta_data('billing_document', sanitize_text_field($document));
        }
    }

    /**
     * Validate document in blocks checkout
     */
    public function validate_document_in_blocks($request, $errors)
    {
        error_log('SuperFrete DocumentFields: Validating document in blocks');
        
        $document = $request->get_param('billing_document');
        if (empty($document)) {
            $errors->add('billing_document_required', __('CPF/CNPJ é um campo obrigatório.', 'superfrete'));
        } elseif (!$this->is_valid_document($document)) {
            $errors->add('billing_document_invalid', __('CPF/CNPJ não é válido.', 'superfrete'));
        }
    }

    /**
     * Save document from checkout blocks using order processed hook
     */
    public function save_document_from_checkout_blocks($order_id, $posted_data, $order)
    {
        error_log('SuperFrete DocumentFields: save_document_from_checkout_blocks called for order ' . $order_id);
        error_log('SuperFrete DocumentFields: Posted data keys: ' . implode(', ', array_keys($posted_data)));
        
        // Try to find the document field in posted data
        $document = '';
        
        // Check various possible field names for blocks checkout
        $possible_keys = array(
            'superfrete/document',
            'superfrete--document', 
            'billing_document',
            'extensions',
        );
        
        foreach ($possible_keys as $key) {
            if (isset($posted_data[$key])) {
                if ($key === 'extensions' && is_array($posted_data[$key])) {
                    // Look for our field in extensions array
                    if (isset($posted_data[$key]['superfrete']) && isset($posted_data[$key]['superfrete']['document'])) {
                        $document = sanitize_text_field($posted_data[$key]['superfrete']['document']);
                        error_log('SuperFrete DocumentFields: Found document in extensions: ' . $document);
                        break;
                    }
                } else {
                    $document = sanitize_text_field($posted_data[$key]);
                    error_log('SuperFrete DocumentFields: Found document in ' . $key . ': ' . $document);
                    break;
                }
            }
        }
        
        if (!empty($document)) {
            $order->update_meta_data('_billing_document', $document);
            $order->update_meta_data('_superfrete_document', $document);
            $order->save();
            error_log('SuperFrete DocumentFields: Document saved from blocks checkout: ' . $document);
        } else {
            error_log('SuperFrete DocumentFields: No document found in blocks checkout data');
        }
    }

    /**
     * Save document from Store API order data
     */
    public function save_document_from_store_api($order_data, $request)
    {
        error_log('SuperFrete DocumentFields: save_document_from_store_api called');
        error_log('SuperFrete DocumentFields: Order data keys: ' . implode(', ', array_keys($order_data)));
        
        // Check if our field is in the request
        $document_value = '';
        
        // Try to get the field value from various possible locations
        if (method_exists($request, 'get_param')) {
            $extensions = $request->get_param('extensions');
            if (is_array($extensions) && isset($extensions['superfrete']) && isset($extensions['superfrete']['document'])) {
                $document_value = sanitize_text_field($extensions['superfrete']['document']);
                error_log('SuperFrete DocumentFields: Found document in extensions: ' . $document_value);
            }
            
            // Also try direct parameter
            $direct_value = $request->get_param('superfrete/document');
            if ($direct_value) {
                $document_value = sanitize_text_field($direct_value);
                error_log('SuperFrete DocumentFields: Found document in direct param: ' . $document_value);
            }
        }
        
        // If we found a document value, add it to the order data meta
        if (!empty($document_value)) {
            if (!isset($order_data['meta_data'])) {
                $order_data['meta_data'] = array();
            }
            
            $order_data['meta_data'][] = array(
                'key' => '_billing_document',
                'value' => $document_value
            );
            $order_data['meta_data'][] = array(
                'key' => '_superfrete_document', 
                'value' => $document_value
            );
            
            error_log('SuperFrete DocumentFields: Added document to order meta data: ' . $document_value);
        } else {
            error_log('SuperFrete DocumentFields: No document found in Store API request');
        }
        
        return $order_data;
    }

    /**
     * Valid checkout fields - following reference plugin pattern
     */
    public function valid_checkout_fields()
    {
        error_log('SuperFrete DocumentFields: valid_checkout_fields called');
        
        if (empty($_POST['billing_document'])) {
            wc_add_notice(sprintf('<strong>%s</strong> %s.', __('CPF/CNPJ', 'superfrete'), __('é um campo obrigatório', 'superfrete')), 'error');
            return;
        }

        $document = sanitize_text_field($_POST['billing_document']);
        
        if (!$this->is_valid_document($document)) {
            wc_add_notice(sprintf('<strong>%s</strong> %s.', __('CPF/CNPJ', 'superfrete'), __('não é válido', 'superfrete')), 'error');
        }
    }

    /**
     * Save document field to order meta
     */
    public function save_document_field($order_id)
    {
        error_log('SuperFrete DocumentFields: save_document_field called for order ' . $order_id);
        
        // Handle both classic checkout and blocks checkout
        $document = '';
        
        // Try to get from POST (classic checkout)
        if (!empty($_POST['billing_document'])) {
            $document = sanitize_text_field($_POST['billing_document']);
            error_log('SuperFrete DocumentFields: Document from POST: ' . $document);
        }
        
        // Try to get from blocks checkout field
        if (empty($document) && !empty($_POST['superfrete--document'])) {
            $document = sanitize_text_field($_POST['superfrete--document']);
            error_log('SuperFrete DocumentFields: Document from blocks field: ' . $document);
        }
        
        // Also try the namespaced field format
        if (empty($document) && !empty($_POST['superfrete/document'])) {
            $document = sanitize_text_field($_POST['superfrete/document']);
            error_log('SuperFrete DocumentFields: Document from namespaced field: ' . $document);
        }
        
        if (!empty($document)) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_billing_document', $document);
                $order->update_meta_data('_superfrete_document', $document);
                $order->save();
                error_log('SuperFrete DocumentFields: Document saved to order meta: ' . $document);
            }
        } else {
            error_log('SuperFrete DocumentFields: No document found in POST data');
            error_log('SuperFrete DocumentFields: POST keys: ' . implode(', ', array_keys($_POST)));
        }
    }

    /**
     * Display document in admin order details
     */
    public function display_document_in_admin($order)
    {
        $document = $order->get_meta('_billing_document');
        if ($document) {
            echo '<p><strong>' . __('CPF/CNPJ:', 'superfrete') . '</strong> ' . esc_html($document) . '</p>';
        }
    }

    /**
     * Display document in customer order details
     */
    public function display_document_in_order($order)
    {
        $document = $order->get_meta('_billing_document');
        if ($document) {
            echo '<p><strong>' . __('CPF/CNPJ:', 'superfrete') . '</strong> ' . esc_html($document) . '</p>';
        }
    }

    /**
     * Validate if document is a valid CPF or CNPJ - using reference plugin algorithms
     */
    private function is_valid_document($document)
    {
        $document = preg_replace('/[^0-9]/', '', $document);
        
        if (strlen($document) == 11) {
            return $this->is_cpf($document);
        } elseif (strlen($document) == 14) {
            return $this->is_cnpj($document);
        }
        
        return false;
    }

    /**
     * Checks if the CPF is valid - exact copy from reference plugin
     */
    private function is_cpf($cpf)
    {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        if (11 !== strlen($cpf) || preg_match('/^([0-9])\1+$/', $cpf)) {
            return false;
        }

        $digit = substr($cpf, 0, 9);

        for ($j = 10; $j <= 11; $j++) {
            $sum = 0;

            for ($i = 0; $i < $j - 1; $i++) {
                $sum += ($j - $i) * intval($digit[$i]);
            }

            $summod11        = $sum % 11;
            $digit[$j - 1] = $summod11 < 2 ? 0 : 11 - $summod11;
        }

        return intval($digit[9]) === intval($cpf[9]) && intval($digit[10]) === intval($cpf[10]);
    }

    /**
     * Checks if the CNPJ is valid - exact copy from reference plugin
     */
    private function is_cnpj($cnpj)
    {
        $cnpj = sprintf('%014s', preg_replace('{\D}', '', $cnpj));

        if (14 !== strlen($cnpj) || 0 === intval(substr($cnpj, -4))) {
            return false;
        }

        for ($t = 11; $t < 13;) {
            for ($d = 0, $p = 2, $c = $t; $c >= 0; $c--, ($p < 9) ? $p++ : $p = 2) {
                $d += $cnpj[$c] * $p;
            }

            $d = ((10 * $d) % 11) % 10;
            if (intval($cnpj[++$t]) !== $d) {
                return false;
            }
        }

        return true;
    }
}

new \SuperFrete_API\Controllers\DocumentFields();