#!/bin/bash

echo "Setting up test products for SuperFrete WooCommerce testing..."

# Fix for docker-compose warnings
export SHOP_ID=""
export CART_ID=""
export CHECKOUT_ID=""
export ACCOUNT_ID=""

echo "Checking WooCommerce plugin..."

# Check if WooCommerce plugin is installed
echo "Checking if WooCommerce is installed..."
WC_INSTALLED=$(docker-compose exec -T wordpress bash -c 'test -d /var/www/html/wp-content/plugins/woocommerce && echo "yes" || echo "no"')

if [ "$WC_INSTALLED" = "yes" ]; then
    echo "WooCommerce is already installed."
else
    echo "Installing WooCommerce using wp-cli..."
    
    # Fix permissions first - avoid touching .git directories
    docker-compose exec -T wordpress bash -c 'mkdir -p /var/www/html/wp-content/upgrade && chmod 777 /var/www/html/wp-content/upgrade'
    
    # Install required packages inside the container
    echo "Installing required packages in the container..."
    docker-compose exec -T wordpress apt-get update
    docker-compose exec -T wordpress apt-get install -y unzip
    
    # Install WooCommerce with wp-cli using direct download
    echo "Downloading and installing WooCommerce..."
    docker-compose exec -T wordpress bash -c '
    cd /var/www/html
    curl -O https://downloads.wordpress.org/plugin/woocommerce.9.8.5.zip
    mkdir -p /var/www/html/wp-content/plugins/woocommerce
    unzip -o woocommerce.9.8.5.zip -d /var/www/html/wp-content/plugins/
    rm woocommerce.9.8.5.zip
    chown -R www-data:www-data /var/www/html/wp-content/plugins/woocommerce
    '
    
    # Activate WooCommerce
    echo "Activating WooCommerce..."
    docker-compose run --rm wp-cli wp plugin activate woocommerce --path=/var/www/html
    
    # Verify installation
    WC_CHECK=$(docker-compose exec -T wordpress bash -c 'test -d /var/www/html/wp-content/plugins/woocommerce && echo "yes" || echo "no"')
    
    if [ "$WC_CHECK" != "yes" ]; then
        echo "WooCommerce installation failed. Exiting."
        exit 1
    fi
    
    echo "Setting up WooCommerce pages and configuration..."
    # Create required pages and set WooCommerce options
    docker-compose run --rm wp-cli bash -c '
    cd /var/www/html
    
    # Create shop page
    SHOP_ID=$(wp post create --post_type=page --post_title="Shop" --post_status=publish --porcelain)
    wp option update woocommerce_shop_page_id "$SHOP_ID"
    
    # Create cart page
    CART_ID=$(wp post create --post_type=page --post_title="Cart" --post_status=publish --porcelain)
    wp option update woocommerce_cart_page_id "$CART_ID"
    
    # Create checkout page
    CHECKOUT_ID=$(wp post create --post_type=page --post_title="Checkout" --post_status=publish --porcelain)
    wp option update woocommerce_checkout_page_id "$CHECKOUT_ID"
    
    # Create account page
    ACCOUNT_ID=$(wp post create --post_type=page --post_title="My Account" --post_status=publish --porcelain)
    wp option update woocommerce_myaccount_page_id "$ACCOUNT_ID"
    
    # Configure WooCommerce
    wp option update woocommerce_currency BRL
    wp option update woocommerce_default_country "BR:SP"
    wp option update woocommerce_store_address "Example Street"
    wp option update woocommerce_store_city "São Paulo"
    wp option update woocommerce_store_postcode "01000-000"
    '
fi

# Wait for WooCommerce to fully initialize
echo "Waiting for WooCommerce to fully initialize..."
sleep 5

# Check if WooCommerce classes are loaded properly
echo "Verifying WooCommerce installation..."
WC_CLASS_EXISTS=$(docker-compose run --rm wp-cli wp eval 'echo class_exists("WC_Product") ? "yes" : "no";' --path=/var/www/html)

if [ "$WC_CLASS_EXISTS" != "yes" ]; then
    echo "WooCommerce classes cannot be loaded. Cannot create test products."
    exit 1
fi

# Create simple products using WooCommerce API through wp-cli
echo "Creating test products..."

