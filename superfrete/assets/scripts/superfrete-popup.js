jQuery(document).ready(function ($) {
    var popup = $("#superfrete-popup");
    var form = $("#superfrete-form");

    if (popup.length) {
        popup.fadeIn();
    }

    if (form.length) {
        form.on("submit", function (event) {
            event.preventDefault();

            // Serializa os dados do formulário (incluindo o nonce)
            var formData = form.serialize();
            formData += "&action=superfrete_update_address"; // Adiciona a ação do AJAX

            $.ajax({
                url: superfrete_ajax.ajax_url, // URL do AJAX do WordPress
                type: 'POST',
                data: formData,
                dataType: 'json',
                beforeSend: function () {
                    form.find("button").prop("disabled", true).text("Salvando...");
                },
                success: function (response) {
                    if (response.success) {
                        console.log("Endereço atualizado! Tente novamente.");
                        location.reload(); // Recarrega a página após sucesso
                    } else {
                        alert("Erro ao atualizar os dados: " + response.message);
                    }
                },
                error: function (xhr, status, error) {
                    console.log("Erro na requisição AJAX: " + xhr.responseText);
                },
                complete: function () {
                    form.find("button").prop("disabled", false).text("Corrigir Dados");
                }
            });
        });
    }
});