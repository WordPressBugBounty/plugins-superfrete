(function ($) {
	'use strict';

	// Polyfill to get first array key in old browsers
	if (!Array.prototype.hasOwnProperty('flat')) {
		Object.defineProperty(Array.prototype, 'flat', {
			value: function (depth = 1) {
				return this.reduce(function (flat, toFlatten) {
					return flat.concat((Array.isArray(toFlatten) && (depth > 1)) ? toFlatten.flat(depth - 1) : toFlatten);
				}, []);
			}
		});
	}

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

			// Clear empty alert container on page load
			jQuery(".superfrete-alert-container:empty").hide();

			// Initialize CEP input masking and auto-submit
			this.initCEPMasking();

			// No need for recalculate button handler - form is always visible
			// CEP input will auto-recalculate when changed
		}

		this.cal_init = function(){
			this.submitDetect();
			this.autoSelectCountry();
			
			// Check if CEP is pre-populated and calculate automatically
			var cepInput = jQuery('#calc_shipping_postcode');
			if (cepInput.length > 0) {
				var cepValue = cepInput.val().replace(/\D/g, '');
				if (cepValue.length === 8) {
					// CEP is pre-populated with valid value, calculate freight
					var parent = this;
					// Store the pre-populated CEP to prevent duplicate calculations
					cepInput.data('last-calculated-cep', cepValue);
					setTimeout(function() {
						jQuery("#superfrete-status-message p").text('🔄 Calculando frete automaticamente...');
						parent.onloadShippingMethod();
					}, 500); // Small delay to ensure page is fully loaded
				}
			}
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
			// Calculator is always visible now, no need to hide
		}

		this.showCalculator = function () {
			// Calculator is always visible now, no need to show
		}

		this.setVariation = function (data) {
			if (data == undefined) {
				var var_id = 0;
			} else {
				var var_id = data.variation_id;
			}
			jQuery(".superfrete-woocommerce-shipping-calculator input[name='variation_id']").val(var_id);
			// REMOVED: Automatic calculation on variation change
			// Only calculate when user explicitly requests it
		}

		this.submitDetect = function () {
			var parent = this;
			jQuery(document).on("submit", "form.superfrete-woocommerce-shipping-calculator", { parent: parent }, parent.shipping_calculator_submit);
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
			
			var e = jQuery('form.superfrete-woocommerce-shipping-calculator').first();
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
				timeout: 30000, // 30 second timeout
				success: function (t) {					
					// Output performance logs to console if available
					if (t.performance_log) {
						console.group("SuperFrete Performance Logs");
						console.log("Total execution time: " + t.performance_log.total_time);
						
						// Sort steps by execution time (descending)
						var steps = [];
						for (var step in t.performance_log.steps) {
							steps.push({
								name: step,
								time: parseFloat(t.performance_log.steps[step])
							});
						}
						
						steps.sort(function(a, b) {
							return b.time - a.time;
						});
						
						console.table(steps);
						
						// Show shipping method timings if available
						if (t.performance_log.method_times) {
							console.group("Shipping Method Timings");
							
							var methodTimes = [];
							for (var method in t.performance_log.method_times) {
								methodTimes.push({
									method: method,
									time: parseFloat(t.performance_log.method_times[method])
								});
							}
							
							methodTimes.sort(function(a, b) {
								return b.time - a.time;
							});
							
							console.table(methodTimes);
							console.groupEnd();
						}
						
						// Show HTTP API stats if available
						if (t.performance_log.http_api) {
							console.group("HTTP API Requests");
							console.log("Total requests: " + t.performance_log.http_api.total_requests);
							console.log("Total time: " + t.performance_log.http_api.total_time);
							console.log("Average time: " + t.performance_log.http_api.average_time);
							
							if (t.performance_log.http_api.slow_requests) {
								console.group("Slow Requests (>500ms)");
								console.table(t.performance_log.http_api.slow_requests);
								console.groupEnd();
							}
							
							console.groupEnd();
						}
						
						// Show shipping methods info
						if (t.performance_log.shipping_methods) {
							console.group("Shipping Methods");
							console.table(t.performance_log.shipping_methods);
							console.groupEnd();
						}
						
						// Show slow methods if any
						if (t.performance_log.slow_methods) {
							console.group("⚠️ Slow Shipping Methods");
							console.table(t.performance_log.slow_methods);
							console.groupEnd();
						}
						
						console.groupEnd();
					}
					
					// Keep form visible - no need to hide/show anything

					// Update the shipping methods display in new results container
					if (t.shipping_methods && t.shipping_methods.trim() !== '') {
						jQuery("#superfrete-results-container").html(t.shipping_methods).show();
						jQuery("#superfrete-status-message").hide(); // Hide status when results are shown
					} else {
						jQuery("#superfrete-results-container").html('').hide();
						jQuery("#superfrete-status-message p").text('❌ Nenhum método de envio encontrado para este CEP');
						jQuery("#superfrete-status-message").show();
					}
					
					// Display any errors
					if (t.error && t.error.trim() !== '') {
						jQuery("#superfrete-error, .superfrete-error").html(t.error);
					} else {
						jQuery("#superfrete-error, .superfrete-error").html('');
					}
					
					if(jQuery('form.variations_form').length != 0){
						var product_id = jQuery('input[name="product_id"]', jQuery('form.variations_form')).val();
						var variation_id = jQuery('input[name="variation_id"]', jQuery('form.variations_form')).val();
						jQuery(document).trigger('pi_edd_custom_get_estimate_trigger', [product_id, variation_id]);
					}else{
						jQuery(document).trigger('superfrete_shipping_address_updated', [t]);
					}
				}
			}).fail(function(xhr, status, error) {
				// Handle AJAX errors
				console.error('SuperFrete AJAX Error:', {
					status: status,
					error: error,
					responseText: xhr.responseText,
					statusCode: xhr.status
				});
				
				// Show user-friendly error message
				var errorMessage = 'Erro ao calcular frete. ';
				if (status === 'timeout') {
					errorMessage += 'A consulta demorou mais que o esperado. Tente novamente.';
				} else if (xhr.status === 0) {
					errorMessage += 'Verifique sua conexão com a internet.';
				} else if (xhr.status === 500) {
					errorMessage += 'Erro interno do servidor. Tente novamente.';
				} else if (xhr.status === 404) {
					errorMessage += 'Serviço não encontrado.';
				} else if (xhr.status === 403) {
					errorMessage += 'Acesso negado. Recarregue a página e tente novamente.';
				} else {
					errorMessage += 'Tente novamente em alguns segundos.';
				}
				
				jQuery("#superfrete-status-message p").text('❌ ' + errorMessage);
				jQuery("#superfrete-results-container").hide();
				jQuery("#superfrete-status-message").show();
				jQuery("#superfrete-error").html('<div class="superfrete-alert superfrete-alert-error">' + errorMessage + '</div>');
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
			var auto_select_country_code = 'BR'; // Always Brazil for SuperFrete
			jQuery("#calc_shipping_country option[value='" + auto_select_country_code + "']").prop('selected', 'selected');
			jQuery("#calc_shipping_country").trigger('change');
		}

		this.initCEPMasking = function () {
			var parent = this;
			
			// CEP input masking and auto-submit functionality
			jQuery(document).on('input', '#calc_shipping_postcode', function(e) {
				var input = jQuery(this);
				var currentValue = input.val();
				var cursorPosition = input[0].selectionStart;
				var value = currentValue.replace(/\D/g, ''); // Remove non-digits
				
				// Limit to 8 digits maximum
				if (value.length > 8) {
					value = value.substring(0, 8);
				}
				
				// Apply CEP mask (00000-000)
				var formattedValue = value;
				if (value.length >= 5) {
					formattedValue = value.substring(0, 5) + '-' + value.substring(5, 8);
				}
				
				// Update input value only if it changed
				if (currentValue !== formattedValue) {
					input.val(formattedValue);
					
					// Better cursor positioning logic
					var newCursorPos = cursorPosition;
					
					// If cursor was before or at the dash position and we're removing characters
					if (currentValue.length > formattedValue.length) {
						// Deleting - adjust cursor position
						if (cursorPosition > 5 && formattedValue.includes('-')) {
							newCursorPos = Math.min(cursorPosition, formattedValue.length);
						} else if (cursorPosition === 6 && !formattedValue.includes('-')) {
							// If dash was removed, position cursor after the last digit
							newCursorPos = formattedValue.length;
						}
					} else {
						// Adding characters - normal positioning
						if (cursorPosition === 5 && formattedValue.length > 5) {
							newCursorPos = 6; // Skip the dash
						} else if (cursorPosition > 5 && formattedValue.includes('-')) {
							newCursorPos = cursorPosition;
						}
					}
					
					setTimeout(function() {
						if (input[0] && typeof input[0].setSelectionRange === 'function') {
							input[0].setSelectionRange(newCursorPos, newCursorPos);
						}
					}, 0);
				}
				
				// Update status message and auto-calculate when CEP is complete
				parent.updateCEPStatus(value);
			});
			
			// Handle autocomplete change events
			jQuery(document).on('change', '#calc_shipping_postcode', function(e) {
				var input = jQuery(this);
				var value = input.val().replace(/\D/g, '');
				
				// If autocomplete filled the field, format and calculate
				if (value.length > 0) {
					// Apply formatting
					var formattedValue = value;
					if (value.length >= 5) {
						formattedValue = value.substring(0, 5) + '-' + value.substring(5, 8);
					}
					input.val(formattedValue);
					
					// Update status and calculate if complete
					parent.updateCEPStatus(value);
				}
			});

			// Handle backspace/delete for dash position
			jQuery(document).on('keydown', '#calc_shipping_postcode', function(e) {
				var input = jQuery(this);
				var cursorPosition = input[0].selectionStart;
				var currentValue = input.val();
				
				// Handle backspace (8) and delete (46) keys
				if (e.keyCode === 8 || e.keyCode === 46) {
					// If cursor is right after the dash and user presses backspace
					if (e.keyCode === 8 && cursorPosition === 6 && currentValue.charAt(5) === '-') {
						// Remove the character before the dash instead
						var newValue = currentValue.substring(0, 4) + currentValue.substring(6);
						input.val(newValue);
						
						// Position cursor at the end of the remaining digits
						setTimeout(function() {
							var cleanValue = newValue.replace(/\D/g, '');
							var formattedValue = cleanValue;
							if (cleanValue.length >= 5) {
								formattedValue = cleanValue.substring(0, 5) + '-' + cleanValue.substring(5, 8);
							}
							input.val(formattedValue);
							
							// Position cursor appropriately
							var newPos = Math.min(4, formattedValue.length);
							if (input[0] && typeof input[0].setSelectionRange === 'function') {
								input[0].setSelectionRange(newPos, newPos);
							}
							
							// Trigger updateCEPStatus manually since we bypassed the input event
							parent.updateCEPStatus(cleanValue);
						}, 0);
						
						e.preventDefault();
						return false;
					}
					
					// If cursor is on the dash and user presses delete
					if (e.keyCode === 46 && cursorPosition === 5 && currentValue.charAt(5) === '-') {
						// Remove the character after the dash instead
						var newValue = currentValue.substring(0, 6) + currentValue.substring(7);
						input.val(newValue);
						
						setTimeout(function() {
							var cleanValue = newValue.replace(/\D/g, '');
							var formattedValue = cleanValue;
							if (cleanValue.length >= 5) {
								formattedValue = cleanValue.substring(0, 5) + '-' + cleanValue.substring(5, 8);
							}
							input.val(formattedValue);
							
							// Keep cursor at dash position
							if (input[0] && typeof input[0].setSelectionRange === 'function') {
								input[0].setSelectionRange(5, 5);
							}
							
							// Trigger updateCEPStatus manually since we bypassed the input event
							parent.updateCEPStatus(cleanValue);
						}, 0);
						
						e.preventDefault();
						return false;
					}
				}
			});

			// Prevent non-numeric input except dash
			jQuery(document).on('keypress', '#calc_shipping_postcode', function(e) {
				var char = String.fromCharCode(e.which);
				if (!/[0-9-]/.test(char)) {
					e.preventDefault();
				}
			});

			// Handle paste events
			jQuery(document).on('paste', '#calc_shipping_postcode', function(e) {
				var parent_calc = parent;
				setTimeout(function() {
					var input = jQuery('#calc_shipping_postcode');
					var value = input.val().replace(/\D/g, '');
					
					// Limit to 8 digits
					if (value.length > 8) {
						value = value.substring(0, 8);
					}
					
					// Apply CEP mask
					var formattedValue = value;
					if (value.length >= 5) {
						formattedValue = value.substring(0, 5) + '-' + value.substring(5, 8);
					}
					
					input.val(formattedValue);
					
					// Update status and calculate if complete
					parent_calc.updateCEPStatus(value);
				}, 0);
			});
		}
		
		this.updateCEPStatus = function(cleanValue) {
			var parent = this;
			
			if (cleanValue.length === 0) {
				jQuery("#superfrete-status-message p").text('💡 Digite seu CEP para calcular automaticamente o frete e prazo de entrega');
				jQuery("#superfrete-results-container").hide();
				jQuery("#superfrete-status-message").show();
			} else if (cleanValue.length < 8) {
				jQuery("#superfrete-status-message p").text('⌨️ Continue digitando seu CEP...');
				jQuery("#superfrete-results-container").hide();
				jQuery("#superfrete-status-message").show();
			} else if (cleanValue.length === 8) {
				jQuery("#superfrete-status-message p").text('🔄 Calculando frete automaticamente...');
				// Check if this is a new CEP (different from last calculated)
				var lastCalculatedCEP = jQuery("#calc_shipping_postcode").data('last-calculated-cep');
				if (lastCalculatedCEP !== cleanValue) {
					// Store the CEP being calculated
					jQuery("#calc_shipping_postcode").data('last-calculated-cep', cleanValue);
					// Small delay to ensure user sees the formatted CEP
					setTimeout(function() {
						if (parent.form_present()) {
							parent.onloadShippingMethod();
						}
					}, 300);
				}
			}
		}
	}

	jQuery(function ($) {
		var managingCalculatorObj = new managingCalculator();
		managingCalculatorObj.init();
	});

})(jQuery);
