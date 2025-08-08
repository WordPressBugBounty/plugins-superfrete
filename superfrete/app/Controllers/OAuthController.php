<?php

namespace SuperFrete_API\Controllers;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class OAuthController
{
    
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Also register AJAX handlers as backup
        add_action('wp_ajax_superfrete_oauth_proxy', [$this, 'ajax_oauth_proxy']);
        add_action('wp_ajax_nopriv_superfrete_oauth_proxy', [$this, 'ajax_oauth_proxy']);
    }
    
    /**
     * Register REST API routes for OAuth proxy
     */
    public function register_routes()
    {
        // Add debug logging
        error_log('SuperFrete OAuth: Registering REST API routes');
        
        // Proxy endpoint for polling OAuth token
        $result = register_rest_route('superfrete/v1', '/oauth/token', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'proxy_oauth_token'],
            'permission_callback' => '__return_true', // Temporarily allow all for debugging
            'args' => [
                'session_id' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'OAuth session ID'
                ]
            ]
        ]);
        
        if ($result) {
            error_log('SuperFrete OAuth: REST route registered successfully');
        } else {
            error_log('SuperFrete OAuth: Failed to register REST route');
        }
    }
    
    /**
     * AJAX handler for OAuth proxy (backup method)
     */
    public function ajax_oauth_proxy()
    {
        error_log('SuperFrete OAuth: AJAX proxy called');
        
        // Check nonce for security
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'wp_rest')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $session_id = sanitize_text_field($_GET['session_id'] ?? '');
        error_log('SuperFrete OAuth: AJAX session_id = ' . $session_id);
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID is required');
            return;
        }
        
        // Get the headless API URL from settings
        $api_url = $this->get_headless_api_url();
        if (!$api_url) {
            wp_send_json_error('Headless API URL not configured');
            return;
        }
        
        // Make server-side request to headless API
        $url = $api_url . '/headless/oauth/token?session_id=' . urlencode($session_id);
        error_log('SuperFrete OAuth: AJAX calling URL = ' . $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'SuperFrete-WordPress-Plugin/2.1.4'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('SuperFrete OAuth: AJAX wp_remote_get error = ' . $response->get_error_message());
            wp_send_json_error('Failed to connect to headless API: ' . $response->get_error_message());
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('SuperFrete OAuth: AJAX response status = ' . $status_code);
        error_log('SuperFrete OAuth: AJAX response body = ' . $body);
        
        // Try to decode JSON response
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON response from API');
            return;
        }
        
        // Return the data
        wp_send_json($data);
    }
    
    /**
     * Proxy OAuth token request to headless API
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function proxy_oauth_token(WP_REST_Request $request)
    {
        error_log('SuperFrete OAuth: proxy_oauth_token called');
        
        $session_id = $request->get_param('session_id');
        error_log('SuperFrete OAuth: session_id = ' . $session_id);
        
        if (empty($session_id)) {
            error_log('SuperFrete OAuth: Missing session_id');
            return new WP_Error('missing_session_id', 'Session ID is required', ['status' => 400]);
        }
        
        // Get the headless API URL from settings
        $api_url = $this->get_headless_api_url();
        if (!$api_url) {
            return new WP_Error('api_url_not_configured', 'Headless API URL not configured', ['status' => 500]);
        }
        
        // Make server-side request to headless API
        $url = $api_url . '/headless/oauth/token?session_id=' . urlencode($session_id);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'SuperFrete-WordPress-Plugin/2.1.4'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'Failed to connect to headless API: ' . $response->get_error_message(), ['status' => 500]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Try to decode JSON response
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json_response', 'Invalid JSON response from API', ['status' => 500]);
        }
        
        // Return the same response structure as the headless API
        return new WP_REST_Response($data, $status_code);
    }
    
    /**
     * Get headless API URL from WordPress settings
     * 
     * @return string|null
     */
    private function get_headless_api_url()
    {
        // Check if we're in sandbox mode
        $sandbox_mode = get_option('superfrete_sandbox_mode', 'no');
        
        // Debug logging
        error_log('SuperFrete OAuth: sandbox_mode setting = ' . $sandbox_mode);
        
        if ($sandbox_mode === 'yes') {
            // Development/sandbox environment
            $api_url = 'https://api.dev.superintegrador.superfrete.com';
            error_log('SuperFrete OAuth: Using dev API = ' . $api_url);
            return $api_url;
        } else {
            // Production environment
            $api_url = 'https://api.superintegrador.superfrete.com';
            error_log('SuperFrete OAuth: Using production API = ' . $api_url);
            return $api_url;
        }
    }
    
    /**
     * Check if user has admin permissions
     * 
     * @return bool
     */
    public function check_admin_permissions()
    {
        return current_user_can('manage_options');
    }
} 