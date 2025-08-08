<?php

namespace SuperFrete_API\Http;

use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class WebhookVerifier
{
    
    /**
     * Verify webhook signature using HMAC SHA-256
     * 
     * @param array $payload The webhook payload
     * @param string $signature The signature from x-me-signature header
     * @param string $secret The webhook secret
     * @return bool
     */
    public static function verify_signature($payload, $signature, $secret)
    {
        if (empty($signature) || empty($secret)) {
            Logger::log('SuperFrete', 'Webhook verification failed: Missing signature or secret');
            return false;
        }

        // Convert payload to JSON string for signature calculation
        $payload_json = is_string($payload) ? $payload : wp_json_encode($payload);
        
        // Calculate expected signature
        $expected_signature = hash_hmac('sha256', $payload_json, $secret);
        
        // Remove 'sha256=' prefix if present
        $provided_signature = str_replace('sha256=', '', $signature);
        
        // Use hash_equals for timing-safe comparison
        $is_valid = hash_equals($expected_signature, $provided_signature);
        
        if (!$is_valid) {
            Logger::log('SuperFrete', 'Webhook signature verification failed', [
                'expected' => $expected_signature,
                'provided' => $provided_signature,
                'payload_length' => strlen($payload_json)
            ]);
        }
        
        return $is_valid;
    }
    
    /**
     * Extract and validate webhook headers
     * 
     * @return array
     */
    public static function get_webhook_headers()
    {
        $headers = [];
        
        // Get signature from header
        $signature = null;
        if (function_exists('getallheaders')) {
            $all_headers = getallheaders();
            $signature = $all_headers['x-me-signature'] ?? $all_headers['X-Me-Signature'] ?? null;
        }
        
        // Fallback to $_SERVER
        if (!$signature) {
            $signature = $_SERVER['HTTP_X_ME_SIGNATURE'] ?? null;
        }
        
        $headers['signature'] = $signature;
        $headers['content_type'] = $_SERVER['CONTENT_TYPE'] ?? '';
        $headers['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return $headers;
    }
    
    /**
     * Validate webhook payload structure
     * 
     * @param array $payload
     * @return bool
     */
    public static function validate_payload_structure($payload)
    {
        if (!is_array($payload)) {
            Logger::log('SuperFrete', 'Invalid webhook payload: Not an array');
            return false;
        }
        
        // Check required fields
        $required_fields = ['event', 'data'];
        foreach ($required_fields as $field) {
            if (!isset($payload[$field])) {
                Logger::log('SuperFrete', "Invalid webhook payload: Missing field '$field'");
                return false;
            }
        }
        
        // Validate event type
        $supported_events = ['order.posted', 'order.delivered'];
        if (!in_array($payload['event'], $supported_events)) {
            Logger::log('SuperFrete', 'Unsupported webhook event: ' . $payload['event']);
            return false;
        }
        
        // Validate data structure
        if (!isset($payload['data']['id'])) {
            Logger::log('SuperFrete', 'Invalid webhook payload: Missing data.id');
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate webhook secret
     * 
     * @param int $length
     * @return string
     */
    public static function generate_webhook_secret($length = 32)
    {
        return wp_generate_password($length, false);
    }
} 