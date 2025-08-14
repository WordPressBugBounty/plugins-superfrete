#!/bin/bash

echo "Deploying SuperFrete UI changes to development environment..."

# Get the current directory
CURRENT_DIR=$(pwd)

# Create directories if they don't exist
docker-compose exec wordpress mkdir -p /var/www/html/wp-content/plugins/superfrete/assets/styles
docker-compose exec wordpress mkdir -p /var/www/html/wp-content/plugins/superfrete/assets/scripts
docker-compose exec wordpress mkdir -p /var/www/html/wp-content/plugins/superfrete/templates/woocommerce
docker-compose exec wordpress mkdir -p /var/www/html/wp-content/plugins/superfrete/app/Controllers

# Copy the modified files to WordPress container
echo "Copying template files..."
docker cp templates/woocommerce/shipping-calculator.php i9-internet-superfrete-woocommerce-804b69c477ef-wordpress-1:/var/www/html/wp-content/plugins/superfrete/templates/woocommerce/

echo "Copying JavaScript files..."
docker cp assets/scripts/superfrete-calculator.js i9-internet-superfrete-woocommerce-804b69c477ef-wordpress-1:/var/www/html/wp-content/plugins/superfrete/assets/scripts/

echo "Copying CSS files..."
docker cp assets/styles/superfrete-calculator.css i9-internet-superfrete-woocommerce-804b69c477ef-wordpress-1:/var/www/html/wp-content/plugins/superfrete/assets/styles/

echo "Copying PHP controller files..."
docker cp app/Controllers/ProductShipping.php i9-internet-superfrete-woocommerce-804b69c477ef-wordpress-1:/var/www/html/wp-content/plugins/superfrete/app/Controllers/

# Set permissions
echo "Setting permissions..."
docker-compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/superfrete

# Clear WordPress cache
echo "Clearing WordPress cache..."
docker-compose exec wordpress wp cache flush --path=/var/www/html

# Clear browser cache instruction
echo "================================================"
echo "✅ Deployment complete!"
echo "Remember to clear your browser cache to see CSS changes."
echo "You can test the changes by visiting a product page at:"
echo "http://localhost:8080/?p=product_id"
echo "================================================" 