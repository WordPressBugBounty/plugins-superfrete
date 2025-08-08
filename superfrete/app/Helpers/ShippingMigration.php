<?php

namespace SuperFrete_API\Helpers;

use SuperFrete_API\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class ShippingMigration {
    
    /**
     * Migrate from old individual shipping methods to new consolidated method
     */
    public static function migrate_shipping_methods() {
        if (!class_exists('WC_Shipping_Zones')) {
            return false;
        }
        
        $migrated = get_option('superfrete_shipping_migrated', false);
        if ($migrated) {
            return true; // Already migrated - don't check again to avoid loops
        }
        
        Logger::log('SuperFrete', 'Starting shipping methods migration');
        
        $zones = \WC_Shipping_Zones::get_zones();
        $migration_count = 0;
        $superfrete_zone_fixed = false;
        
        // Remove the check for shipping method registration as it causes loops
        // The migration will add methods and they'll be available on next load
        
        foreach ($zones as $zone_data) {
            $zone = new \WC_Shipping_Zone($zone_data['zone_id']);
            $methods = $zone->get_shipping_methods();
            
            $has_old_methods = false;
            $has_new_method = false;
            $old_method_settings = [];
            $old_methods_to_remove = [];
            
            // Check for consolidated method (which should be migrated to individual methods)
            foreach ($methods as $method) {
                if ($method->id === 'superfrete_shipping') {
                    $has_old_methods = true; // Treat consolidated method as "old" to be replaced
                    $old_methods_to_remove[] = $method->get_instance_id();
                    Logger::log('SuperFrete', "Found consolidated method {$method->id} in zone {$zone->get_zone_name()} - will migrate to individual methods");
                } elseif (in_array($method->id, ['superfrete_pac', 'superfrete_sedex', 'superfrete_jadlog', 'superfrete_mini_envio', 'superfrete_loggi'])) {
                    $has_new_method = true; // Individual methods are the "new" approach
                    Logger::log('SuperFrete', "Found individual method {$method->id} in zone {$zone->get_zone_name()}");
                }
            }
            
            // Special handling for "Brasil - SuperFrete" zone
            if ($zone_data['zone_name'] === 'Brasil - SuperFrete') {
                // Check if zone has proper location configuration
                $zone_locations = $zone->get_zone_locations();
                $has_brazil_location = false;
                
                foreach ($zone_locations as $location) {
                    if ($location->code === 'BR' && $location->type === 'country') {
                        $has_brazil_location = true;
                        break;
                    }
                }
                
                // Add Brazil location if missing
                if (!$has_brazil_location) {
                    $zone->add_location('BR', 'country');
                    $zone->save();
                    Logger::log('SuperFrete', 'Added Brazil (BR) location to Brasil - SuperFrete zone');
                    $superfrete_zone_fixed = true;
                }
                
                // Add all individual SuperFrete methods if missing
                $method_ids = ['superfrete_pac', 'superfrete_sedex', 'superfrete_jadlog', 'superfrete_mini_envio', 'superfrete_loggi'];
                $methods_added = false;
                
                foreach ($method_ids as $method_id) {
                    $has_method = false;
                    foreach ($methods as $method) {
                        if ($method->id === $method_id) {
                            $has_method = true;
                            break;
                        }
                    }
                    
                    if (!$has_method) {
                        $instance_id = $zone->add_shipping_method($method_id);
                        
                        // Enable the new method
                        $new_methods = $zone->get_shipping_methods();
                        foreach ($new_methods as $method) {
                            if ($method->id === $method_id && $method->get_instance_id() == $instance_id) {
                                $method->enabled = 'yes';
                                $method->update_option('enabled', 'yes');
                                $method->update_option('title', $method->method_title);
                                Logger::log('SuperFrete', "Fixed Brasil - SuperFrete zone: Added $method_id method (Instance ID: $instance_id)");
                                $methods_added = true;
                                break;
                            }
                        }
                    }
                }
                
                if ($methods_added) {
                    $superfrete_zone_fixed = true;
                    $migration_count++;
                }
                
                // Remove old methods from Brasil zone
                if ($has_old_methods) {
                    foreach ($old_methods_to_remove as $instance_id_to_remove) {
                        $zone->delete_shipping_method($instance_id_to_remove);
                        Logger::log('SuperFrete', 'Removed old shipping method from Brasil - SuperFrete zone');
                    }
                    $migration_count++;
                }
            }
            
            // Handle other zones - no changes needed for individual methods
            elseif ($has_old_methods && $zone_data['zone_name'] !== 'Brasil - SuperFrete') {
                // For other zones with old methods, just keep them as is
                // since we're using individual methods now
                Logger::log('SuperFrete', 'Zone ' . $zone->get_zone_name() . ' has old methods - keeping as individual methods');
            }
        }
        
        // Mark migration as complete
        update_option('superfrete_shipping_migrated', true);
        
        Logger::log('SuperFrete', "Shipping methods migration completed. Migrated $migration_count zones. Brasil zone fixed: " . ($superfrete_zone_fixed ? 'Yes' : 'No'));
        
        return true;
    }
    
    /**
     * Reset migration flag (for testing purposes)
     */
    public static function reset_migration() {
        delete_option('superfrete_shipping_migrated');
        Logger::log('SuperFrete', 'Migration flag reset');
    }
    
    /**
     * Force migration to run (for admin use)
     */
    public static function force_migration() {
        delete_option('superfrete_shipping_migrated');
        delete_option('superfrete_individual_methods_migrated');
        return self::migrate_shipping_methods();
    }
    
    /**
     * Force migration to individual methods (for v3.2.0+ update)
     */
    public static function force_individual_methods_migration() {
        delete_option('superfrete_shipping_migrated');
        delete_option('superfrete_individual_methods_migrated');
        
        // Also remove the consolidated method from all zones first
        if (!class_exists('WC_Shipping_Zones')) {
            return false;
        }
        
        $zones = \WC_Shipping_Zones::get_zones();
        foreach ($zones as $zone_data) {
            $zone = new \WC_Shipping_Zone($zone_data['zone_id']);
            $methods = $zone->get_shipping_methods();
            
            foreach ($methods as $method) {
                if ($method->id === 'superfrete_shipping') {
                    $zone->delete_shipping_method($method->get_instance_id());
                    Logger::log('SuperFrete', 'Removed consolidated method from zone: ' . $zone->get_zone_name());
                }
            }
        }
        
        return self::migrate_shipping_methods();
    }
    
    /**
     * Check if migration is needed
     */
    public static function needs_migration() {
        if (!class_exists('WC_Shipping_Zones')) {
            return false;
        }
        
        $migrated = get_option('superfrete_shipping_migrated', false);
        if ($migrated) {
            return false;
        }
        
        // Check if any zones have old SuperFrete methods or if Brasil zone needs fixing
        $zones = \WC_Shipping_Zones::get_zones();
        
        foreach ($zones as $zone_data) {
            $zone = new \WC_Shipping_Zone($zone_data['zone_id']);
            $methods = $zone->get_shipping_methods();
            
            // Check for consolidated method (needs migration to individual methods)
            foreach ($methods as $method) {
                if ($method->id === 'superfrete_shipping') {
                    return true; // Consolidated method needs to be migrated to individual methods
                }
            }
            
            // Check if Brasil - SuperFrete zone exists but has issues
            if ($zone_data['zone_name'] === 'Brasil - SuperFrete') {
                // Check if it has all individual SuperFrete methods
                $required_methods = ['superfrete_pac', 'superfrete_sedex', 'superfrete_jadlog', 'superfrete_mini_envio', 'superfrete_loggi'];
                $existing_methods = [];
                
                foreach ($methods as $method) {
                    if (in_array($method->id, $required_methods)) {
                        $existing_methods[] = $method->id;
                    }
                }
                
                if (count($existing_methods) < count($required_methods)) {
                    return true; // Brasil zone needs missing individual methods
                }
                
                // Check if zone has proper location configuration
                $zone_locations = $zone->get_zone_locations();
                $has_brazil_location = false;
                
                foreach ($zone_locations as $location) {
                    if ($location->code === 'BR' && $location->type === 'country') {
                        $has_brazil_location = true;
                        break;
                    }
                }
                
                if (!$has_brazil_location) {
                    return true; // Brasil zone needs location configuration
                }
            }
        }
        
        return false;
    }
    
    /**
     * Display admin notice about migration
     */
    public static function display_migration_notice() {
        if (!self::needs_migration()) {
            return;
        }
        
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>SuperFrete:</strong> 
                Detectamos métodos de frete antigos do SuperFrete. 
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=shipping&action=superfrete_migrate'); ?>" class="button button-primary">
                    Migrar Agora
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Handle migration request from admin
     */
    public static function handle_migration_request() {
        if (!isset($_GET['action']) || !in_array($_GET['action'], ['superfrete_migrate', 'superfrete_force_migrate', 'superfrete_force_individual_migrate'])) {
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        if ($_GET['action'] === 'superfrete_force_migrate') {
            $result = self::force_migration();
        } elseif ($_GET['action'] === 'superfrete_force_individual_migrate') {
            $result = self::force_individual_methods_migration();
        } else {
            $result = self::migrate_shipping_methods();
        }
        
        if ($result) {
            wp_redirect(add_query_arg([
                'page' => 'wc-settings',
                'tab' => 'shipping',
                'superfrete_migrated' => '1'
            ], admin_url('admin.php')));
            exit;
        } else {
            wp_redirect(add_query_arg([
                'page' => 'wc-settings',
                'tab' => 'shipping',
                'superfrete_migration_error' => '1'
            ], admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * Display migration success/error messages
     */
    public static function display_migration_messages() {
        if (isset($_GET['superfrete_migrated'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>SuperFrete:</strong> Métodos de frete migrados com sucesso!</p>
            </div>
            <?php
        }
        
        if (isset($_GET['superfrete_migration_error'])) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>SuperFrete:</strong> Erro durante a migração. Verifique os logs para mais detalhes.</p>
            </div>
            <?php
        }
    }
}

// Hook into admin to handle migration
add_action('admin_init', ['SuperFrete_API\Helpers\ShippingMigration', 'handle_migration_request']);
add_action('admin_notices', ['SuperFrete_API\Helpers\ShippingMigration', 'display_migration_notice']);
add_action('admin_notices', ['SuperFrete_API\Helpers\ShippingMigration', 'display_migration_messages']);

// Auto-migrate on plugin activation/update
add_action('init', function() {
    if (\SuperFrete_API\Helpers\ShippingMigration::needs_migration()) {
        \SuperFrete_API\Helpers\ShippingMigration::migrate_shipping_methods();
    }
}); 