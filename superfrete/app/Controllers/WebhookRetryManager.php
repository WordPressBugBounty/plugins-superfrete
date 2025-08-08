<?php

namespace SuperFrete_API\Controllers;

use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class WebhookRetryManager
{
    
    private $max_retries = 3;
    private $retry_table;
    
    public function __construct()
    {
        global $wpdb;
        $this->retry_table = $wpdb->prefix . 'superfrete_webhook_retries';
        
        // Hook into WordPress cron
        add_action('superfrete_process_webhook_retries', [$this, 'process_retries']);
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('superfrete_process_webhook_retries')) {
            wp_schedule_event(time(), 'hourly', 'superfrete_process_webhook_retries');
        }
    }
    
    /**
     * Queue a webhook for retry
     * 
     * @param int $order_id
     * @param string $superfrete_id
     * @param string $event_type
     * @param array $payload
     * @param string $error_message
     */
    public function queue_retry($order_id, $superfrete_id, $event_type, $payload, $error_message)
    {
        global $wpdb;
        
        // Check if this webhook is already queued for retry
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->retry_table} 
             WHERE superfrete_id = %s AND event_type = %s AND status = 'pending'",
            $superfrete_id,
            $event_type
        ));
        
        if ($existing) {
            Logger::log('SuperFrete', "Webhook retry already queued for {$superfrete_id} - {$event_type}");
            return;
        }
        
        // Calculate next retry time (start with 5 minutes)
        $next_retry = current_time('mysql', true);
        $next_retry = date('Y-m-d H:i:s', strtotime($next_retry . ' +5 minutes'));
        
        $result = $wpdb->insert($this->retry_table, [
            'order_id' => $order_id,
            'superfrete_id' => $superfrete_id,
            'event_type' => $event_type,
            'payload' => wp_json_encode($payload),
            'retry_count' => 0,
            'max_retries' => $this->max_retries,
            'next_retry_at' => $next_retry,
            'status' => 'pending',
            'error_message' => $error_message,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);
        
        if ($result) {
            Logger::log('SuperFrete', "Webhook queued for retry: {$superfrete_id} - {$event_type}");
        } else {
            Logger::log('SuperFrete', "Failed to queue webhook retry: " . $wpdb->last_error);
        }
    }
    
    /**
     * Process pending retries
     */
    public function process_retries()
    {
        global $wpdb;
        
        Logger::log('SuperFrete', 'Processing webhook retries...');
        
        // Get pending retries that are due for processing
        $retries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->retry_table} 
             WHERE status = 'pending' 
             AND next_retry_at <= %s 
             AND retry_count < max_retries 
             ORDER BY created_at ASC 
             LIMIT 10",
            current_time('mysql', true)
        ));
        
        if (empty($retries)) {
            Logger::log('SuperFrete', 'No webhook retries to process');
            return;
        }
        
        Logger::log('SuperFrete', 'Found ' . count($retries) . ' webhook retries to process');
        
        foreach ($retries as $retry) {
            $this->process_single_retry($retry);
        }
        
        // Clean up old completed/failed retries
        $this->cleanup_old_retries();
    }
    
    /**
     * Process a single retry
     * 
     * @param object $retry
     */
    private function process_single_retry($retry)
    {
        global $wpdb;
        
        try {
            Logger::log('SuperFrete', "Processing retry #{$retry->retry_count} for {$retry->superfrete_id}");
            
            // Increment retry count
            $new_retry_count = $retry->retry_count + 1;
            
            // Try to process the webhook
            $payload = json_decode($retry->payload, true);
            $result = $this->retry_webhook_processing($payload, $retry);
            
            if ($result['success']) {
                // Mark as completed
                $wpdb->update(
                    $this->retry_table,
                    [
                        'status' => 'completed',
                        'retry_count' => $new_retry_count,
                        'error_message' => null,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $retry->id],
                    ['%s', '%d', '%s', '%s'],
                    ['%d']
                );
                
                Logger::log('SuperFrete', "Webhook retry successful for {$retry->superfrete_id}");
                
            } else {
                // Check if we've reached max retries
                if ($new_retry_count >= $retry->max_retries) {
                    // Mark as permanently failed
                    $wpdb->update(
                        $this->retry_table,
                        [
                            'status' => 'failed',
                            'retry_count' => $new_retry_count,
                            'error_message' => $result['error'],
                            'updated_at' => current_time('mysql')
                        ],
                        ['id' => $retry->id],
                        ['%s', '%d', '%s', '%s'],
                        ['%d']
                    );
                    
                    Logger::log('SuperFrete', "Webhook retry permanently failed for {$retry->superfrete_id}: {$result['error']}");
                    
                } else {
                    // Schedule next retry with exponential backoff
                    $next_retry_minutes = pow(2, $new_retry_count) * 5; // 5, 10, 20 minutes
                    $next_retry = date('Y-m-d H:i:s', strtotime(current_time('mysql', true) . " +{$next_retry_minutes} minutes"));
                    
                    $wpdb->update(
                        $this->retry_table,
                        [
                            'retry_count' => $new_retry_count,
                            'next_retry_at' => $next_retry,
                            'error_message' => $result['error'],
                            'updated_at' => current_time('mysql')
                        ],
                        ['id' => $retry->id],
                        ['%d', '%s', '%s', '%s'],
                        ['%d']
                    );
                    
                    Logger::log('SuperFrete', "Webhook retry #{$new_retry_count} scheduled for {$retry->superfrete_id} at {$next_retry}");
                }
            }
            
        } catch (Exception $e) {
            Logger::log('SuperFrete', "Exception during webhook retry processing: " . $e->getMessage());
            
            // Update with error
            $wpdb->update(
                $this->retry_table,
                [
                    'retry_count' => $retry->retry_count + 1,
                    'error_message' => $e->getMessage(),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $retry->id],
                ['%d', '%s', '%s'],
                ['%d']
            );
        }
    }
    
    /**
     * Retry webhook processing
     * 
     * @param array $payload
     * @param object $retry_record
     * @return array
     */
    private function retry_webhook_processing($payload, $retry_record)
    {
        try {
            $event_type = $payload['event'];
            $superfrete_data = $payload['data'];
            $superfrete_id = $superfrete_data['id'];
            
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
            
            // Create webhook controller instance to use its methods
            $webhook_controller = new WebhookController();
            
            // Use reflection to call private methods
            $reflection = new ReflectionClass($webhook_controller);
            
            switch ($event_type) {
                case 'order.posted':
                    $method = $reflection->getMethod('handle_order_posted');
                    $method->setAccessible(true);
                    $result = $method->invoke($webhook_controller, $order, $superfrete_data, "retry-{$retry_record->id}");
                    break;
                    
                case 'order.delivered':
                    $method = $reflection->getMethod('handle_order_delivered');
                    $method->setAccessible(true);
                    $result = $method->invoke($webhook_controller, $order, $superfrete_data, "retry-{$retry_record->id}");
                    break;
                    
                default:
                    return [
                        'success' => false,
                        'error' => "Unsupported event type: $event_type"
                    ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
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
     * Clean up old retry records
     */
    private function cleanup_old_retries()
    {
        global $wpdb;
        
        // Delete completed retries older than 7 days
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->retry_table} 
             WHERE status IN ('completed', 'failed') 
             AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ));
        
        if ($deleted > 0) {
            Logger::log('SuperFrete', "Cleaned up {$deleted} old webhook retry records");
        }
    }
    
    /**
     * Get retry statistics
     * 
     * @return array
     */
    public function get_retry_stats()
    {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM {$this->retry_table}
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
            ARRAY_A
        );
        
        return $stats ?: [
            'total' => 0,
            'pending' => 0,
            'completed' => 0,
            'failed' => 0
        ];
    }
    
    /**
     * Get recent retry records
     * 
     * @param int $limit
     * @return array
     */
    public function get_recent_retries($limit = 50)
    {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->retry_table} 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Manually trigger retry processing
     */
    public function manual_process_retries()
    {
        Logger::log('SuperFrete', 'Manual webhook retry processing triggered');
        $this->process_retries();
    }
    
    /**
     * Cancel pending retries for a specific order
     * 
     * @param int $order_id
     */
    public function cancel_retries_for_order($order_id)
    {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->retry_table,
            ['status' => 'cancelled'],
            [
                'order_id' => $order_id,
                'status' => 'pending'
            ],
            ['%s'],
            ['%d', '%s']
        );
        
        if ($result > 0) {
            Logger::log('SuperFrete', "Cancelled {$result} pending webhook retries for order {$order_id}");
        }
        
        return $result;
    }
} 