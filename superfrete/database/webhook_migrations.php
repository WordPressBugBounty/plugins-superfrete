<?php

namespace SuperFrete_API\Database;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class WebhookMigrations
{
    
    /**
     * Create webhook retry queue table
     */
    public static function create_webhook_retry_table()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_retries';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            superfrete_id varchar(255) NOT NULL,
            event_type varchar(50) NOT NULL,
            payload longtext NOT NULL,
            retry_count tinyint(4) NOT NULL DEFAULT 0,
            max_retries tinyint(4) NOT NULL DEFAULT 3,
            next_retry_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY superfrete_id (superfrete_id),
            KEY status (status),
            KEY next_retry_at (next_retry_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store table version for future migrations
        update_option('superfrete_webhook_table_version', '1.0');
    }
    
    /**
     * Create webhook logs table
     */
    public static function create_webhook_logs_table()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'superfrete_webhook_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            webhook_id varchar(255) DEFAULT NULL,
            order_id bigint(20) DEFAULT NULL,
            superfrete_id varchar(255) DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            http_status_code smallint(6) DEFAULT NULL,
            payload longtext DEFAULT NULL,
            response_data longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            processing_time_ms int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY superfrete_id (superfrete_id),
            KEY event_type (event_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Run all migrations
     */
    public static function run_migrations()
    {
        self::create_webhook_retry_table();
        self::create_webhook_logs_table();
        
        // Initialize webhook options if they don't exist
        if (!get_option('superfrete_webhook_secret')) {
            update_option('superfrete_webhook_secret', wp_generate_password(32, false));
        }
        
        if (!get_option('superfrete_webhook_registered')) {
            update_option('superfrete_webhook_registered', 'no');
        }
        
        if (!get_option('superfrete_webhook_url')) {
            update_option('superfrete_webhook_url', '');
        }
    }
    
    /**
     * Clean up old webhook logs (older than 30 days)
     */
    public static function cleanup_old_logs()
    {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'superfrete_webhook_logs';
        $retries_table = $wpdb->prefix . 'superfrete_webhook_retries';
        
        // Delete logs older than 30 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $logs_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ));
        
        // Delete completed or failed retry records older than 7 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $retries_table WHERE status IN ('completed', 'failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ));
    }
    
    /**
     * Drop webhook tables (for uninstallation)
     */
    public static function drop_tables()
    {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'superfrete_webhook_retries',
            $wpdb->prefix . 'superfrete_webhook_logs'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // Clean up options
        $options = [
            'superfrete_webhook_secret',
            'superfrete_webhook_registered',
            'superfrete_webhook_url',
            'superfrete_webhook_table_version',
            'superfrete_webhook_migrated'
        ];
        
        foreach ($options as $option) {
            delete_option($option);
        }
    }
} 
