<?php

namespace SuperFrete_API\Shipping;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH'))
    exit; // Segurança

class SuperFreteShipping extends \WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('SuperFrete', 'superfrete');
        $this->method_description = __('Método de envio via SuperFrete API.', 'superfrete');
        $this->supports = ['shipping-zones', 'instance-settings'];

        $this->init();
    }

    /**
     * Inicializa as configurações do método de envio.
     */
    public function init() {
        // Carrega as configurações do método de envio
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
    }

    /**
     * Define os campos do método de envio no painel de administração.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Ativar/Desativar', 'superfrete'),
                'type' => 'checkbox',
                'label' => __('Ativar SuperFrete nas áreas de entrega', 'superfrete'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Título', 'superfrete'),
                'type' => 'text',
                'description' => __('Este título aparecerá no checkout', 'superfrete'),
                'default' => __('Entrega via SuperFrete', 'superfrete'),
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Calcula o frete e retorna as opções disponíveis.
     */
    public function calculate_shipping($package = []) {
        if (!$this->enabled || empty($package['destination']['postcode'])) {
            return;
        }

        $cep_destino = $package['destination']['postcode'];
        $peso_total = 0;
        $dimensoes = ['height' => 2, 'width' => 11, 'length' => 16, 'weight' => 0.3];

        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $peso_total += $product->get_weight() * $item['quantity'];
            $dimensoes['height'] = max($dimensoes['height'], $product->get_height());
            $dimensoes['width'] = max($dimensoes['width'], $product->get_width());
            $dimensoes['length'] += $product->get_length();
        }

        $cep_origem = get_option('woocommerce_store_postcode');

        if (!$cep_origem) {
            return;
        }

        $payload = [
            'from' => ['postal_code' => $cep_origem],
            'to' => ['postal_code' => $cep_destino],
            'services' => "1,2,17",
            'package' => $dimensoes,
        ];
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/calculator', 'POST', $payload);

        if (!empty($response)) {
            foreach ($response as $frete) {
                if (!$frete['has_error']) {
                    $rate = [
                        'id' => $this->id . '_' . $frete['id'],
                        'label' => $frete['name'] . ' - (' . $frete['delivery_time'] . ' dias úteis)',
                        'cost' => floatval($frete['price']),
                    ];
                    $this->add_rate($rate);
                }
            }
        }
    }
}