<?php

namespace SuperFrete_API\Helpers;

if (!defined('ABSPATH')) exit; // Segurança

class SuperFrete_Notice {

    /**
     * Adiciona uma mensagem de erro para exibição no popup
     * @param string $message Mensagem de erro a ser exibida.
     * @param array $missing_fields Campos que precisam ser preenchidos.
     */
    public static function add_error($order_id, $message, $missing_fields = []) {
        if (!session_id()) {
            session_start();
        }

        $_SESSION['superfrete_errors'][] = [
            'message' => $message,
            'missing_fields' => $missing_fields,
            'order_id' => $order_id
        ];
    }

    /**
     * Exibe os erros armazenados em um popup no frontend
     */
public static function display_errors() {
    if (!session_id()) {
        session_start();
    }

    if (!empty($_SESSION['superfrete_errors'])) {
        $error_data = array_pop($_SESSION['superfrete_errors']); // Pega o erro mais recente
        $message = $error_data['message'];
        $missing_fields = $error_data['missing_fields'];
        $order_id = $error_data['order_id'];
        
        echo '<div id="superfrete-popup" class="superfrete-popup">';
        echo '<div class="superfrete-popup-content">';
      
        echo '<h3>Erro no Cálculo do Frete</h3>';
        echo '<p>' . esc_html($message) . '</p>';
        
        // Se há campos ausentes, exibe o formulário para preenchimento
        if (!empty($missing_fields)) {
            echo '<form id="superfrete-form">';
            echo '<input type="hidden" id="order_id" name="order_id" value="' . esc_attr($order_id) . '" required>';
            
            // Adiciona o campo oculto para o nonce
  echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr(wp_create_nonce('superfrete_update_address_nonce')) . '">';          
  $estados = [
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
    'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
    'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
    'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
    'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima',
    'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
];

foreach ($missing_fields as $field => $label) {
    echo '<label>' . esc_html($label) . '</label>';
    
    if ($field === 'state_abbr') {
        echo '<select name="' . esc_attr($field) . '" required>';
        echo '<option value="">Selecione</option>';
        foreach ($estados as $sigla => $nome) {
            echo '<option value="' . esc_attr($sigla) . '">' . esc_html($sigla . ' - ' . $nome) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" name="' . esc_attr($field) . '" required>';
    }
}
            echo '<button type="submit">Corrigir Dados</button>';
            echo '</form>';
        }

        echo '</div></div>';
    }
}
}

// Garante que o popup seja carregado no frontend
add_action('wp_footer', ['SuperFrete_API\Helpers\SuperFrete_Notice', 'display_errors']);