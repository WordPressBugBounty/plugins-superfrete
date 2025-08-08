<?php

namespace SuperFrete_API\Controllers;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;
use SuperFrete_API\Helpers\SuperFrete_Notice;
use SuperFrete_API\Helpers\AddressHelper;
use WC_Order;

if (!defined('ABSPATH'))
    exit; // Segurança

class SuperFrete_Order
{

    public function __construct()
    {
        add_action('woocommerce_thankyou', [$this, 'send_order_to_superfrete'], 100, 1);
    }

    /**
     * Envia os dados do pedido para a API SuperFrete.
     */
    public function send_order_to_superfrete($order_id)
    {

        if (!$order_id)
            return;


        $order = wc_get_order($order_id);
        if (!$order)
            return;

        $order = wc_get_order($order_id);
        $superfrete_status = $order ? $order->get_meta('_superfrete_status') : '';
        if ($superfrete_status == 'enviado')
            return;



        Logger::log('SuperFrete', 'Pedido #' . $order_id . ' capturado para envio à API.');

        // Obtém os dados do remetente (endereço da loja)
        $cep_origem = get_option('woocommerce_store_postcode');
        $store_raw_country = get_option('woocommerce_default_country');

        // Split the country/state
        $split_country = explode(":", $store_raw_country);
        $store_country = $split_country[0];
        $store_state = $split_country[1];
        $cep_limpo = preg_replace('/[^0-9]/', '', $cep_origem);
        $remetente = [
            'name' => get_option('woocommerce_store_name', 'Minha Loja'),
            'address' => get_option('woocommerce_store_address'),
            'complement' => get_option('woocommerce_store_address_2', ''),
            'number' => get_option('woocommerce_store_number', ''),
            'district' => get_option('woocommerce_store_neighborhood'),
            'city' => get_option('woocommerce_store_city'),
            'state_abbr' => $store_state,
            'postal_code' => $cep_limpo
        ];

        // Obtém os dados do destinatário (cliente)
        $shipping = $order->get_address('shipping');

        // Se estiver vazio, usa o billing como fallback
if (empty($shipping) || empty($shipping['first_name'])) {
    $shipping = $order->get_address('billing');
}

        // Verifica e adiciona os campos personalizados
        if (empty($shipping['number'])) {
            // Try multiple possible field names for number
            $possible_number_fields = [
                '_shipping_number',
                '_billing_number',
                '_WC_OTHER/SHIPPING/NUMBER',
                'shipping/number',
                'billing_number'
            ];
            
            foreach ($possible_number_fields as $field) {
                $value = $order->get_meta($field);
                Logger::log('SuperFrete', 'Checking number field ' . $field . ' for order #' . $order_id . ': "' . $value . '"');
                if (!empty($value)) {
                    $shipping['number'] = $value;
                    Logger::log('SuperFrete', 'Using number from field ' . $field . ' for order #' . $order_id . ': ' . $value);
                    break;
                }
            }
            
            // If still empty, search directly in meta data
            if (empty($shipping['number'])) {
                $all_meta = $order->get_meta_data();
                foreach ($all_meta as $meta) {
                    if (strpos(strtolower($meta->key), 'number') !== false && !empty($meta->value)) {
                        $shipping['number'] = $meta->value;
                        Logger::log('SuperFrete', 'Found number via meta search for order #' . $order_id . ' (key: ' . $meta->key . '): ' . $meta->value);
                        break;
                    }
                }
            }
        }
        
        if (empty($shipping['neighborhood'])) {
            // Try multiple possible field names for neighborhood
            $possible_neighborhood_fields = [
                '_shipping_neighborhood',
                '_billing_neighborhood',
                '_WC_OTHER/SHIPPING/NEIGHBORHOOD',
                'shipping/neighborhood',
                'billing_neighborhood'
            ];
            
            foreach ($possible_neighborhood_fields as $field) {
                $value = $order->get_meta($field);
                Logger::log('SuperFrete', 'Checking neighborhood field ' . $field . ' for order #' . $order_id . ': "' . $value . '"');
                if (!empty($value)) {
                    $shipping['neighborhood'] = $value;
                    Logger::log('SuperFrete', 'Using neighborhood from field ' . $field . ' for order #' . $order_id . ': ' . $value);
                    break;
                }
            }
            
            // If still empty, search directly in meta data
            if (empty($shipping['neighborhood'])) {
                $all_meta = $order->get_meta_data();
                foreach ($all_meta as $meta) {
                    if (strpos(strtolower($meta->key), 'neighborhood') !== false && !empty($meta->value)) {
                        $shipping['neighborhood'] = $meta->value;
                        Logger::log('SuperFrete', 'Found neighborhood via meta search for order #' . $order_id . ' (key: ' . $meta->key . '): ' . $meta->value);
                        break;
                    }
                }
            }
        }
        
        // If district is still missing, try to get it from ViaCEP
        if (empty($shipping['neighborhood']) && !empty($shipping['postcode'])) {
            Logger::log('SuperFrete', 'District missing for order #' . $order_id . ', trying ViaCEP for CEP: ' . $shipping['postcode']);
            $district_from_viacep = AddressHelper::get_district_from_postal_code($shipping['postcode']);
            if ($district_from_viacep) {
                $shipping['neighborhood'] = $district_from_viacep;
                Logger::log('SuperFrete', 'Got district from ViaCEP for order #' . $order_id . ': ' . $district_from_viacep);
            } else {
                Logger::log('SuperFrete', 'Could not get district from ViaCEP for order #' . $order_id . ' with CEP: ' . $shipping['postcode']);
            }
        }
        
        // Get customer document (CPF/CNPJ)
        $document = '';
        
        // Search through all meta data for document field
        $all_meta = $order->get_meta_data();
        foreach ($all_meta as $meta) {
            // Check if this could be our document field
            if (strpos($meta->key, 'DOCUMENT') !== false || strpos($meta->key, 'document') !== false) {
                $clean_value = preg_replace('/[^0-9]/', '', $meta->value);
                
                if (strlen($clean_value) == 11 || strlen($clean_value) == 14) {
                    $document = $clean_value;
                    Logger::log('SuperFrete', 'Document found for order #' . $order_id . ': ' . substr($document, 0, 3) . '***');
                    break;
                }
            }
        }
        
        // If no document found, log warning
        if (empty($document)) {
            Logger::log('SuperFrete', 'Warning: No CPF/CNPJ found for order #' . $order_id);
        }
        
        $destinatario = [
            'name' => $shipping['first_name'] . ' ' . $shipping['last_name'],
            'address' => $shipping['address_1'],
            'complement' => $shipping['address_2'],
            'number' => $shipping['number'],
            'district' => $shipping['neighborhood'],
            'city' => $shipping['city'],
            'state_abbr' => $shipping['state'],
            'postal_code' => preg_replace('/[^\p{L}\p{N}\s]/', '', $shipping['postcode'])
        ];
        
        // Add document if available
        if (!empty($document)) {
            $destinatario['document'] = $document;
            Logger::log('SuperFrete', 'Document added to destinatario for order #' . $order_id . ': ' . substr($document, 0, 3) . '***');
        } else {
            Logger::log('SuperFrete', 'WARNING: No document found to add to destinatario for order #' . $order_id);
        }

        // Obtém o método de envio escolhido
        $chosen_methods = $order->get_shipping_methods();
        $service = "";

        foreach ($chosen_methods as $method) {
            // First try to get service ID from method metadata
            $method_data = $method->get_data();
            if (isset($method_data['meta_data']) && is_array($method_data['meta_data'])) {
                foreach ($method_data['meta_data'] as $meta) {
                    if ($meta->key === 'service_id') {
                        $service = strval($meta->value);
                        Logger::log('SuperFrete', 'Got service ID from metadata for order #' . $order_id . ': ' . $service);
                        break 2; // Exit both loops
                    }
                }
            }
            
            // Fallback to name-based detection if no metadata
            if (empty($service)) {
                $method_id = $method->get_method_id();
                $method_name = strtolower($method->get_name());
                
                // Check method ID first (more reliable)
                if (strpos($method_id, 'superfrete_pac') !== false) {
                    $service = "1";
                } elseif (strpos($method_id, 'superfrete_sedex') !== false) {
                    $service = "2";
                } elseif (strpos($method_id, 'superfrete_jadlog') !== false) {
                    $service = "3";
                } elseif (strpos($method_id, 'superfrete_mini_envio') !== false) {
                    $service = "17";
                } elseif (strpos($method_id, 'superfrete_loggi') !== false) {
                    $service = "31";
                }
                // Fallback to name checking
                elseif (strpos($method_name, 'pac') !== false) {
                    $service = "1";
                } elseif (strpos($method_name, 'sedex') !== false) {
                    $service = "2";
                } elseif (strpos($method_name, 'jadlog') !== false) {
                    $service = "3";
                } elseif (strpos($method_name, 'mini envio') !== false) {
                    $service = "17";
                } elseif (strpos($method_name, 'loggi') !== false) {
                    $service = "31";
                }
                
                Logger::log('SuperFrete', 'Service ID for order #' . $order_id . ' determined from method ID/name: ' . $service . ' (method_id: ' . $method_id . ', name: ' . $method_name . ')');
            }
        }
        $request = new Request();

        $produtos = [];

        $insurance_value = 0;

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            if ($product && !$product->is_virtual()) {
                $qty = $item->get_quantity();
                $total = $order->get_item_total($item, false); // valor unitário sem frete
                $insurance_value += $total * $qty;

                $weight_unit = get_option('woocommerce_weight_unit');
                $dimension_unit = get_option('woocommerce_dimension_unit');

                $produtos[] = [
                    'quantity' => $item['quantity'],
                    'weight' => ($weight_unit === 'g') ? floatval($product->get_weight()) / 1000 : floatval($product->get_weight()),
                    'height' => ($dimension_unit === 'm') ? floatval($product->get_height()) * 100 : floatval($product->get_height()),
                    'width' => ($dimension_unit === 'm') ? floatval($product->get_width()) * 100 : floatval($product->get_width()),
                    'length' => ($dimension_unit === 'm') ? floatval($product->get_length()) * 100 : floatval($product->get_length()),

                ];
            }

        }
        if (empty($produtos)) {
            Logger::log('SuperFrete', 'Pedido #' . $order_id . ' não enviado para a SuperFrete, pois contém apenas produtos virtuais.');
            return;
        }

