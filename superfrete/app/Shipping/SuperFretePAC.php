<?php

namespace SuperFrete_API\Shipping;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH'))
    exit; // Segurança

class SuperFretePAC extends \WC_Shipping_Method {

    public $free_shipping;
    public $extra_days;
    public $extra_cost;
    public $extra_cost_type;
 public function __construct( $instance_id = 'superfrete_pac') {
        $this->id                    = 'superfrete_pac';
        $this->instance_id           = absint( $instance_id );
        $this->method_title          = __( 'PAC SuperFrete' );
        $this->method_description    = __( 'Envia utilizando PAC' );
        $this->supports              = array(
            'shipping-zones',
            'instance-settings',
        );
        $this->instance_form_fields = array(
            'enabled' => array(
                'title'         => __( 'Ativar/Desativar' ),
                'type'             => 'checkbox',
                'label'         => __('Ativar SuperFrete nas áreas de entrega', 'superfrete'),
                'default'         => 'yes',
            ),
            'title' => array(
                'title'         => __( 'Method Title' ),
                'type'             => 'text',
                  'description' => __('Este título aparecerá no checkout', 'superfrete'),
                'default' => __('Entrega via PAC SuperFrete', 'superfrete'),
                'desc_tip'        => true
            ),
              'free_shipping' => array(
                'title' => __('Frete Grátis', 'superfrete'),
                'type' => 'checkbox',
                'label' => __('Ativar frete grátis para este método', 'superfrete'),
         
            ),
            'extra_days' => array(
                'title' => __('Dias extras no prazo', 'superfrete'),
                'type' => 'number',
                'description' => __('Dias adicionais ao prazo estimado pela SuperFrete', 'superfrete'),
                'default' => 0,
                'desc_tip' => true
            ),
            'extra_cost' => array(
                'title' => __('Valor adicional no frete', 'superfrete'),
                'type' => 'price',
                'description' => __('Valor extra a ser somado ao custo do frete', 'superfrete'),
                'default' => '0',
                'desc_tip' => true
            ),
            'extra_cost_type' => array(
                'title' => __('Tipo de valor adicional', 'superfrete'),
                'type' => 'select',
                'description' => __('Escolha se o valor adicional será fixo (R$) ou percentual (%) sobre o frete original.', 'superfrete'),
                'default' => 'fixed',
                'options' => array(
                    'fixed' => __('Valor fixo (R$)', 'superfrete'),
                    'percent' => __('Porcentagem (%)', 'superfrete'),
                ),
            ),
        );
        $this->enabled = $this->get_option('enabled');
        $this->free_shipping = $this->get_option('free_shipping');
        $this->title = $this->get_option('title');
        $this->extra_days = $this->get_option('extra_days', 0);
        $this->extra_cost = $this->get_option('extra_cost', 0);
        $this->extra_cost_type = $this->get_option('extra_cost_type', 'fixed');

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }
    protected function get_service_id() {
        return 1; // ID do PAC na API
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
$produtos = [];
     $insurance_value = 0;
foreach ($package['contents'] as $item) {
    $product = $item['data'];
    
    
    
    
   $insurance_value += $product->get_price() * $item['quantity'];
$weight_unit = get_option('woocommerce_weight_unit');
$dimension_unit = get_option('woocommerce_dimension_unit');

    $produtos[] = [
        'quantity' => $item['quantity'],
      'weight'   => ($weight_unit === 'g') ? floatval($product->get_weight()) / 1000 : floatval($product->get_weight()),
    'height'   => ($dimension_unit === 'm') ? floatval($product->get_height()) * 100 : floatval($product->get_height()),
    'width'    => ($dimension_unit === 'm') ? floatval($product->get_width()) * 100 : floatval($product->get_width()),
    'length'   => ($dimension_unit === 'm') ? floatval($product->get_length()) * 100 : floatval($product->get_length()),

    ];
}

$cep_origem = get_option('woocommerce_store_postcode');

if (!$cep_origem) {
    return;
}

$payload = [
    'from' => ['postal_code' => $cep_origem],
    'to' => ['postal_code' => $cep_destino],
    'services' => "1",
    'options'=> [
      "own_hand"=>false,
      "receipt"=>false,
      "insurance_value"=> $insurance_value,
      "use_insurance_value"=>false
          ],

    'products' => $produtos,
];

$request = new Request();


$response = $request->call_superfrete_api('/api/v0/calculator', 'POST', $payload);

        if (!empty($response)) {
            foreach ($response as $frete) {
                $prazo_total = $frete['delivery_time'] + $this->extra_days;
                $text_dias = ($prazo_total <= 1) ? "dia útil" : "dias úteis";

                if (!$frete['has_error'] && !isset($frete["error"])) {
                   $frete_custo = 0;

if ($this->free_shipping !== 'yes') {
    $frete_base = floatval($frete['price']);
    if ($this->extra_cost_type === 'percent') {
        $frete_custo = $frete_base + ($frete_base * ($this->extra_cost / 100));
    } else {
        $frete_custo = $frete_base + $this->extra_cost;
    }
}

                    $frete_desc = ($this->free_shipping === 'yes') ? "- Frete Grátis" : "";

                    $rate = [
                        'id' => $this->id . '_' . $frete['id'],
                        'label' => $frete['name'] . ' - Promocional - (' . $prazo_total . ' ' . $text_dias . ') ' . $frete_desc,
                        'cost' => $frete_custo
                    ];
                    $this->add_rate($rate);
                }
            }
        }
    }

    public function process_admin_options() {
        parent::process_admin_options(); // Chama a função do WooCommerce para salvar
    }
}
