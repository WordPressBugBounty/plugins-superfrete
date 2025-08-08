<?php

namespace SuperFrete_API\Shipping;

if (!defined('ABSPATH'))
    exit; // Segurança

require_once plugin_dir_path(__FILE__) . 'SuperFreteBase.php';

class SuperFretePAC extends SuperFreteBase {

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete_pac';
        $this->method_title = __('PAC SuperFrete');
        $this->method_description = __('Envia utilizando PAC');
        
        parent::__construct($instance_id);
    }

    protected function get_service_id() {
        return 1; // ID do PAC na API
    }
}