        $payload_products = [
            'from' => $remetente,
            'to' => $destinatario,
            'services' => $service, // deve ser string, ex: "1,2,17"
            'options' => [
                'insurance_value' => round($insurance_value, 2),
                'receipt' => false,
                'own_hand' => false,
            ],
            'products' => $produtos
        ];

        $response_package = $request->call_superfrete_api('/api/v0/calculator', 'POST', $payload_products, false);

        $volume_data = [
            'height' => 0.3,
            'width' => 0.3,
            'length' => 0.3,
            'weight' => 0.2
        ];

        if (!empty($response_package) && isset($response_package[0]['packages'][0])) {
            $package = $response_package[0]['packages'][0];

            $volume_data = [
                'height' => (float) $package['dimensions']['height'],
                'width' => (float) $package['dimensions']['width'],
                'length' => (float) $package['dimensions']['length'],
                'weight' => (float) $package['weight']
            ];
        }
        // Obtém os produtos do pedido
        $produtos = [];

        foreach ($order->get_items() as $item_id => $item) {

            $product = $item->get_product();

            if ($product && !$product->is_virtual()) {
                $produtos[] = [
                    'name' => $product->get_name(),
                    'quantity' => strval($item->get_quantity()),
                    'unitary_value' => strval($order->get_item_total($item, false))
                ];
            }
        }
        // Monta o payload final
        $payload = [
            'from' => $remetente,
            'to' => $destinatario,
            'email' => $order->get_billing_email(),
            'service' => intval($service),
            'products' => $produtos,
            'volumes' => $volume_data,
            'options' => [
                'insurance_value' => round($insurance_value, 2),
                'receipt' => false,
                'own_hand' => false,
                'non_commercial' => false,
                'tags' => [
                    [
                        'tag' => strval($order->get_id()),
                        'url' => get_admin_url(null, 'post.php?post=' . $order_id . '&action=edit')
                    ]
                ],
            ],
            'platform' => 'WooCommerce'
        ];

