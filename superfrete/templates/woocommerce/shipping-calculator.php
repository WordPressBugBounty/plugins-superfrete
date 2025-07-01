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
$closed = get_option('superfrete_default_form_display', 'closed');
if ($closed == 'open') {
    $style = '';
} else {
    $style = ' display:none; ';
}
?>
<div id="super-frete-shipping-calculator" >
    <div class="superfrete-container">
        <?php do_action('superfrete_before_calculate_button'); ?>
        <form class="superfrete-woocommerce-shipping-calculator" action="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" method="post" onsubmit="return false;">

            <?php printf('<a href="/" class="button superfrete-shipping-calculator-button" rel="nofollow">%s</a>', esc_html($button_text)); ?>

            <?php do_action('superfrete_after_calculate_button'); ?>

            <section class="superfrete-shipping-calculator-form" style="<?php echo esc_attr( $style ); ?>">
                <?php do_action('superfrete_before_calculate_form'); ?>
                <div id="superfrete-error" class="superfrete-error"></div>
                <?php if (apply_filters('woocommerce_shipping_calculator_enable_country', true)) : ?>
                    <?php
                    $countries = WC()->countries->get_shipping_countries();
                    $remove_country_field = get_option('superfrete_remove_country', 0);
                    ?>
                    <?php
                    if (count($countries) == 1 && !empty($remove_country_field)) {
                        $first_country_key = array_key_first($countries);
                        ?>	
                        <input type="hidden" name="calc_shipping_country" id="calc_shipping_country" class="country_to_state country_select" rel="calc_shipping_state" value="<?php echo esc_attr($first_country_key); ?>">
                    <?php } else { ?>
                        <p class="form-row form-row-wide" id="calc_shipping_country_field">
                            <select name="calc_shipping_country" id="calc_shipping_country" class="country_to_state country_select" rel="calc_shipping_state">
                                <option value="default"><?php esc_html_e('Select a country / region&hellip;', 'woocommerce'); ?></option>
                                <?php
                                foreach ($countries as $key => $value) {
                                    echo '<option value="' . esc_attr($key) . '"' . selected(WC()->customer->get_shipping_country(), esc_attr($key), false) . '>' . esc_html($value) . '</option>';
                                }
                                ?>
                            </select>
                        </p>
                    <?php } ?>

                <?php endif; ?>

                <?php
                $remove_state = get_option('superfrete_remove_state', 0);
                if (apply_filters('woocommerce_shipping_calculator_enable_state', true) && empty($remove_state)) :
                    ?>
                    <p class="form-row form-row-wide" id="calc_shipping_state_field">
                        <?php
                        $current_cc = "BR";
                        $current_r = WC()->customer->get_shipping_state();
                        $states = WC()->countries->get_states($current_cc);

                        if (is_array($states) && empty($states)) {
                            ?>
                            <input type="hidden" name="calc_shipping_state" id="calc_shipping_state" placeholder="<?php esc_attr_e('State / County', 'woocommerce'); ?>" />
                            <?php
                        } elseif (is_array($states)) {
                            ?>
                            <span>
                                <select name="calc_shipping_state" class="state_select" id="calc_shipping_state" data-placeholder="<?php esc_attr_e('State / County', 'woocommerce'); ?>">
                                    <option value=""><?php esc_html_e('Select an option&hellip;', 'woocommerce'); ?></option>
                                    <?php
                                    foreach ($states as $ckey => $cvalue) {
                                        echo '<option value="' . esc_attr($ckey) . '" ' . selected($current_r, $ckey, false) . '>' . esc_html($cvalue) . '</option>';
                                    }
                                    ?>
                                </select>
                            </span>
                            <?php
                        } else {
                            ?>
                            <input type="text" class="input-text" value="<?php echo esc_attr($current_r); ?>" placeholder="<?php esc_attr_e('State / County', 'woocommerce'); ?>" name="calc_shipping_state" id="calc_shipping_state" />
                            <?php
                        }
                        ?>
                    </p>
                <?php endif; ?>

                <?php
                $remove_city = get_option('superfrete_remove_city', 0);
                if (apply_filters('woocommerce_shipping_calculator_enable_city', true) && empty($remove_city)) :
                    ?>
                    <p class="form-row form-row-wide" id="calc_shipping_city_field">
                        <input type="text" class="input-text" value="<?php echo esc_attr(WC()->customer->get_shipping_city()); ?>" placeholder="<?php esc_attr_e('City', 'woocommerce'); ?>" name="calc_shipping_city" id="calc_shipping_city" />
                    </p>
                <?php endif; ?>

                <?php
                $remove_postcode = get_option('superfrete_remove_postcode', 0);
                if (apply_filters('woocommerce_shipping_calculator_enable_postcode', true) && empty($remove_postcode)) :
                    ?>
                    <p class="form-row form-row-wide" id="calc_shipping_postcode_field">
                        <input type="text" class="input-text" value="<?php echo esc_attr(WC()->customer->get_shipping_postcode()); ?>" placeholder="<?php esc_attr_e('Postcode / ZIP', 'woocommerce'); ?>" name="calc_shipping_postcode" id="calc_shipping_postcode" />
                    </p>
                <?php endif; ?>

                <p>
                    <button type="submit" name="calc_shipping" value="1" class="button superfrete-update-address-button">
                        <?php echo esc_html($update_address_btn_text); ?>
                    </button>
                </p>
                <?php wp_nonce_field('superfrete_nonce', 'superfrete_nonce'); ?>
                <?php do_action('superfrete_after_calculate_form'); ?>
            </section>
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

    <div id="superfrete-alert-container" class="superfrete-alert-container"></div>
</div>