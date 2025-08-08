<?php

namespace SuperFrete_API\Controllers;

use SuperFrete_API\Http\WebhookVerifier;
use SuperFrete_API\Helpers\Logger;
use SuperFrete_API\Controllers\WebhookRetryManager;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class WebhookController
{
    
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes for webhooks
     */
    public function register_routes()
    {
        register_rest_route('superfrete/v1', '/webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // We handle auth via signature verification
            'args' => []
        ]);
        
        // Test endpoint for development
        register_rest_route('superfrete/v1', '/webhook/test', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'webhook_test'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
    }
    
    /**
     * Handle incoming webhook from SuperFrete
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_webhook(WP_REST_Request $request)
    {
        $start_time = microtime(true);
        $webhook_id = wp_generate_uuid4();
        
        Logger::log('SuperFrete', "Webhook received: ID $webhook_id");
        
        try {
            // Get request data
            $payload = $request->get_json_params();
            $headers = WebhookVerifier::get_webhook_headers();
            
            // Log incoming webhook
            $this->log_webhook_attempt($webhook_id, $payload, $headers, 'received');
            
            // Validate payload structure
            if (!WebhookVerifier::validate_payload_structure($payload)) {
                return $this->webhook_error_response(
                    'Invalid payload structure', 
                    400, 
                    $webhook_id,
                    $payload,
                    $start_time
                );
            }
            
            // Verify signature
            $webhook_secret = get_option('superfrete_webhook_secret');
            if (!$webhook_secret) {
                return $this->webhook_error_response(
                    'Webhook secret not configured', 
                    500, 
                    $webhook_id,
                    $payload,
                    $start_time
                );
            }
            
            $raw_body = $request->get_body();
            if (!WebhookVerifier::verify_signature($raw_body, $headers['signature'], $webhook_secret)) {
                return $this->webhook_error_response(
                    'Invalid signature', 
                    401, 
                    $webhook_id,
                    $payload,
                    $start_time
                );
            }
            
            Logger::log('SuperFrete', "Webhook signature verified: ID $webhook_id");
            
            // Process the webhook
            $result = $this->process_webhook($payload, $webhook_id);
            
            if ($result['success']) {
                $this->log_webhook_success($webhook_id, $payload, $result, $start_time);
                
                return new WP_REST_Response([
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'webhook_id' => $webhook_id
                ], 200);
            } else {
                // Queue for retry if processing failed
                $this->queue_webhook_retry($payload, $result['error']);
                
                return $this->webhook_error_response(
                    $result['error'], 
                    500, 
                    $webhook_id,
                    $payload,
                    $start_time
                );
            }
            
        } catch (Exception $e) {
            Logger::log('SuperFrete', "Webhook exception: ID $webhook_id - " . $e->getMessage());
            
            // Queue for retry on exception
            if (isset($payload)) {
                $this->queue_webhook_retry($payload, $e->getMessage());
            }
            
            return $this->webhook_error_response(
                'Internal server error', 
                500, 
                $webhook_id,
                $payload ?? [],
                $start_time
            );
        }
    }
    
    /**
     * Process webhook event
     * 
     * @param array $payload
     * @param string $webhook_id
     * @return array
     */
    private function process_webhook($payload, $webhook_id)
    {
        try {
            $event_type = $payload['event'];
            $superfrete_data = $payload['data'];
            $superfrete_id = $superfrete_data['id'];
            
            Logger::log('SuperFrete', "Processing $event_type for freight ID: $superfrete_id");
            
            // Find order by SuperFrete ID
            $order_id = $this->find_order_by_superfrete_id($superfrete_id);
            if (!$order_id) {
                return [
                    'success' => false,
                    'error' => "Order not found for SuperFrete ID: $superfrete_id"
                ];
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return [
                    'success' => false,
                    'error' => "Invalid order ID: $order_id"
                ];
            }
            
            Logger::log('SuperFrete', "Found order #$order_id for freight ID: $superfrete_id");
            
            // Process based on event type
            switch ($event_type) {
                case 'order.posted':
                    return $this->handle_order_posted($order, $superfrete_data, $webhook_id);
                    
                case 'order.delivered':
                    return $this->handle_order_delivered($order, $superfrete_data, $webhook_id);
                    
                default:
                    return [
                        'success' => false,
                        'error' => "Unsupported event type: $event_type"
                    ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle order.posted event
     * 
     * @param WC_Order $order
     * @param array $superfrete_data
     * @param string $webhook_id
     * @return array
     */
    private function handle_order_posted($order, $superfrete_data, $webhook_id)
    {
        try {
            $order_id = $order->get_id();
            
            // Update order status to shipped
            $order->update_status('shipped', 'SuperFrete: Pedido postado nos Correios');
            
            // Store tracking information
            $tracking_code = $superfrete_data['tracking'] ?? '';
            $tracking_url = $superfrete_data['tracking_url'] ?? '';
            $posted_at = $superfrete_data['posted_at'] ?? '';
            
                    $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_superfrete_tracking_code', $tracking_code);
            $order->update_meta_data('_superfrete_tracking_url', $tracking_url);
            $order->update_meta_data('_superfrete_posted_at', $posted_at);
            $order->update_meta_data('_superfrete_webhook_id', $webhook_id);
            $order->save();
        }
            
            // Add order note
            $note = sprintf(
                'SuperFrete: Pedido postado nos Correios%s%s',
                $tracking_code ? "\nCódigo de rastreamento: $tracking_code" : '',
                $tracking_url ? "\nURL de rastreamento: $tracking_url" : ''
            );
            $order->add_order_note($note);
            
            Logger::log('SuperFrete', "Order #$order_id status updated to shipped with tracking: $tracking_code");
            
            return ['success' => true, 'message' => 'Order marked as shipped'];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to process order.posted: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle order.delivered event
     * 
     * @param WC_Order $order
     * @param array $superfrete_data
     * @param string $webhook_id
     * @return array
     */
    private function handle_order_delivered($order, $superfrete_data, $webhook_id)
    {
        try {
            $order_id = $order->get_id();
            
            // Update order status to completed
            $order->update_status('completed', 'SuperFrete: Pedido entregue');
            
            // Store delivery information
            $delivered_at = $superfrete_data['delivered_at'] ?? '';
            $tracking_code = $superfrete_data['tracking'] ?? '';
            
                    $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_superfrete_delivered_at', $delivered_at);
            $order->update_meta_data('_superfrete_delivery_webhook_id', $webhook_id);
            $order->save();
        }
            
            // Add order note
            $note = sprintf(
                'SuperFrete: Pedido entregue%s%s',
                $delivered_at ? "\nData de entrega: $delivered_at" : '',
                $tracking_code ? "\nCódigo de rastreamento: $tracking_code" : ''
            );
            $order->add_order_note($note);
            
            Logger::log('SuperFrete', "Order #$order_id marked as delivered");
            
            return ['success' => true, 'message' => 'Order marked as delivered'];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to process order.delivered: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Find order by SuperFrete ID
     * 
     * @param string $superfrete_id
     * @return int|false
     */
    private function find_order_by_superfrete_id($superfrete_id)
    {
        global $wpdb;
        
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_superfrete_id' AND meta_value = %s",
            $superfrete_id
        ));
        
        return $order_id ? (int) $order_id : false;
    }
    
    /**
     * Queue webhook for retry
     * 
     * @param array $payload
     * @param string $error_message
     */
    private function queue_webhook_retry($payload, $error_message)
    {
        if (!isset($payload['data']['id'])) {
            return;
        }
        
        $order_id = $this->find_order_by_superfrete_id($payload['data']['id']);
        
        // Use WebhookRetryManager to queue the retry
        $retry_manager = new WebhookRetryManager();
        $retry_manager->queue_retry(
            $order_id ?: 0,
            $payload['data']['id'],
            $payload['event'],
            $payload,
            $error_message
        );
    }
    
    /**
     * Log webhook attempt
     */
    private function log_webhook_attempt($webhook_id, $payload, $headers, $status)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_logs';
        
        $order_id = null;
        $superfrete_id = null;
        $event_type = '';
        
        if (isset($payload['data']['id'])) {
            $superfrete_id = $payload['data']['id'];
            $order_id = $this->find_order_by_superfrete_id($superfrete_id);
        }
        
        if (isset($payload['event'])) {
            $event_type = $payload['event'];
        }
        
        $wpdb->insert($table_name, [
            'webhook_id' => $webhook_id,
            'order_id' => $order_id,
            'superfrete_id' => $superfrete_id,
            'event_type' => $event_type,
            'status' => $status,
            'payload' => wp_json_encode($payload),
            'created_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Log successful webhook processing
     */
    private function log_webhook_success($webhook_id, $payload, $result, $start_time)
    {
        global $wpdb;
        
        $processing_time = round((microtime(true) - $start_time) * 1000);
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_logs';
        
        $wpdb->update(
            $table_name,
            [
                'status' => 'success',
                'response_data' => wp_json_encode($result),
                'processing_time_ms' => $processing_time
            ],
            ['webhook_id' => $webhook_id],
            ['%s', '%s', '%d'],
            ['%s']
        );
    }
    
    /**
     * Create error response and log it
     */
    private function webhook_error_response($message, $status_code, $webhook_id, $payload, $start_time)
    {
        global $wpdb;
        
        $processing_time = round((microtime(true) - $start_time) * 1000);
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_logs';
        
        $wpdb->update(
            $table_name,
            [
                'status' => 'error',
                'http_status_code' => $status_code,
                'error_message' => $message,
                'processing_time_ms' => $processing_time
            ],
            ['webhook_id' => $webhook_id],
            ['%s', '%d', '%s', '%d'],
            ['%s']
        );
        
        Logger::log('SuperFrete', "Webhook error: $message (ID: $webhook_id)");
        
        return new WP_Error('webhook_error', $message, ['status' => $status_code]);
    }
    
    /**
     * Webhook test endpoint
     */
    public function webhook_test(WP_REST_Request $request)
    {
        return new WP_REST_Response([
            'status' => 'success',
            'message' => 'SuperFrete webhook endpoint is working',
            'timestamp' => current_time('mysql'),
            'url' => rest_url('superfrete/v1/webhook')
        ], 200);
    }
    
    /**
     * Check admin permissions
     */
    public function check_admin_permissions()
    {
        return current_user_can('manage_woocommerce');
    }
} 
