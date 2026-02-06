<?php

namespace SuperFrete_API\Controllers;

use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class DocumentFields
{
    public function __construct()
    {
        // Classic checkout support
        add_filter('woocommerce_billing_fields', array($this, 'checkout_billing_fields'), 10);
        add_filter('woocommerce_checkout_fields', array($this, 'add_document_to_checkout_fields'), 10);
        add_filter('woocommerce_checkout_posted_data', array($this, 'merge_woofunnels_document_data'), 5);
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
    }

    /**
     * Check if WooFunnels Aero Checkout is active
     */
    private function is_woofunnels_active()
    {
        return class_exists('WFACP_Common') || class_exists('WFACP_Template_Common');
    }

    /**
     * Merge WooFunnels billing_cpf/billing_cnpj data into billing_document
     * This runs before validation to ensure SuperFrete can read the document
     */
    public function merge_woofunnels_document_data($data)
    {
        if (!$this->is_woofunnels_active()) {
            return $data;
        }

        Logger::debug('WooFunnels detected, checking for billing_cpf/billing_cnpj fields', 'DocumentFields');

        // If billing_document already has a value, don't override it
        if (!empty($data['billing_document'])) {
            Logger::debug('billing_document already set: ' . $data['billing_document'], 'DocumentFields');
            return $data;
        }

        // Check for CPF field (individuals)
        if (!empty($_POST['billing_cpf'])) {
            $cpf = sanitize_text_field($_POST['billing_cpf']);
            $data['billing_document'] = $cpf;
            Logger::debug('Merged billing_cpf into billing_document: ' . $cpf, 'DocumentFields');
            return $data;
        }

        // Check for CNPJ field (legal entities)
        if (!empty($_POST['billing_cnpj'])) {
            $cnpj = sanitize_text_field($_POST['billing_cnpj']);
            $data['billing_document'] = $cnpj;
            Logger::debug('Merged billing_cnpj into billing_document: ' . $cnpj, 'DocumentFields');
            return $data;
        }

        Logger::debug('No billing_cpf or billing_cnpj found in WooFunnels checkout', 'DocumentFields');
        return $data;
    }

    /**
     * New checkout billing fields - following reference plugin pattern
     */
    public function checkout_billing_fields($fields)
    {
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

        return $new_fields;
    }

    /**
     * Add document field to checkout fields (alternative hook)
     */
    public function add_document_to_checkout_fields($fields)
    {
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
        }

        return $fields;
    }

    /**
     * Debug what type of checkout is being used (only logs when explicitly called for debugging)
     */
    public function debug_checkout_type()
    {
        // This function is kept for manual debugging but doesn't log automatically
        // to avoid polluting logs on every page load
    }

    /**
     * Register checkout field for WooCommerce Blocks
     */
    public function register_checkout_field_block()
    {
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
        } else {

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
        Logger::debug('Validating document via Checkout Field API: ' . $field_value, 'DocumentFields');

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
        Logger::debug('Saving document from blocks', 'DocumentFields');

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
        Logger::debug('Validating document in blocks', 'DocumentFields');
        
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
        Logger::debug('save_document_from_checkout_blocks called for order ' . $order_id, 'DocumentFields');
        Logger::debug('Posted data keys: ' . implode(', ', array_keys($posted_data)), 'DocumentFields');

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
                        Logger::debug('Found document in extensions: ' . $document, 'DocumentFields');
                        break;
                    }
                } else {
                    $document = sanitize_text_field($posted_data[$key]);
                    Logger::debug('Found document in ' . $key . ': ' . $document, 'DocumentFields');
                    break;
                }
            }
        }

        if (!empty($document)) {
            $order->update_meta_data('_billing_document', $document);
            $order->update_meta_data('_superfrete_document', $document);
            $order->save();
            Logger::debug('Document saved from blocks checkout: ' . $document, 'DocumentFields');
        } else {
            Logger::debug('No document found in blocks checkout data', 'DocumentFields');
        }
    }

    /**
     * Save document from Store API order data
     */
    public function save_document_from_store_api($order_data, $request)
    {
        Logger::debug('save_document_from_store_api called', 'DocumentFields');
        Logger::debug('Order data keys: ' . implode(', ', array_keys($order_data)), 'DocumentFields');

        // Check if our field is in the request
        $document_value = '';

        // Try to get the field value from various possible locations
        if (method_exists($request, 'get_param')) {
            $extensions = $request->get_param('extensions');
            if (is_array($extensions) && isset($extensions['superfrete']) && isset($extensions['superfrete']['document'])) {
                $document_value = sanitize_text_field($extensions['superfrete']['document']);
                Logger::debug('Found document in extensions: ' . $document_value, 'DocumentFields');
            }

            // Also try direct parameter
            $direct_value = $request->get_param('superfrete/document');
            if ($direct_value) {
                $document_value = sanitize_text_field($direct_value);
                Logger::debug('Found document in direct param: ' . $document_value, 'DocumentFields');
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

            Logger::debug('Added document to order meta data: ' . $document_value, 'DocumentFields');
        } else {
            Logger::debug('No document found in Store API request', 'DocumentFields');
        }

        return $order_data;
    }

    /**
     * Valid checkout fields - following reference plugin pattern
     */
    public function valid_checkout_fields()
    {
        Logger::debug('valid_checkout_fields called', 'DocumentFields');

        $document = '';

        // Try to get document from billing_document first (standard or merged from WooFunnels)
        if (!empty($_POST['billing_document'])) {
            $document = sanitize_text_field($_POST['billing_document']);
        }

        // Fallback: Check WooFunnels CPF field
        if (empty($document) && !empty($_POST['billing_cpf'])) {
            $document = sanitize_text_field($_POST['billing_cpf']);
            Logger::debug('Using billing_cpf for validation: ' . $document, 'DocumentFields');
        }

        // Fallback: Check WooFunnels CNPJ field
        if (empty($document) && !empty($_POST['billing_cnpj'])) {
            $document = sanitize_text_field($_POST['billing_cnpj']);
            Logger::debug('Using billing_cnpj for validation: ' . $document, 'DocumentFields');
        }

        if (empty($document)) {
            wc_add_notice(sprintf('<strong>%s</strong> %s.', __('CPF/CNPJ', 'superfrete'), __('é um campo obrigatório', 'superfrete')), 'error');
            return;
        }

        if (!$this->is_valid_document($document)) {
            wc_add_notice(sprintf('<strong>%s</strong> %s.', __('CPF/CNPJ', 'superfrete'), __('não é válido', 'superfrete')), 'error');
        }
    }

    /**
     * Save document field to order meta
     */
    public function save_document_field($order_id)
    {
        Logger::debug('save_document_field called for order ' . $order_id, 'DocumentFields');

        // Handle both classic checkout and blocks checkout
        $document = '';

        // Try to get from POST (classic checkout)
        if (!empty($_POST['billing_document'])) {
            $document = sanitize_text_field($_POST['billing_document']);
            Logger::debug('Document from POST billing_document: ' . $document, 'DocumentFields');
        }

        // Try to get from blocks checkout field
        if (empty($document) && !empty($_POST['superfrete--document'])) {
            $document = sanitize_text_field($_POST['superfrete--document']);
            Logger::debug('Document from blocks field: ' . $document, 'DocumentFields');
        }

        // Also try the namespaced field format
        if (empty($document) && !empty($_POST['superfrete/document'])) {
            $document = sanitize_text_field($_POST['superfrete/document']);
            Logger::debug('Document from namespaced field: ' . $document, 'DocumentFields');
        }

        // Try WooFunnels CPF field
        if (empty($document) && !empty($_POST['billing_cpf'])) {
            $document = sanitize_text_field($_POST['billing_cpf']);
            Logger::debug('Document from WooFunnels billing_cpf: ' . $document, 'DocumentFields');
        }

        // Try WooFunnels CNPJ field
        if (empty($document) && !empty($_POST['billing_cnpj'])) {
            $document = sanitize_text_field($_POST['billing_cnpj']);
            Logger::debug('Document from WooFunnels billing_cnpj: ' . $document, 'DocumentFields');
        }

        if (!empty($document)) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_billing_document', $document);
                $order->update_meta_data('_superfrete_document', $document);
                $order->save();
                Logger::debug('Document saved to order meta: ' . $document, 'DocumentFields');
            }
        } else {
            Logger::debug('No document found in POST data', 'DocumentFields');
            Logger::debug('POST keys: ' . implode(', ', array_keys($_POST)), 'DocumentFields');
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