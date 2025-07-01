<?php

namespace SuperFrete_API\Admin;

use SuperFrete_API\Http\Request;
use SuperFrete_API\Helpers\Logger;
use WC_Order;

if (!defined('ABSPATH'))
    exit; // Segurança

class SuperFrete_OrderActions
{

    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'add_superfrete_metabox']);
        add_action('admin_post_superfrete_resend_order', [$this, 'resend_order_to_superfrete']);
        add_action('admin_post_superfrete_pay_ticket', [$this, 'pay_ticket_superfrete']);

        // Adiciona o AJAX para verificar o status da etiqueta
        add_action('wp_ajax_check_superfrete_status', [$this, 'check_superfrete_status']);
    }

    /**
     * Adiciona a metabox na lateral da tela de edição do pedido
     */
    public function add_superfrete_metabox()
    {
        $screen = 'shop_order';

        if (defined('WC_VERSION') && version_compare(WC_VERSION, '7.1', '>=')) {
            $hpos_enabled = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();
            $screen = $hpos_enabled ? wc_get_page_screen_id('shop-order') : 'shop_order';
        }

        add_meta_box(
            'wc-superfrete_metabox',
            'SuperFrete',
            array($this, 'display_superfrete_metabox'),
            $screen,
            'side',
            'high'
        );

    }

    /**
     * Exibe a metabox na tela de edição do pedido
     */
    public function display_superfrete_metabox($post)
    {
        $order = ($post instanceof WP_Post) ? wc_get_order($post->ID) : $post;
        $order_id = is_callable(array($order, 'get_id')) ? $order->get_id() : $order->ID;

        $order = wc_get_order($order_id);
        $methods = $order->get_shipping_methods();

        foreach ($methods as $method) {
            $method_id = $method->get_method_id(); // Forma correta de obter o method_id


            if (strpos($method_id, 'superfrete') !== false) {

                echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr(wp_create_nonce('check_superfrete_status_nonce')) . '">';
                echo '<button id="verificar_etiqueta" class="button button-primary" data-order-id="' . esc_attr($order_id) . '">' . esc_html__('Verificar Etiqueta', 'superfrete') . '</button>';
                echo '<div id="superfrete_status_container"></div>';

                // Script JS para processar o clique do botão
                ?>
                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        $('#verificar_etiqueta').on('click', function (event) {
                            event.preventDefault();
                            var order_id = $(this).data('order-id');

                            $('#superfrete_status_container').html('<p>Verificando status...</p>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'check_superfrete_status',
                                    order_id: order_id,

                                },
                                success: function (response) {
                                    $('#superfrete_status_container').html(response);
                                },
                                error: function () {
                                    $('#superfrete_status_container').html('<p style="color: red;">Erro na API, Verifique os Logs</p>');
                                }
                            });
                        });
                    });
                </script>
                <?php

            } else {
                echo '<strong>Esse pedido não foi feito utilizando SuperFrete</strong>';
            }
        }
    }

    /**
     * AJAX para buscar o status da etiqueta
     */
    public function check_superfrete_status()
    {

        if (!(!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'check_superfrete_status_nonce')) && !isset($_POST['order_id']) || !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permissão negada.', 'superfrete'));
        }

        $order_id = intval($_POST['order_id']);
        $etiqueta_id = get_post_meta($order_id, '_superfrete_id', true);

        if (!$etiqueta_id) {
            echo '<p>' . esc_html__('Status da Etiqueta: ', 'superfrete') . ' <strong>' . esc_html__('Erro ao Enviar, Verifique o Log', 'superfrete') . '</strong></p>';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=superfrete_resend_order&order_id=' . $order_id), 'superfrete_resend_order')) . '" class="button button-primary">' . esc_html__('Reenviar Pedido', 'superfrete') . '</a>';
            wp_die();
        }

        $saldo = $this->get_superfrete_balance();
        $superfrete_status = $this->get_superfrete_data($etiqueta_id)['status'];
        $superfrete_tracking = $this->get_superfrete_data($etiqueta_id)['tracking'];

        $valor_frete = floatval(get_post_meta($order_id, '_superfrete_price', true));

        echo "<p><strong>" . esc_html__('Saldo na SuperFrete:', 'superfrete') . "</strong> R$ " . esc_html(number_format($saldo, 2, ',', '.')) . "</p>";
        echo "<p><strong>" . esc_html__('Valor da Etiqueta:', 'superfrete') . "</strong> R$ " . esc_html(number_format($valor_frete, 2, ',', '.')) . "</p>";
        if ($superfrete_status == 'released') {
            echo '<p>' . esc_html__('Status da Etiqueta: ', 'superfrete') . ' <strong>' . esc_html__('Emitida', 'superfrete') . '</strong></p>';
            echo '<a href="' . esc_url($this->get_ticket_superfrete($order_id)) . '" target="_blank" class="button button-secondary">' . esc_html__('Imprimir Etiqueta', 'superfrete') . '</a>';
        } elseif ($superfrete_status == 'pending') {

            echo '<p>' . esc_html__('Status da Etiqueta: ', 'superfrete') . ' <strong>' . esc_html__('Pendente Pagamento', 'superfrete') . '</strong></p>';
            $disabled = ($saldo < $valor_frete) ? 'disabled' : '';
            echo '<a href="https://web.superfrete.com/#/account/credits" class="button button-primary">' . esc_html__('Adicionar Saldo', 'superfrete') . '</a>';
            if ($saldo < $valor_frete) {
                echo '<p style="color: red;">' . esc_html__('Saldo insuficiente para pagamento da etiqueta.', 'superfrete') . '</p>';
            }
        } else if ($superfrete_status == 'canceled') {
            echo '<p>' . esc_html__('Status da Etiqueta: ', 'superfrete') . ' <strong>' . esc_html__('Cancelada', 'superfrete') . '</strong></p>';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=superfrete_resend_order&order_id=' . $order_id), 'superfrete_resend_order')) . '" class="button button-primary">' . esc_html__('Reenviar Pedido', 'superfrete') . '</a>';


        } else if ($superfrete_status == 'posted') {
            echo '<p>' . esc_html__('Status do Pedido: ', 'superfrete') . ' <strong>' . esc_html__('Postado', 'superfrete') . '</strong></p>';
            echo '<a target="_blank" href="https://rastreio.superfrete.com/#/tracking/' . $superfrete_tracking . '"  class="button button-primary">' . esc_html__('Rastrear Pedido', 'superfrete') . '</a>';

        } else if ($superfrete_status == 'delivered') {
            echo '<p>' . esc_html__('Status do Pedido: ', 'superfrete') . ' <strong>' . esc_html__('Entregue', 'superfrete') . '</strong></p>';

        } else {

            echo '<p>' . esc_html__('Status da Etiqueta: ', 'superfrete') . ' <strong>' . esc_html__('Erro ao Enviar', 'superfrete') . '</strong></p>';
            echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=superfrete_resend_order&order_id=' . $order_id), 'superfrete_resend_order')) . '" class="button button-primary">' . esc_html__('Reenviar Pedido', 'superfrete') . '</a>';
        }

        wp_die();
    }

    /**
     * Obtém o saldo do usuário na SuperFrete
     */
    private function get_superfrete_balance()
    {
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/user', 'GET', [], true);
        return isset($response['balance']) ? floatval($response['balance']) : 0;
    }

    private function get_superfrete_data($id)
    {
        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/order/info/' . $id, 'GET', [], true);
        return $response;
    }

    /**
     * Obtém o link de impressão da etiqueta
     */
    private function get_ticket_superfrete($order_id)
    {
        $etiqueta_id = get_post_meta($order_id, '_superfrete_id', true);
        if (!$etiqueta_id) {
            return '';
        }

        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/tag/print', 'POST', ['orders' => [$etiqueta_id]], true);

        if (isset($response['url'])) {
            update_post_meta($order_id, '_superfrete_status', 'success');
            return $response['url'];
        }

        return '';
    }

    /**
     * Reenvia o pedido para a API SuperFrete
     */
    public function resend_order_to_superfrete()
    {
        if (!isset($_GET['order_id']) || !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permissão negada.', 'superfrete'));
        }

        $order_id = intval($_GET['order_id']);
        check_admin_referer('superfrete_resend_order');
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Pedido inválido.', 'superfrete'));
        }

        Logger::log('SuperFrete', "Reenviando pedido #{$order_id} para a API...");

        $controller = new \SuperFrete_API\Controllers\SuperFrete_Order();
        $response = $controller->send_order_to_superfrete($order_id);

        if (isset($response['status']) && $response['status'] === 'pending') {
            update_post_meta($order_id, '_superfrete_status', 'pending-payment');
            Logger::log('SuperFrete', "Pedido #{$order_id} enviado com sucesso.");
        } else {
            update_post_meta($order_id, '_superfrete_status', 'erro');
            Logger::log('SuperFrete', "Erro ao reenviar pedido #{$order_id}.");
        }

        wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }

    /**
     * Paga a etiqueta da SuperFrete
     */
    public function pay_ticket_superfrete()
    {
        if (!isset($_GET['order_id']) || !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permissão negada.', 'superfrete'));
        }

        $order_id = intval($_GET['order_id']);
        check_admin_referer('superfrete_pay_ticket');
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Pedido inválido.', 'superfrete'));
        }

        Logger::log('SuperFrete', "Pagando Etiqueta #{$order_id}...");

        $etiqueta_id = get_post_meta($order_id, '_superfrete_id', true);

        $request = new Request();
        $response = $request->call_superfrete_api('/api/v0/checkout', 'POST', ['orders' => [$etiqueta_id]], true);

        if ($response == 409) {
            update_post_meta($order_id, '_superfrete_status', 'success');
            wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
            return;
        }

        if (isset($response['status']) && $response['status'] === 'pending') {
            Logger::log('SuperFrete', "Etiqueta Paga #{$order_id}...");
            update_post_meta($order_id, '_superfrete_status', 'pending-payment');
            Logger::log('SuperFrete', "Pedido #{$order_id} enviado com sucesso.");
        } else {
            update_post_meta($order_id, '_superfrete_status', 'aguardando');

            Logger::log('SuperFrete', "Erro ao tentar pagar o ticket do pedido #{$order_id}.");
        }

        wp_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }
}
