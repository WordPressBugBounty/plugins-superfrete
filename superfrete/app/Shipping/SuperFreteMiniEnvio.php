<?php

namespace SuperFrete_API\Shipping;

if (!defined('ABSPATH'))
    exit; // Segurança

require_once plugin_dir_path(__FILE__) . 'SuperFreteBase.php';

class SuperFreteMiniEnvios extends SuperFreteBase {

    public function __construct($instance_id = 0) {
        $this->id = 'superfrete_mini_envio';
        $this->method_title = __('Mini Envios SuperFrete');
        $this->method_description = __('Envia utilizando Mini Envios');
        
        parent::__construct($instance_id);
    }

    protected function get_service_id() {
        return 17; // ID do Mini Envios na API
    }
}