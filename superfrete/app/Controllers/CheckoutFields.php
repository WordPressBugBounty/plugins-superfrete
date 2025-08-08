<?php

namespace SuperFrete_API\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CheckoutFields
{
    public function __construct()
    {
        // Classic checkout support
        add_filter('woocommerce_checkout_fields', array($this, 'add_required_checkout_fields'), 20);
        add_filter('woocommerce_billing_fields', array($this, 'customize_billing_fields'), 20);
        add_filter('woocommerce_shipping_fields', array($this, 'customize_shipping_fields'), 20);
        
        // WooCommerce Blocks support
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_fields_block'));
        
        // Add helper text about N/A option
        add_action('woocommerce_before_checkout_form', array($this, 'add_number_field_notice'));
    }

    /**
     * Add notice about N/A option for number field
     */
    public function add_number_field_notice()
    {
        ?>
        <div class="woocommerce-info superfrete-number-notice">
            <strong>Nota:</strong> Se o endereço não possui número, digite "N/A" ou "S/N" no campo Número.
        </div>
        <?php
    }

    /**
     * Customize billing fields
     */
    public function customize_billing_fields($fields)
    {
        // Make number field required if it exists
        if (isset($fields['billing_number'])) {
            $fields['billing_number']['required'] = true;
            $fields['billing_number']['label'] = __('Número', 'superfrete');
            $fields['billing_number']['placeholder'] = __('Número ou N/A', 'superfrete');
            $fields['billing_number']['description'] = __('Digite N/A se não houver número', 'superfrete');
        }
        
        // Make neighborhood field required if it exists
        if (isset($fields['billing_neighborhood'])) {
            $fields['billing_neighborhood']['required'] = true;
            $fields['billing_neighborhood']['label'] = __('Bairro', 'superfrete');
        }
        
        return $fields;
    }

    /**
     * Customize shipping fields
     */
    public function customize_shipping_fields($fields)
    {
        // Make number field required if it exists
        if (isset($fields['shipping_number'])) {
            $fields['shipping_number']['required'] = true;
            $fields['shipping_number']['label'] = __('Número', 'superfrete');
            $fields['shipping_number']['placeholder'] = __('Número ou N/A', 'superfrete');
            $fields['shipping_number']['description'] = __('Digite N/A se não houver número', 'superfrete');
        }
        
        // Make neighborhood field required if it exists
        if (isset($fields['shipping_neighborhood'])) {
            $fields['shipping_neighborhood']['required'] = true;
            $fields['shipping_neighborhood']['label'] = __('Bairro', 'superfrete');
        }
        
        return $fields;
    }

    /**
     * Add required checkout fields
     */
    public function add_required_checkout_fields($fields)
    {
        // Add number field to billing if it doesn't exist
        if (!isset($fields['billing']['billing_number'])) {
            $fields['billing']['billing_number'] = array(
                'label'       => __('Número', 'superfrete'),
                'placeholder' => __('Número ou N/A', 'superfrete'),
                'required'    => true,
                'class'       => array('form-row-wide'),
                'priority'    => 61, // After address_2
                'description' => __('Digite N/A se não houver número', 'superfrete'),
            );
        } else {
            // Make it required if it exists
            $fields['billing']['billing_number']['required'] = true;
            $fields['billing']['billing_number']['placeholder'] = __('Número ou N/A', 'superfrete');
            $fields['billing']['billing_number']['description'] = __('Digite N/A se não houver número', 'superfrete');
        }
        
        // Add neighborhood field to billing if it doesn't exist
        if (!isset($fields['billing']['billing_neighborhood'])) {
            $fields['billing']['billing_neighborhood'] = array(
                'label'       => __('Bairro', 'superfrete'),
                'placeholder' => __('Bairro', 'superfrete'),
                'required'    => true,
                'class'       => array('form-row-wide'),
                'priority'    => 65, // After number
            );
        } else {
            // Make it required if it exists
            $fields['billing']['billing_neighborhood']['required'] = true;
        }
        
        // Add number field to shipping if it doesn't exist
        if (!isset($fields['shipping']['shipping_number'])) {
            $fields['shipping']['shipping_number'] = array(
                'label'       => __('Número', 'superfrete'),
                'placeholder' => __('Número ou N/A', 'superfrete'),
                'required'    => true,
                'class'       => array('form-row-wide'),
                'priority'    => 61, // After address_2
                'description' => __('Digite N/A se não houver número', 'superfrete'),
            );
        } else {
            // Make it required if it exists
            $fields['shipping']['shipping_number']['required'] = true;
            $fields['shipping']['shipping_number']['placeholder'] = __('Número ou N/A', 'superfrete');
            $fields['shipping']['shipping_number']['description'] = __('Digite N/A se não houver número', 'superfrete');
        }
        
        // Add neighborhood field to shipping if it doesn't exist
        if (!isset($fields['shipping']['shipping_neighborhood'])) {
            $fields['shipping']['shipping_neighborhood'] = array(
                'label'       => __('Bairro', 'superfrete'),
                'placeholder' => __('Bairro', 'superfrete'),
                'required'    => true,
                'class'       => array('form-row-wide'),
                'priority'    => 65, // After number
            );
        } else {
            // Make it required if it exists
            $fields['shipping']['shipping_neighborhood']['required'] = true;
        }
        
        return $fields;
    }

    /**
     * Register checkout fields for WooCommerce Blocks
     */
    public function register_checkout_fields_block()
    {
        if (function_exists('woocommerce_register_additional_checkout_field')) {
            // Register shipping number field for blocks
            woocommerce_register_additional_checkout_field(array(
                'id'            => 'shipping/number',
                'label'         => __('Número', 'superfrete'),
                'location'      => 'address',
                'type'          => 'text',
                'required'      => true,
                'attributes'    => array(
                    'data-1p-ignore' => 'true',
                    'data-lpignore'  => 'true',
                    'autocomplete'   => 'off',
                ),
            ));
            
            // Register shipping neighborhood field for blocks
            woocommerce_register_additional_checkout_field(array(
                'id'            => 'shipping/neighborhood',
                'label'         => __('Bairro', 'superfrete'),
                'location'      => 'address',
                'type'          => 'text',
                'required'      => true,
                'attributes'    => array(
                    'data-1p-ignore' => 'true',
                    'data-lpignore'  => 'true',
                    'autocomplete'   => 'off',
                ),
            ));
        }
    }
}

new \SuperFrete_API\Controllers\CheckoutFields();