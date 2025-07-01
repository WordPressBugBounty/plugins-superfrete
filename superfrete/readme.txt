=== SuperFrete ===
Contributors: Zafarie, SuperFrete
Tags: WooCommerce, Shipping, Frete, Logística
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


Integração com a plataforma SuperFrete para WooCommerce.

== Descrição ==
SuperFrete é um plugin para WooCommerce que otimiza o cálculo de frete, oferecendo múltiplas opções de envio, integração com transportadoras e funcionalidades avançadas para gestão de frete na loja virtual.

Principais funcionalidades:
- Cálculo de frete em tempo real
- Suporte a PAC, SEDEX e MiniEnvio
- Integração com APIs de transportadoras
- Interface amigável para configuração no painel administrativo
- Exibição personalizada do cálculo de frete na página do produto e checkout
- Logs e registros detalhados para auditoria
- Opção de frete gratuito baseado em regras personalizadas
- Suporte para múltiplos perfis de envio por categoria de produto
- Controle avançado de restrições geográficas e de peso
- Notificação de status do frete para o cliente

== Instalação ==
1. Faça o upload da pasta `superfrete` para o diretório `/wp-content/plugins/`.
2. Ative o plugin através do menu "Plugins" no WordPress.
3. Acesse "Configurações -> SuperFrete" para configurar os métodos de envio.
4. Configure suas credenciais e opções de transporte conforme necessário.
5. Defina as regras de cálculo de frete no painel de administração.

== Uso ==
- O plugin adiciona um cálculo de frete diretamente nas páginas de produtos e checkout do WooCommerce.
- O administrador pode gerenciar as opções de envio no painel de administração do WordPress.
- Logs podem ser acessados para verificação de erros e análise de pedidos.
- Os clientes podem visualizar estimativas de entrega em tempo real.

== Hooks e Filtros ==
**Ações:**
- `superfrete_before_calculation` - Executado antes do cálculo de frete.
- `superfrete_after_calculation` - Executado após o cálculo de frete.
- `superfrete_order_completed` - Acionado quando um pedido é finalizado.

**Filtros:**
- `superfrete_shipping_options` - Modifica as opções de frete disponíveis.
- `superfrete_custom_price` - Permite alterar os valores de frete dinamicamente.
- `superfrete_delivery_time` - Personaliza o tempo estimado de entrega.
- `superfrete_shipping_zones` - Permite modificar zonas de frete.

== Arquivos Principais ==
- `superfrete.php` - Arquivo principal do plugin.
- `app/App.php` - Core do plugin.
- `app/Controllers/Admin/Admin_Menu.php` - Criação do menu administrativo.
- `app/Controllers/ProductShipping.php` - Controle de métodos de envio.
- `app/Shipping/SuperFreteShipping.php` - Classe principal de cálculo de frete.
- `app/Shipping/SuperFreteSEDEX.php` - Implementação do método SEDEX.
- `app/Shipping/SuperFreteMiniEnvio.php` - Implementação do método MiniEnvio.
- `app/Shipping/SuperFretePAC.php` - Implementação do método PAC.
- `api/Http/Request.php` - Gerenciamento de requisições de API.
- `api/Helpers/Logger.php` - Registro de logs de eventos do plugin.
- `templates/woocommerce/shipping-calculator.php` - Template do calculador de frete.
- `assets/scripts/superfrete-calculator.js` - Script de cálculo de frete no frontend.
- `assets/scripts/admin.js` - Script para configuração administrativa.

== Suporte ==
Caso tenha dúvidas ou precise de suporte, entre em contato através do e-mail [seu-email] ou acesse o repositório do plugin no GitHub.

== Changelog ==
= 2.0 =
* Melhorias na interface administrativa para configuração do frete.
* Implementação de suporte a múltiplas transportadoras.
* Novo sistema de logs aprimorado para auditoria.
* Suporte a regras de frete gratuito baseado em categorias de produtos.
* Opção de cálculo de frete diferenciado por CEP e peso.

= 1.0.0 =
* Versão inicial do plugin com suporte a PAC, SEDEX e MiniEnvio.
* Adicionado painel de administração para configuração de fretes.
* Implementado cálculo de frete dinâmico na página do produto e checkout.