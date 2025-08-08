<?php

namespace SuperFrete_API\Shipping;

if (!defined('ABSPATH'))
    exit; // Segurança

require_once plugin_dir_path(__FILE__) . 'SuperFreteBase.php';

class SuperFreteLoggi extends SuperFreteBase {

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete_loggi';
        $this->method_title = __('Loggi SuperFrete');
        $this->method_description = __('Envia utilizando Loggi');
        
        parent::__construct($instance_id);
    }

    protected function get_service_id() {
        return 31; // ID do Loggi na API
    }
}