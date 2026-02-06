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
        
        // Check if proxy is configured and force proxy mode is enabled
        $proxy_url = get_option('superfrete_proxy_url');
        
        // Set default proxy URL if not configured
        if (empty($proxy_url)) {
            $proxy_url = 'https://api.dev.superintegrador.superfrete.com/headless/proxy/superfrete';
            Logger::log('SuperFrete', "Using default proxy URL: {$proxy_url}");
        }
        
        $force_proxy = get_option('superfrete_force_proxy', 'no') === 'yes';
        
        if (!empty($proxy_url) && $force_proxy) {
            Logger::log('SuperFrete', "Force proxy mode enabled - using proxy directly");
            return $this->call_via_proxy($proxy_url, $endpoint, $method, $payload);
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
                'sslverify' => false, // Skip SSL verification for faster connection
                'redirection' => 5,
                'httpversion' => '1.1',
            ];
            
            // LiteSpeed server detected - add additional timeout parameters
            if (isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false) {
                // LiteSpeed often ignores standard timeout - try cURL-specific options
                $params['timeout'] = 35; // Slightly higher for LiteSpeed
                $params['user-agent'] = 'WordPress/' . get_bloginfo('version') . '; SuperFrete Plugin';
                
                // Add stream context options for alternative transport
                $params['stream_context'] = stream_context_create([
                    'http' => [
                        'timeout' => 35,
                        'user_agent' => 'WordPress/' . get_bloginfo('version') . '; SuperFrete Plugin',
                    ]
                ]);
            }

            if ($method === 'POST' && !empty($payload)) {
                $params['body'] = wp_json_encode($payload);
                Logger::debug('API Payload: ' . wp_json_encode($payload), 'Request');
            }

            // Force timeout to prevent hosting overrides
            $timeout_filter = function() { return 30; };
            $args_filter = function($args) {
                if (isset($args['headers']['Authorization']) && strpos($args['headers']['Authorization'], 'Bearer') === 0) {
                    $args['timeout'] = 30;
                }
                return $args;
            };
            
            add_filter('http_request_timeout', $timeout_filter);
            add_filter('http_request_args', $args_filter);
            
            $max_attempts = 3;
            $attempt = 1;
            $response = null;
            
            while ($attempt <= $max_attempts) {
                $start_time = microtime(true);
                $response = ($method === 'POST') ? wp_remote_post($this->api_url . $endpoint, $params) : wp_remote_get($this->api_url . $endpoint, $params);
                $end_time = microtime(true);
                
                // If successful or not a timeout, break
                if (!is_wp_error($response) || strpos($response->get_error_message(), 'timeout') === false) {
                    break;
                }
                
                // Log retry attempt
                $error_msg = $response->get_error_message();
                Logger::log('SuperFrete', "Attempt {$attempt}/{$max_attempts} failed: {$error_msg}");
                
                $attempt++;
                if ($attempt <= $max_attempts) {
                    // Wait before retry (exponential backoff)
                    $wait_seconds = pow(2, $attempt - 1);
                    Logger::log('SuperFrete', "Retrying in {$wait_seconds} seconds...");
                    sleep($wait_seconds);
                }
            }
            
            // Remove filters to not affect other plugins
            remove_filter('http_request_timeout', $timeout_filter);
            remove_filter('http_request_args', $args_filter);
            $request_time = round(($end_time - $start_time) * 1000, 2);

            Logger::debug('API Request Time: ' . $request_time . ' ms', 'Request');

            // Check for WP errors first (timeout, connection issues, etc.)
            if (is_wp_error($response)) {
                $error_code = $response->get_error_code();
                $error_message = $response->get_error_message();
                
                // Collect diagnostic information
                $diagnostics = [
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'request_time_ms' => round(($end_time - $start_time) * 1000, 2),
                    'configured_timeout' => $params['timeout'] ?? 'unknown',
                    'api_url' => $this->api_url,
                    'environment' => $environment,
                    'wp_version' => get_bloginfo('version'),
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
                    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
                ];
                
                // Check if it's a timeout specifically
                if (strpos($error_message, 'timeout') !== false || strpos($error_message, 'timed out') !== false) {
                    $diagnostics['timeout_type'] = 'connection_timeout';
                    
                    // Check for hosting-specific indicators
                    if (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
                        $diagnostics['server_type'] = 'nginx';
                    } elseif (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache') !== false) {
                        $diagnostics['server_type'] = 'apache';
                    }
                    
                    // Check for common hosting providers
                    $host_indicators = [
                        'hostgator' => strpos($_SERVER['HTTP_HOST'] ?? '', 'hostgator') !== false,
                        'godaddy' => strpos($_SERVER['HTTP_HOST'] ?? '', 'secureserver') !== false,
                        'bluehost' => strpos($_SERVER['HTTP_HOST'] ?? '', 'bluehost') !== false,
                        'siteground' => strpos($_SERVER['HTTP_HOST'] ?? '', 'siteground') !== false,
                        'wpengine' => strpos($_SERVER['HTTP_HOST'] ?? '', 'wpengine') !== false,
                    ];
                    $diagnostics['hosting_indicators'] = array_filter($host_indicators);
                    
                    // Check PHP configurations that might affect timeouts
                    $diagnostics['php_config'] = [
                        'max_execution_time' => ini_get('max_execution_time'),
                        'default_socket_timeout' => ini_get('default_socket_timeout'),
                        'curl_available' => function_exists('curl_init'),
                        'openssl_version' => OPENSSL_VERSION_TEXT ?? 'unknown',
                    ];
                    
                    // Test basic connectivity
                    $diagnostics['connectivity_test'] = [
                        'can_resolve_dns' => gethostbyname('api.superfrete.com') !== 'api.superfrete.com',
                        'superfrete_ip' => gethostbyname('api.superfrete.com'),
                    ];
                }
                
                $diagnostic_json = wp_json_encode($diagnostics, JSON_PRETTY_PRINT);

                Logger::log("TIMEOUT DIAGNOSTICS:\n" . $diagnostic_json, 'ERROR');
                Logger::debug('TIMEOUT DIAGNOSTICS: ' . $diagnostic_json, 'Request');
                
                // Also log the original error message for backwards compatibility
                Logger::log('SuperFrete', "WP Error na API ({$endpoint}): " . $error_message);
                
                // Check if this is a timeout and we have a proxy available for fallback
                if ((strpos($error_message, 'timeout') !== false || strpos($error_message, 'timed out') !== false) && !empty($proxy_url)) {
                    Logger::log('SuperFrete', "TIMEOUT DETECTED - Attempting automatic proxy fallback");
                    
                    $proxy_result = $this->call_via_proxy($proxy_url, $endpoint, $method, $payload);
                    if ($proxy_result !== false) {
                        Logger::log('SuperFrete', "PROXY FALLBACK SUCCESSFUL - Enabling proxy for future requests");
                        
                        // Enable force proxy mode to avoid future timeouts
                        update_option('superfrete_force_proxy', 'yes');
                        
                        return $proxy_result;
                    } else {
                        Logger::log('SuperFrete', "PROXY FALLBACK ALSO FAILED");
                    }
                }
                
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);

            // Debug logging
            Logger::debug('API Response: Status = ' . $status_code, 'Request');
            Logger::debug('API Response: Body = ' . substr($raw_body, 0, 500) . (strlen($raw_body) > 500 ? '...' : ''), 'Request');

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
                Logger::log("JSON decode error na API ({$endpoint}): " . json_last_error_msg() . " - Raw response: " . substr($raw_body, 0, 200), 'ERROR');
                return false;
            }

            // Check for API-level errors
            if (isset($body['success']) && $body['success'] === false) {
                $error_message = isset($body['message']) ? $body['message'] : 'Erro desconhecido';
                $errors = $this->extract_api_errors($body);
                Logger::log("API Error ({$endpoint}): {$error_message}\nDetalhes: {$errors}", 'ERROR');
                return false;
            }

            Logger::log('SuperFrete', "API call successful ({$endpoint}) - Time: {$request_time}ms");
            return $body;

        } catch (Exception $exc) {
            Logger::log("Exception na API ({$endpoint}): " . $exc->getMessage(), 'ERROR');
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

    /**
     * Make API call via proxy to work around hosting timeouts
     */
    private function call_via_proxy($proxy_url, $endpoint, $method, $payload = []) {
        Logger::log('SuperFrete', "Using proxy for API call: {$proxy_url}");
        
        $proxy_payload = [
            'endpoint' => $endpoint,
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
            ]
        ];
        
        if (!empty($payload)) {
            $proxy_payload['body'] = $payload;
        }
        
        $params = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'method' => 'POST',
            'body' => wp_json_encode($proxy_payload),
            'timeout' => 65, // Slightly higher than proxy timeout
        ];
        
        $start_time = microtime(true);
        $response = wp_remote_post($proxy_url, $params);
        $end_time = microtime(true);
        $request_time = round(($end_time - $start_time) * 1000, 2);
        
        Logger::log('SuperFrete', "Proxy request time: {$request_time} ms");
        
        if (is_wp_error($response)) {
            Logger::log('SuperFrete', 'Proxy request failed: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code !== 200) {
            Logger::log('SuperFrete', "Proxy returned error: {$status_code} - {$body}");
            return false;
        }
        
        if (!$data || !$data['success']) {
            Logger::log('SuperFrete', 'Proxy request unsuccessful: ' . wp_json_encode($data));
            return false;
        }
        
        Logger::log('SuperFrete', "Proxy call successful - Duration: {$data['duration']}");
        return $data['data'];
    }
}
