<?php

namespace SuperFrete_API\Admin;

use SuperFrete_API\Controllers\WebhookRetryManager;
use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class WebhookAdmin
{
    
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_superfrete_manual_retry', [$this, 'handle_manual_retry']);
        add_action('wp_ajax_superfrete_clear_webhook_logs', [$this, 'handle_clear_logs']);
    }
    
    /**
     * Add admin menu for webhook management
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'SuperFrete Webhooks',
            'SuperFrete Webhooks',
            'manage_woocommerce',
            'superfrete-webhooks',
            [$this, 'webhook_admin_page']
        );
    }
    
    /**
     * Display the webhook admin page
     */
    public function webhook_admin_page()
    {
        $retry_manager = new WebhookRetryManager();
        $stats = $retry_manager->get_retry_stats();
        $recent_retries = $retry_manager->get_recent_retries(20);
        $recent_logs = $this->get_recent_webhook_logs(20);
        
        ?>
        <div class="wrap">
            <h1>SuperFrete Webhooks</h1>
            
            <div class="superfrete-webhook-stats" style="display: flex; gap: 20px; margin: 20px 0;">
                <div class="stat-card" style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; min-width: 150px;">
                    <h3 style="margin: 0 0 10px 0;">Total (30 dias)</h3>
                    <p style="font-size: 24px; margin: 0; color: #0073aa;"><?php echo esc_html($stats['total']); ?></p>
                </div>
                <div class="stat-card" style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; min-width: 150px;">
                    <h3 style="margin: 0 0 10px 0;">Pendentes</h3>
                    <p style="font-size: 24px; margin: 0; color: #d63638;"><?php echo esc_html($stats['pending']); ?></p>
                </div>
                <div class="stat-card" style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; min-width: 150px;">
                    <h3 style="margin: 0 0 10px 0;">Completados</h3>
                    <p style="font-size: 24px; margin: 0; color: #00a32a;"><?php echo esc_html($stats['completed']); ?></p>
                </div>
                <div class="stat-card" style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; min-width: 150px;">
                    <h3 style="margin: 0 0 10px 0;">Falharam</h3>
                    <p style="font-size: 24px; margin: 0; color: #d63638;"><?php echo esc_html($stats['failed']); ?></p>
                </div>
            </div>
            
            <div class="superfrete-webhook-actions" style="margin: 20px 0;">
                <button id="manual-retry-btn" class="button button-primary">Processar Tentativas Manuais</button>
                <button id="clear-logs-btn" class="button button-secondary">Limpar Logs Antigos</button>
            </div>
            
            <h2>Tentativas de Webhook (Últimas 20)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Pedido</th>
                        <th>SuperFrete ID</th>
                        <th>Evento</th>
                        <th>Status</th>
                        <th>Tentativas</th>
                        <th>Próxima Tentativa</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_retries)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">Nenhuma tentativa de webhook encontrada.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_retries as $retry): ?>
                            <tr>
                                <td><?php echo esc_html($retry->id); ?></td>
                                <td>
                                    <?php if ($retry->order_id): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $retry->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($retry->order_id); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($retry->superfrete_id); ?></td>
                                <td><?php echo esc_html($retry->event_type); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($retry->status); ?>" style="
                                        padding: 2px 8px; 
                                        border-radius: 3px; 
                                        color: white;
                                        background: <?php 
                                            switch($retry->status) {
                                                case 'pending': echo '#d63638'; break;
                                                case 'completed': echo '#00a32a'; break;
                                                case 'failed': echo '#8c8f94'; break;
                                                default: echo '#0073aa';
                                            }
                                        ?>;
                                    ">
                                        <?php echo esc_html(ucfirst($retry->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($retry->retry_count . '/' . $retry->max_retries); ?></td>
                                <td>
                                    <?php 
                                    if ($retry->next_retry_at && $retry->status === 'pending') {
                                        echo esc_html(date('d/m/Y H:i', strtotime($retry->next_retry_at)));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($retry->created_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <h2>Logs de Webhook (Últimos 20)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Webhook ID</th>
                        <th>Pedido</th>
                        <th>Evento</th>
                        <th>Status</th>
                        <th>HTTP Status</th>
                        <th>Tempo (ms)</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_logs)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">Nenhum log de webhook encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td><?php echo esc_html(substr($log->webhook_id, 0, 8)); ?>...</td>
                                <td>
                                    <?php if ($log->order_id): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $log->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($log->order_id); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->event_type); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($log->status); ?>" style="
                                        padding: 2px 8px; 
                                        border-radius: 3px; 
                                        color: white;
                                        background: <?php 
                                            switch($log->status) {
                                                case 'success': echo '#00a32a'; break;
                                                case 'error': echo '#d63638'; break;
                                                case 'received': echo '#0073aa'; break;
                                                default: echo '#8c8f94';
                                            }
                                        ?>;
                                    ">
                                        <?php echo esc_html(ucfirst($log->status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->http_status_code ?: '-'); ?></td>
                                <td><?php echo esc_html($log->processing_time_ms ?: '-'); ?></td>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($log->created_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#manual-retry-btn').click(function() {
                var button = $(this);
                var originalText = button.text();
                
                button.text('Processando...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'superfrete_manual_retry',
                        nonce: '<?php echo wp_create_nonce('superfrete_webhook_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Tentativas processadas com sucesso!');
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Erro de comunicação com o servidor.');
                    },
                    complete: function() {
                        button.text(originalText).prop('disabled', false);
                    }
                });
            });
            
            $('#clear-logs-btn').click(function() {
                if (confirm('Tem certeza que deseja limpar os logs antigos?')) {
                    var button = $(this);
                    var originalText = button.text();
                    
                    button.text('Limpando...').prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'superfrete_clear_webhook_logs',
                            nonce: '<?php echo wp_create_nonce('superfrete_webhook_admin_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Logs limpos com sucesso!');
                                location.reload();
                            } else {
                                alert('Erro: ' + response.data);
                            }
                        },
                        error: function() {
                            alert('Erro de comunicação com o servidor.');
                        },
                        complete: function() {
                            button.text(originalText).prop('disabled', false);
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get recent webhook logs
     */
    private function get_recent_webhook_logs($limit = 50)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Handle manual retry processing
     */
    public function handle_manual_retry()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'superfrete_webhook_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        try {
            $retry_manager = new WebhookRetryManager();
            $retry_manager->manual_process_retries();
            wp_send_json_success('Tentativas processadas manualmente.');
        } catch (Exception $e) {
            wp_send_json_error('Erro: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle clearing old logs
     */
    public function handle_clear_logs()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'superfrete_webhook_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        try {
            global $wpdb;
            
            $logs_table = $wpdb->prefix . 'superfrete_webhook_logs';
            $retries_table = $wpdb->prefix . 'superfrete_webhook_retries';
            
            // Delete logs older than 7 days
            $deleted_logs = $wpdb->query($wpdb->prepare(
                "DELETE FROM $logs_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ));
            
            // Delete completed retries older than 7 days
            $deleted_retries = $wpdb->query($wpdb->prepare(
                "DELETE FROM $retries_table WHERE status IN ('completed', 'failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ));
            
            Logger::log('SuperFrete', "Manual cleanup: {$deleted_logs} logs and {$deleted_retries} retries deleted");
            
            wp_send_json_success("Logs limpos: {$deleted_logs} logs e {$deleted_retries} tentativas removidos.");
        } catch (Exception $e) {
            wp_send_json_error('Erro: ' . $e->getMessage());
        }
    }
} 
