<?php

namespace SuperFrete_API\Shipping;

if (!defined('ABSPATH'))
    exit; // Segurança

require_once plugin_dir_path(__FILE__) . 'SuperFreteBase.php';

class SuperFreteJadlog extends SuperFreteBase {

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete_jadlog';
        $this->method_title = __('Jadlog SuperFrete');
        $this->method_description = __('Envia utilizando Jadlog');
        
        parent::__construct($instance_id);
    }

    protected function get_service_id() {
        return 3; // ID do Jadlog na API
    }
}