# Create test product 1
echo "Creating Camiseta Básica..."
docker-compose run --rm wp-cli wp eval '
try {
    // Make sure WooCommerce is loaded
    include_once( ABSPATH . "wp-admin/includes/plugin.php" );
    if ( !is_plugin_active( "woocommerce/woocommerce.php" ) ) {
        throw new Exception("WooCommerce plugin is not active");
    }
    
    // Create/get the category "Vestuário"
    $term = term_exists("Vestuário", "product_cat");
    if (!$term) {
        $term = wp_insert_term("Vestuário", "product_cat");
    }
    $category_id = is_array($term) ? $term["term_id"] : $term;

    // Create the product
    $product = new WC_Product_Simple();
    $product->set_name("Camiseta Básica");
    $product->set_status("publish");
    $product->set_catalog_visibility("visible");
    $product->set_description("Camiseta básica de algodão de alta qualidade.");
    $product->set_short_description("Camiseta confortável para o dia a dia.");
    $product->set_regular_price("49.90");
    $product->set_weight("0.2");
    $product->set_length("30");
    $product->set_width("20");
    $product->set_height("3");
    $product->set_category_ids(array($category_id));
    $product->set_stock_status("instock");

    $product_id = $product->save();
    echo "Created product: Camiseta Básica with ID: $product_id\n";
} catch (Exception $e) {
    echo "Error creating product: " . $e->getMessage() . "\n";
}
'

# Create test product 2
echo "Creating Mesa de Escritório..."
docker-compose run --rm wp-cli wp eval '
try {
    // Make sure WooCommerce is loaded
    include_once( ABSPATH . "wp-admin/includes/plugin.php" );
    if ( !is_plugin_active( "woocommerce/woocommerce.php" ) ) {
        throw new Exception("WooCommerce plugin is not active");
    }

    // Create/get the category "Móveis"
    $term = term_exists("Móveis", "product_cat");
    if (!$term) {
        $term = wp_insert_term("Móveis", "product_cat");
    }
    $category_id = is_array($term) ? $term["term_id"] : $term;

    // Create the product
    $product = new WC_Product_Simple();
    $product->set_name("Mesa de Escritório");
    $product->set_status("publish");
    $product->set_catalog_visibility("visible");
    $product->set_description("Mesa de escritório em madeira maciça.");
    $product->set_short_description("Mesa de escritório robusta e espaçosa.");
    $product->set_regular_price("499.90");
    $product->set_weight("25");
    $product->set_length("120");
    $product->set_width("60");
    $product->set_height("75");
    $product->set_category_ids(array($category_id));
    $product->set_stock_status("instock");

    $product_id = $product->save();
    echo "Created product: Mesa de Escritório with ID: $product_id\n";
} catch (Exception $e) {
    echo "Error creating product: " . $e->getMessage() . "\n";
}
'

# Create test product 3
echo "Creating Pen Drive 32GB..."
docker-compose run --rm wp-cli wp eval '
try {
    // Make sure WooCommerce is loaded
    include_once( ABSPATH . "wp-admin/includes/plugin.php" );
    if ( !is_plugin_active( "woocommerce/woocommerce.php" ) ) {
        throw new Exception("WooCommerce plugin is not active");
    }

    // Create/get the category "Eletrônicos"
    $term = term_exists("Eletrônicos", "product_cat");
    if (!$term) {
        $term = wp_insert_term("Eletrônicos", "product_cat");
    }
    $category_id = is_array($term) ? $term["term_id"] : $term;

    // Create the product
    $product = new WC_Product_Simple();
    $product->set_name("Pen Drive 32GB");
    $product->set_status("publish");
    $product->set_catalog_visibility("visible");
    $product->set_description("Pen Drive USB 3.0 com 32GB de capacidade.");
    $product->set_short_description("Pen Drive rápido e compacto.");
    $product->set_regular_price("39.90");
    $product->set_weight("0.01");
    $product->set_length("5");
    $product->set_width("2");
    $product->set_height("1");
    $product->set_category_ids(array($category_id));
    $product->set_stock_status("instock");

    $product_id = $product->save();
    echo "Created product: Pen Drive 32GB with ID: $product_id\n";
} catch (Exception $e) {
    echo "Error creating product: " . $e->getMessage() . "\n";
}
'

echo "All test products created successfully!"
echo "You can now access the WordPress admin at http://localhost:8080"
echo "Login with admin/admin and configure the SuperFrete plugin." 