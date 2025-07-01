(function ($) {
	'use strict';

	function managingCalculator() {
		this.init = function () {
			var parent = this;
			
			if(superfrete_setting?.load_location_by_ajax == 1 && this.form_present()){
				var request = this.loadCountry();
				request.done(function(res){
					parent.setLocation(res);
					parent.removeLoading();
					parent.cal_init();
					superfrete_setting.load_location_by_ajax = 0;
				}).fail(function(){
					parent.removeLoading();
					parent.cal_init();
				});
			}else{
				this.cal_init()
			}

			/**
			 * variation change need to be called saperatelly
			 */
			this.variationChange();

			
		}

		this.cal_init = function(){
			this.calculatorOpen();
			this.submitDetect();
			if(!window?.superfrete_autoloading_done){
				this.onloadShippingMethod(true);
			}
			this.autoSelectCountry();
		}

		this.loadCountry = function(){
			var action = 'pi_load_location_by_ajax';
			this.loading();
			return jQuery.ajax({
				type: 'POST',
				url: superfrete_setting.wc_ajax_url.toString().replace('%%endpoint%%', action),
				data: {
					action: action
				},
				dataType: "json",
			});
		}

		this.setLocation = function(res){
			jQuery("#calc_shipping_country").val(res.calc_shipping_country);
			jQuery("#calc_shipping_country").trigger('change');
			jQuery("#calc_shipping_state").val(res.calc_shipping_state);
			jQuery("#calc_shipping_city").val(res.calc_shipping_city);
			jQuery("#calc_shipping_postcode").val(res.calc_shipping_postcode);
		}

		this.form_present = function(){
			return jQuery(".superfrete-woocommerce-shipping-calculator").length > 0 ? true : false;
		}

		this.variationChange = function () {
			var parent = this;
			$(document).on('show_variation reset_data', "form.variations_form", function (event, data) {

				if (data != undefined) {

					if (data.is_in_stock && !data.is_virtual) {

						if(superfrete_setting?.load_location_by_ajax == 1 &&  parent.form_present()){
							var request = parent.loadCountry();
							request.done(function(res){
								parent.setLocation(res);
								parent.showCalculator();
								parent.setVariation(data);
								parent.noVariationSelectedMessage(false);
								superfrete_setting.load_location_by_ajax = 0;
							}).fail(function(){
								parent.showCalculator();
								parent.setVariation(data);
								parent.noVariationSelectedMessage(false);
							});
						}else{
							parent.showCalculator();
							window.superfrete_autoloading_done = 1;
							parent.setVariation(data);
							parent.noVariationSelectedMessage(false);
						}

						
					} else {
						parent.hideCalculator();
						parent.noVariationSelectedMessage(false);
					}

				} else {
					parent.hideCalculator();
					parent.noVariationSelectedMessage(true);
				}

			});
		}

		this.noVariationSelectedMessage = function (show) {
			if (show) {
				jQuery("#superfrete-other-messages").html("Selecione uma Variação")
			} else {
				jQuery("#superfrete-other-messages").html('');
			}
		}

		this.hideCalculator = function () {
			jQuery(".superfrete-container").fadeOut();
		}

		this.showCalculator = function () {
			jQuery(".superfrete-container").fadeIn();
		}

		this.setVariation = function (data) {
			if (data == undefined) {
				var var_id = 0;
			} else {
				var var_id = data.variation_id;
			}
			jQuery(".superfrete-woocommerce-shipping-calculator input[name='variation_id']").val(var_id);
			this.onloadShippingMethod(true);
		}

		this.submitDetect = function () {
			var parent = this;
			jQuery(document).on("submit", "form.superfrete-woocommerce-shipping-calculator", { parent: parent }, parent.shipping_calculator_submit);
		}

		this.calculatorOpen = function () {
			jQuery(document).on('click', '.superfrete-shipping-calculator-button', function (e) {
				e.preventDefault();
				jQuery('.superfrete-shipping-calculator-form').toggle();
				jQuery(document).trigger('superfrete_calculator_button_clicker');
			});
		}

		this.shipping_calculator_submit = function (t) {
			t.preventDefault();
			var n = jQuery;
			var e = jQuery(t.currentTarget);
			var data = t.data;
			data.parent.onloadShippingMethod();
		}

		this.loading = function () {
			jQuery('body').addClass('superfrete-processing');
		}

		this.removeLoading = function () {
			jQuery('body').removeClass('superfrete-processing');
		}

		this.onloadShippingMethod = function (auto_load) {

			if(this.form_present() == false) return;
			
			var e = jQuery('form.superfrete-woocommerce-shipping-calculator:visible').first();
			var parent = this;
			if (jQuery("#superfrete-variation-id").length && jQuery("#superfrete-variation-id").val() == 0) {

			} else {
				this.getMethods(e, auto_load);
			}
		}

		this.getMethods = function (e, auto_load) {
			var parent = this;
			this.loading();
			var auto_load_variable = '';
			if (auto_load) {
				auto_load_variable = '&action_auto_load=true';
			}

			this.updateQuantity(e);

			var action = jQuery('input[type="hidden"][name="action"]', e).val();

			/**
			 * with this one ajax request is reduced when auto loading is set to off
			 */
			
			

			jQuery.ajax({
				type: e.attr("method"),
				url: superfrete_setting.wc_ajax_url.toString().replace('%%endpoint%%', action),
				data: e.serialize() + auto_load_variable,
				dataType: "json",
				success: function (t) {					
						
					

					jQuery("#superfrete-alert-container, .superfrete-alert-container").html(t.shipping_methods);
					jQuery("#superfrete-error, .superfrete-error").html(t.error);
					if(jQuery('form.variations_form').length != 0){
						var product_id = jQuery('input[name="product_id"]', jQuery('form.variations_form')).val();
						var variation_id = jQuery('input[name="variation_id"]', jQuery('form.variations_form')).val();
						jQuery(document).trigger('pi_edd_custom_get_estimate_trigger', [product_id, variation_id]);
					}else{
						jQuery(document).trigger('superfrete_shipping_address_updated', [t]);
					}

				}
			}).always(function () {
				parent.removeLoading();
			})
		}

		this.updateQuantity = function (e) {
			var product_id = jQuery('input[name="product_id"]', e).val();
			var selected_qty = jQuery('#quantity_' + product_id).val();
			jQuery('input[name="quantity"]', e).val(selected_qty);
		}

		this.autoSelectCountry = function () {
			var auto_select_country_code = true;
			if (auto_select_country_code == false) return;

			jQuery("#calc_shipping_country option[value='" + auto_select_country_code + "']").prop('selected', 'selected');
			jQuery("#calc_shipping_country").trigger('change');

		}

	}

	jQuery(function ($) {
		var managingCalculatorObj = new managingCalculator();
		managingCalculatorObj.init();
	});

})(jQuery);
