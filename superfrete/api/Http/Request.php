<?php

namespace SuperFrete_API\Http;

use SuperFrete_API\Helpers\Logger;

class Request {

    private $api_url;
    private $api_token;

    /**
     * Construtor para inicializar as configurações da API.
     */
    public function __construct() {
        $this->api_url = 'https://api.superfrete.com'; // URL padrão da API
        // Verifica se o sandbox está ativado e troca o token
        $sandbox_enabled = get_option('superfrete_sandbox_mode') === 'yes';
        $this->api_token = $sandbox_enabled ? get_option('superfrete_api_token_sandbox') : get_option('superfrete_api_token');

        if ($sandbox_enabled) {
            $this->api_url = 'https://sandbox.superfrete.com'; // URL do ambiente de teste
        }
    }

    /**
     * Método genérico para chamadas à API do SuperFrete.
     */
    public function call_superfrete_api($endpoint, $method = 'GET', $payload = [], $retorno = false) {
        
        try {
             $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_token ,
            'Platform' => 'Woocommerce SuperFrete',
        ];
     
 
        $params = [
            'headers' => $headers,
            'method' => $method,
            'timeout' => 10,
        ];

        if ($method === 'POST' && !empty($payload)) {
            $params['body'] = wp_json_encode($payload);
        }
 
  
        $response = ($method === 'POST') ? wp_remote_post($this->api_url . $endpoint, $params) : wp_remote_get($this->api_url . $endpoint, $params);
        if(!$retorno){
                
              if (is_wp_error($response)) {
            
            Logger::log("Erro na API ({$endpoint}): " . $response->get_error_message(), 'ERROR');
            return false;
        }
      
}


     

        $status_code = wp_remote_retrieve_response_code($response);

        $body = json_decode(wp_remote_retrieve_body($response), true);
    
        
        if ($status_code !== 200 || (isset($body['success']) && $body['success'] === false )) {
            $error_message = isset($body['message']) ? $body['message'] : 'Erro desconhecido';
            $nova_linha = (php_sapi_name() === 'cli') ? "\n" : "<br>";
            
            if (!isset($body['errors']) && isset($body['error'])) {
                foreach ($body['error'] as $error) {

                    if (isset($erros)) {

                        $erros = $erros . "\n" . $error[0] . "\n";
                    } else {
                        $erros = "\n" . $error[0];
                    }
                }
            } else if(isset($body['errors'])){
                foreach ($body['errors'] as $error) {

                    if (isset($erros)) {

                        $erros = $erros . "\n" . $error[0] . "\n";
                    } else {
                        $erros = "\n" . $error[0];
                    }
                }
            }

            $errors = isset($erros) ? $erros : 'Sem detalhes';

            Logger::log('SuperFrete', "Erro na API ({$endpoint}): Código {$status_code} - {$error_message}\nDetalhes: {$errors}");
            return false;
        }

        if (is_wp_error($response)) {

            Logger::log("Erro na API ({$endpoint}): " . $response->get_error_message(), 'ERROR');
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            Logger::log("Resposta inesperada ({$endpoint}): HTTP {$status_code}", 'WARNING');
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
        } catch (Exception $exc) {
           Logger::log("Erro {$exc}", 'WARNING');
        }

       
    }
}
