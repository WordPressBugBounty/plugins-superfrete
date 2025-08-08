<?php
/**
 * Shipping Calculator
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/shipping-calculator.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 4.0.0
 */
defined('ABSPATH') || exit;
?>
<?php
// Allow themes to add custom classes
$calculator_classes = apply_filters('superfrete_calculator_classes', array('superfrete-calculator-wrapper'));
$calculator_attributes = apply_filters('superfrete_calculator_attributes', array());

// Build attributes string
$attributes_string = '';
foreach ($calculator_attributes as $key => $value) {
    $attributes_string .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
}
?>
<div id="super-frete-shipping-calculator" class="<?php echo esc_attr(implode(' ', $calculator_classes)); ?>"<?php echo $attributes_string; ?>>
    <?php do_action('superfrete_before_calculate_form'); ?>
    
    <!-- CEP Input Section - Always Visible -->
    <div class="superfrete-input-section">
        <form class="superfrete-woocommerce-shipping-calculator" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" onsubmit="return false;">
            <div id="superfrete-error" class="superfrete-error"></div>
            
            <?php // Hidden fields for Brazil ?>
            <input type="hidden" name="calc_shipping_country" id="calc_shipping_country" value="BR">
            <input type="hidden" name="calc_shipping_state" id="calc_shipping_state" value="">
            <input type="hidden" name="calc_shipping_city" id="calc_shipping_city" value="">
            
            <?php // CEP input field - always visible ?>
            <div class="form-row form-row-wide" id="calc_shipping_postcode_field">
                <input type="text" class="input-text" value="<?php echo esc_attr(WC()->customer->get_shipping_postcode()); ?>" 
                       placeholder="<?php esc_attr_e('Digite seu CEP (00000-000)', 'superfrete'); ?>" 
                       name="calc_shipping_postcode" id="calc_shipping_postcode" />
            </div>

            <div class="form-row" id="superfrete-submit-container" style="display: none;">
                <button type="submit" name="calc_shipping" value="1" class="button superfrete-update-address-button">
                    <?php echo esc_html($update_address_btn_text); ?>
                </button>
            </div>
            
            <?php wp_nonce_field('superfrete_nonce', 'superfrete_nonce'); ?>
            <?php do_action('superfrete_after_calculate_form'); ?>
            
            <?php if (!empty($product_id)): ?>
                <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
                <input type="hidden" name="quantity" value="1">
                <?php if (is_object($product) && $product->is_type('variable')): ?>
                    <input type="hidden" name="variation_id" value="0" id="superfrete-variation-id">
                <?php endif; ?>
            <?php endif; ?>
            <input type="hidden" name="calc_shipping" value="x">
            <input type="hidden" name="action" value="superfrete_cal_shipping">
        </form>
    </div>

    <!-- Status Message Section -->
    <div id="superfrete-status-message" class="superfrete-status-message">
        <p><?php esc_html_e('💡 Digite seu CEP para calcular automaticamente o frete e prazo de entrega', 'superfrete'); ?></p>
    </div>
    
    <!-- Results Section -->
    <div id="superfrete-results-container" class="superfrete-results-container" style="display:none;"></div>
</div>