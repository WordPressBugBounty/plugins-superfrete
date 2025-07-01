jQuery(document).ready(function ($) {
    $('.pagar-etiqueta').click(function (e) {
        e.preventDefault();

        var order_id = $(this).data('order-id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pagar_etiqueta_superfrete',
                order_id: order_id
            },
            beforeSend: function () {
                alert('Processando pagamento...');
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });
});