        Logger::log('SuperFrete', 'Enviando pedido #' . $order_id . ' para API: ' . wp_json_encode($payload));

        // Faz a requisição à API SuperFrete

        $response = $request->call_superfrete_api('/api/v0/cart', 'POST', $payload, true);

        if (!$response) {

            $missing_fields = [];

            if (empty($destinatario['name'])) {
                $missing_fields['name'] = 'Nome do destinatário';
            }
            if (empty($destinatario['address'])) {
                $missing_fields['address'] = 'Endereço';
            }
            if (empty($destinatario['number'])) {
                $missing_fields['number'] = 'Número';
            }
            if (empty($destinatario['district'])) {
                $missing_fields['district'] = 'Bairro';
            }
            if (empty($destinatario['city'])) {
                $missing_fields['city'] = 'Cidade';
            }
            if (empty($destinatario['state_abbr'])) {
                $missing_fields['state_abbr'] = 'Estado';
            }
            if (empty($destinatario['postal_code'])) {
                $missing_fields['postal_code'] = 'CEP';
            }

            if (!empty($missing_fields)) {
                SuperFrete_Notice::add_error($order_id, 'Alguns dados estão ausentes para o cálculo do frete.', $missing_fields);
                return;
            }
            if (session_id() && isset($_SESSION['superfrete_correction'][$order_id])) {
                foreach ($_SESSION['superfrete_correction'][$order_id] as $key => $value) {
                    if (isset($destinatario[$key]) && empty($destinatario[$key])) {
                        $destinatario[$key] = $value;
                    }
                }
                unset($_SESSION['superfrete_correction'][$order_id]); // Remove após uso
            }
        }

        Logger::log('SuperFrete', 'Resposta da API para o pedido #' . $order_id . ': ' . wp_json_encode($response));

        if ($response) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_superfrete_id', $response['id']);
                $order->update_meta_data('_superfrete_protocol', $response['protocol']);
                $order->update_meta_data('_superfrete_price', $response['price']);
                $order->update_meta_data('_superfrete_status', 'enviado');
                $order->save();
            }
        }

        return $response;
    }
}
