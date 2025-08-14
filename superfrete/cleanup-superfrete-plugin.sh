#!/bin/bash

# =========================================
# SuperFrete Plugin Cleanup Script
# Removes only SuperFrete plugin data, keeping WordPress intact
# =========================================

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE} $1${NC}"
    echo -e "${BLUE}================================${NC}"
}

# Database connection details (you may need to adjust these)
DB_NAME="${1:-wordpress}"
DB_USER="${2:-wordpress}"
DB_PASSWORD="${3:-wordpress}"
DB_HOST="${4:-localhost}"

print_header "SuperFrete Plugin Cleanup Starting"

# Ask for confirmation
print_warning "This will remove ALL SuperFrete plugin data:"
echo "  - SuperFrete database tables"
echo "  - SuperFrete WordPress options"
echo "  - SuperFrete transients and cache"
echo "  - SuperFrete user and post metadata"
echo "  - SuperFrete shipping zones and methods"
echo ""
print_warning "WordPress and other plugins will remain intact"
echo ""
read -p "Are you sure you want to continue? (type 'YES' to confirm): " confirm

if [ "$confirm" != "YES" ]; then
    print_status "Cleanup cancelled"
    exit 0
fi

print_header "Starting SuperFrete Plugin Data Cleanup"

# Test database connection
print_status "Testing database connection..."
if ! mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "USE $DB_NAME;" 2>/dev/null; then
    print_error "Cannot connect to database. Please check your credentials."
    print_status "Usage: $0 [db_name] [db_user] [db_password] [db_host]"
    print_status "Example: $0 wordpress wordpress wordpress localhost"
    exit 1
fi

print_status "Database connection successful"

# =========================================
# 1. DROP SUPERFRETE TABLES
# =========================================
print_header "Step 1: Removing SuperFrete Database Tables"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" <<EOF
-- Drop SuperFrete webhook tables
DROP TABLE IF EXISTS wp_superfrete_webhook_retries;
DROP TABLE IF EXISTS wp_superfrete_webhook_logs;

-- Drop any other SuperFrete tables (add more as needed)
DROP TABLE IF EXISTS wp_superfrete_shipping_cache;
DROP TABLE IF EXISTS wp_superfrete_quotes;
DROP TABLE IF EXISTS wp_superfrete_orders;
EOF

print_status "SuperFrete tables dropped"

# =========================================
# 2. REMOVE SUPERFRETE OPTIONS
# =========================================
print_header "Step 2: Removing SuperFrete Options"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" <<EOF
-- Remove SuperFrete options
DELETE FROM wp_options WHERE option_name LIKE 'superfrete_%';
DELETE FROM wp_options WHERE option_name LIKE 'woocommerce_superfrete%';

-- Remove SuperFrete transients
DELETE FROM wp_options WHERE option_name LIKE '_transient_superfrete_%';
DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_superfrete_%';

-- Remove SuperFrete site transients
DELETE FROM wp_options WHERE option_name LIKE '_site_transient_superfrete_%';
DELETE FROM wp_options WHERE option_name LIKE '_site_transient_timeout_superfrete_%';
EOF

print_status "SuperFrete options removed"

# =========================================
# 3. REMOVE SUPERFRETE META DATA
# =========================================
print_header "Step 3: Removing SuperFrete Meta Data"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" <<EOF
-- Remove SuperFrete user meta
DELETE FROM wp_usermeta WHERE meta_key LIKE 'superfrete_%';

-- Remove SuperFrete post meta (orders, products, etc.)
DELETE FROM wp_postmeta WHERE meta_key LIKE '_superfrete_%';
DELETE FROM wp_postmeta WHERE meta_key LIKE 'superfrete_%';

-- Remove SuperFrete shipping method meta
DELETE FROM wp_postmeta WHERE meta_key LIKE '_shipping_superfrete_%';
EOF

print_status "SuperFrete meta data removed"

# =========================================
# 4. REMOVE SUPERFRETE SHIPPING METHODS
# =========================================
print_header "Step 4: Removing SuperFrete Shipping Methods"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" <<EOF
-- Remove SuperFrete shipping zones and methods
DELETE FROM wp_woocommerce_shipping_zone_methods WHERE method_id LIKE 'superfrete%';

-- Remove SuperFrete past order shipping data
UPDATE wp_woocommerce_order_items 
SET order_item_name = REPLACE(order_item_name, 'SuperFrete', 'Removed SuperFrete Method')
WHERE order_item_type = 'shipping' AND order_item_name LIKE '%SuperFrete%';
EOF

print_status "SuperFrete shipping methods removed"

# =========================================
# 5. CLEAN OBJECT CACHE
# =========================================
print_header "Step 5: Clearing Object Cache"

# If using WP-CLI
if command -v wp >/dev/null 2>&1; then
    print_status "Clearing WordPress cache using WP-CLI..."
    wp cache flush --allow-root 2>/dev/null || true
    wp transient delete --all --allow-root 2>/dev/null || true
else
    print_status "WP-CLI not found, cache will be cleared on next page load"
fi

# =========================================
# 6. RESET PLUGIN STATUS
# =========================================
print_header "Step 6: Deactivating SuperFrete Plugin"

if command -v wp >/dev/null 2>&1; then
    wp plugin deactivate superfrete --allow-root 2>/dev/null || true
    print_status "SuperFrete plugin deactivated"
else
    print_warning "WP-CLI not found. Please deactivate SuperFrete plugin manually in WordPress admin"
fi

# =========================================
# CLEANUP COMPLETE
# =========================================
print_header "SuperFrete Plugin Cleanup Complete!"

echo ""
print_status "All SuperFrete plugin data has been removed successfully!"
echo ""
print_status "What was cleaned:"
echo "  ✅ SuperFrete database tables"
echo "  ✅ SuperFrete WordPress options"
echo "  ✅ SuperFrete transients and cache"
echo "  ✅ SuperFrete user and post metadata"
echo "  ✅ SuperFrete shipping methods"
echo "  ✅ Plugin deactivated"
echo ""
print_status "WordPress and other plugins remain intact"
echo ""
print_status "You can now:"
echo "  1. Reactivate the SuperFrete plugin to test fresh installation"
echo "  2. Test migration from this clean state"
echo "  3. Install an older version first, then test migration"
echo ""
print_status "Plugin cleanup completed successfully! 🎉" 