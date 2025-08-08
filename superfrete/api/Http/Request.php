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
        // Set API URL based on environment
        $use_dev_env = get_option('superfrete_sandbox_mode') === 'yes';
        
        if ($use_dev_env) {
            $this->api_url = 'https://sandbox.superfrete.com/';
            $this->api_token = get_option('superfrete_api_token_sandbox');
        } else {
            $this->api_url = 'https://api.superfrete.com/';
            $this->api_token = get_option('superfrete_api_token');
        }
        
        // Debug logging
        error_log('SuperFrete Request: API URL = ' . $this->api_url);
        error_log('SuperFrete Request: Token present = ' . (!empty($this->api_token) ? 'yes' : 'no'));
        error_log('SuperFrete Request: Use dev env = ' . ($use_dev_env ? 'yes' : 'no'));
    }

    /**
     * Método genérico para chamadas à API do SuperFrete.
     */
    public function call_superfrete_api($endpoint, $method = 'GET', $payload = [], $retorno = false) {
        
        // Enhanced debug logging
        $environment = (strpos($this->api_url, 'sandbox') !== false || strpos($this->api_url, 'dev') !== false) ? 'SANDBOX/DEV' : 'PRODUCTION';
        $full_url = $this->api_url . $endpoint;
        $token_preview = !empty($this->api_token) ? substr($this->api_token, 0, 8) . '...' . substr($this->api_token, -4) : 'EMPTY';
        
        Logger::log('SuperFrete', "API CALL [{$environment}]: {$method} {$full_url}");
        Logger::log('SuperFrete', "TOKEN USADO [{$environment}]: {$token_preview}");
        
        if (empty($this->api_token)) {
            Logger::log('SuperFrete', 'API token is empty - cannot make API call');
            return false;
        }
        
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_token,
                'User-Agent' => 'WooCommerce SuperFrete Plugin (github.com/superfrete/woocommerce)',
                'Platform' => 'Woocommerce SuperFrete',
            ];

            $params = [
                'headers' => $headers,
                'method' => $method,
                'timeout' => 30, // Increased timeout to 30 seconds
            ];

            if ($method === 'POST' && !empty($payload)) {
                $params['body'] = wp_json_encode($payload);
                error_log('SuperFrete API Payload: ' . wp_json_encode($payload));
            }

            $start_time = microtime(true);
            $response = ($method === 'POST') ? wp_remote_post($this->api_url . $endpoint, $params) : wp_remote_get($this->api_url . $endpoint, $params);
            $end_time = microtime(true);
            $request_time = round(($end_time - $start_time) * 1000, 2);
            
            error_log('SuperFrete API Request Time: ' . $request_time . ' ms');

            // Check for WP errors first
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                Logger::log('SuperFrete', "WP Error na API ({$endpoint}): " . $error_message);
                error_log('SuperFrete API WP Error: ' . $error_message);
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            
            // Debug logging
            error_log('SuperFrete API Response: Status = ' . $status_code);
            error_log('SuperFrete API Response: Body = ' . substr($raw_body, 0, 500) . (strlen($raw_body) > 500 ? '...' : ''));

            // Check for HTTP errors
            if (!in_array($status_code, [200, 201, 204])) {
                $error_msg = "ERRO NA API ({$endpoint}): CÓDIGO {$status_code}";
                
                // Special handling for 401 errors
                if ($status_code == 401) {
                    $error_msg .= " - NÃO AUTENTICADO!";
                    Logger::log('SuperFrete', $error_msg);
                    Logger::log('SuperFrete', "DETALHES [{$environment}]: URL={$full_url}, TOKEN={$token_preview}");
                    Logger::log('SuperFrete', "RESPOSTA: " . (strlen($raw_body) > 200 ? substr($raw_body, 0, 200) . '...' : $raw_body));
                } else {
                    Logger::log('SuperFrete', $error_msg . "\nDETALHES: " . (strlen($raw_body) > 200 ? substr($raw_body, 0, 200) . '...' : ($raw_body ?: 'SEM DETALHES')));
                }
                
                return false;
            }

            // Handle empty responses (common for DELETE operations)
            if (empty($raw_body) && $status_code == 204) {
                Logger::log('SuperFrete', "API call successful ({$endpoint}) - No content returned (HTTP {$status_code})");
                return true; // Success for DELETE operations
            }
            
            $body = json_decode($raw_body, true);
            
            // Check for JSON decode errors only if there's content to decode
            if (!empty($raw_body) && json_last_error() !== JSON_ERROR_NONE) {
                Logger::log('SuperFrete', "JSON decode error na API ({$endpoint}): " . json_last_error_msg() . " - Raw response: " . substr($raw_body, 0, 200));
                error_log('SuperFrete API JSON Error: ' . json_last_error_msg());
                return false;
            }

            // Check for API-level errors
            if (isset($body['success']) && $body['success'] === false) {
                $error_message = isset($body['message']) ? $body['message'] : 'Erro desconhecido';
                $errors = $this->extract_api_errors($body);
                Logger::log('SuperFrete', "API Error ({$endpoint}): {$error_message}\nDetalhes: {$errors}");
                error_log('SuperFrete API Error: ' . $error_message . ' - Details: ' . $errors);
                return false;
            }

            Logger::log('SuperFrete', "API call successful ({$endpoint}) - Time: {$request_time}ms");
            return $body;

        } catch (Exception $exc) {
            Logger::log('SuperFrete', "Exception na API ({$endpoint}): " . $exc->getMessage());
            error_log('SuperFrete API Exception: ' . $exc->getMessage());
            return false;
        }
    }

    /**
     * Extract error details from API response
     */
    private function extract_api_errors($body) {
        $errors = [];
        
        if (isset($body['errors'])) {
            foreach ($body['errors'] as $field => $field_errors) {
                if (is_array($field_errors)) {
                    $errors[] = $field . ': ' . implode(', ', $field_errors);
                } else {
                    $errors[] = $field . ': ' . $field_errors;
                }
            }
        } elseif (isset($body['error'])) {
            if (is_array($body['error'])) {
                foreach ($body['error'] as $error) {
                    if (is_array($error)) {
                        $errors[] = implode(', ', $error);
                    } else {
                        $errors[] = $error;
                    }
                }
            } else {
                $errors[] = $body['error'];
            }
        }
        
        return empty($errors) ? 'Sem detalhes' : implode('; ', $errors);
    }

    /**
     * Register webhook with SuperFrete API
     */
    public function register_webhook($webhook_url, $events = ['order.posted', 'order.delivered']) 
    {
        Logger::log('SuperFrete', 'Iniciando registro de webhook...');
        Logger::log('SuperFrete', "Token sendo usado: " . (empty($this->api_token) ? 'VAZIO' : 'Presente'));
        Logger::log('SuperFrete', "URL da API: " . $this->api_url);

        // First, check for existing webhooks and clean them up
        Logger::log('SuperFrete', 'Verificando webhooks existentes...');
        $existing_webhooks = $this->list_webhooks();
        
        if ($existing_webhooks && is_array($existing_webhooks)) {
            Logger::log('SuperFrete', 'Encontrados ' . count($existing_webhooks) . ' webhooks existentes');
            
            foreach ($existing_webhooks as $webhook) {
                if (isset($webhook['id'])) {
                    Logger::log('SuperFrete', 'Removendo webhook existente ID: ' . $webhook['id']);
                    $this->delete_webhook($webhook['id'], false); // Don't clear options during cleanup
                }
            }
        } else {
            Logger::log('SuperFrete', 'Nenhum webhook existente encontrado');
        }

        // Now register the new webhook
        $payload = [
            'name' => 'WooCommerce SuperFrete Plugin Webhook',
            'url' => $webhook_url,
            'events' => $events
        ];

        Logger::log('SuperFrete', 'Registrando novo webhook: ' . wp_json_encode($payload));

        $response = $this->call_superfrete_api('/api/v0/webhook', 'POST', $payload, true);

        if ($response && isset($response['secret_token'])) {
            // Store webhook secret for signature verification
            update_option('superfrete_webhook_secret', $response['secret_token']);
            update_option('superfrete_webhook_registered', 'yes');
            update_option('superfrete_webhook_url', $webhook_url);
            update_option('superfrete_webhook_id', $response['id'] ?? '');
            
            Logger::log('SuperFrete', 'Webhook registrado com sucesso. ID: ' . ($response['id'] ?? 'N/A'));
            return $response;
        }

        Logger::log('SuperFrete', 'Falha ao registrar webhook: ' . wp_json_encode($response));
        update_option('superfrete_webhook_registered', 'no');
        return false;
    }

    /**
     * Update existing webhook
     */
    public function update_webhook($webhook_id, $webhook_url, $events = ['order.posted', 'order.delivered'])
    {
        $payload = [
            'name' => 'WooCommerce SuperFrete Plugin Webhook',
            'url' => $webhook_url,
            'events' => $events
        ];

        Logger::log('SuperFrete', 'Atualizando webhook ID: ' . $webhook_id);

        $response = $this->call_superfrete_api('/api/v0/webhook/' . $webhook_id, 'PUT', $payload, true);

        if ($response) {
            update_option('superfrete_webhook_url', $webhook_url);
            Logger::log('SuperFrete', 'Webhook atualizado com sucesso');
            return $response;
        }

        Logger::log('SuperFrete', 'Falha ao atualizar webhook: ' . wp_json_encode($response));
        return false;
    }

    /**
     * Delete webhook from SuperFrete
     */
    public function delete_webhook($webhook_id, $clear_options = true)
    {
        Logger::log('SuperFrete', 'Removendo webhook ID: ' . $webhook_id);

        $response = $this->call_superfrete_api('/api/v0/webhook/' . $webhook_id, 'DELETE', [], true);

        if ($response !== false) {
            if ($clear_options) {
                update_option('superfrete_webhook_registered', 'no');
                update_option('superfrete_webhook_url', '');
                update_option('superfrete_webhook_id', '');
            }
            Logger::log('SuperFrete', 'Webhook removido com sucesso');
            return true;
        }

        Logger::log('SuperFrete', 'Falha ao remover webhook: ' . wp_json_encode($response));
        return false;
    }

    /**
     * List registered webhooks
     */
    public function list_webhooks()
    {
        Logger::log('SuperFrete', 'Listando webhooks registrados');
        
        $response = $this->call_superfrete_api('/api/v0/webhook', 'GET', [], true);
        
        if ($response) {
            Logger::log('SuperFrete', 'Webhooks listados: ' . wp_json_encode($response));
            return $response;
        }

        Logger::log('SuperFrete', 'Falha ao listar webhooks');
        return false;
    }
}
