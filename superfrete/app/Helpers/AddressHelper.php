<?php

namespace SuperFrete_API\Helpers;

if (!defined('ABSPATH')) {
    exit; // Security check
}

class AddressHelper {
    
    private static $cache = [];
    private static $cache_duration = 3600; // 1 hour cache for address data
    
    /**
     * Get district (bairro) from postal code using ViaCEP API
     */
    public static function get_district_from_postal_code($postal_code) {
        // Clean postal code - remove any non-numeric characters
        $clean_postal_code = preg_replace('/[^0-9]/', '', $postal_code);
        
        if (strlen($clean_postal_code) !== 8) {
            Logger::log('SuperFrete', 'Invalid postal code format: ' . $postal_code);
            return null;
        }
        
        // Check cache first
        $cache_key = 'viacep_' . $clean_postal_code;
        if (isset(self::$cache[$cache_key]) && 
            (time() - self::$cache[$cache_key]['timestamp']) < self::$cache_duration) {
            Logger::log('SuperFrete', 'Using cached address data for CEP: ' . $clean_postal_code);
            return self::$cache[$cache_key]['data']['bairro'] ?? null;
        }
        
        // Make ViaCEP API call
        $viacep_url = "https://viacep.com.br/ws/{$clean_postal_code}/json/";
        
        Logger::log('SuperFrete', 'Calling ViaCEP API for CEP: ' . $clean_postal_code);
        
        $response = wp_remote_get($viacep_url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'WooCommerce SuperFrete Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            Logger::log('SuperFrete', 'ViaCEP API error: ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            Logger::log('SuperFrete', 'ViaCEP API returned status: ' . $status_code);
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['erro'])) {
            Logger::log('SuperFrete', 'ViaCEP API returned error or no data for CEP: ' . $clean_postal_code);
            return null;
        }
        
        // Cache the response
        self::$cache[$cache_key] = [
            'data' => $data,
            'timestamp' => time()
        ];
        
        Logger::log('SuperFrete', 'ViaCEP API success for CEP: ' . $clean_postal_code . ' - District: ' . ($data['bairro'] ?? 'N/A'));
        
        return $data['bairro'] ?? null;
    }
    
    /**
     * Get complete address data from postal code using ViaCEP API
     */
    public static function get_address_from_postal_code($postal_code) {
        // Clean postal code - remove any non-numeric characters
        $clean_postal_code = preg_replace('/[^0-9]/', '', $postal_code);
        
        if (strlen($clean_postal_code) !== 8) {
            Logger::log('SuperFrete', 'Invalid postal code format: ' . $postal_code);
            return null;
        }
        
        // Check cache first
        $cache_key = 'viacep_' . $clean_postal_code;
        if (isset(self::$cache[$cache_key]) && 
            (time() - self::$cache[$cache_key]['timestamp']) < self::$cache_duration) {
            Logger::log('SuperFrete', 'Using cached address data for CEP: ' . $clean_postal_code);
            return self::$cache[$cache_key]['data'];
        }
        
        // Make ViaCEP API call
        $viacep_url = "https://viacep.com.br/ws/{$clean_postal_code}/json/";
        
        Logger::log('SuperFrete', 'Calling ViaCEP API for CEP: ' . $clean_postal_code);
        
        $response = wp_remote_get($viacep_url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'WooCommerce SuperFrete Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            Logger::log('SuperFrete', 'ViaCEP API error: ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            Logger::log('SuperFrete', 'ViaCEP API returned status: ' . $status_code);
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['erro'])) {
            Logger::log('SuperFrete', 'ViaCEP API returned error or no data for CEP: ' . $clean_postal_code);
            return null;
        }
        
        // Cache the response
        self::$cache[$cache_key] = [
            'data' => $data,
            'timestamp' => time()
        ];
        
        Logger::log('SuperFrete', 'ViaCEP API success for CEP: ' . $clean_postal_code);
        
        return $data;
    }
    
    /**
     * Clear address cache
     */
    public static function clear_cache() {
        self::$cache = [];
        Logger::log('SuperFrete', 'Address cache cleared');
    }
} 