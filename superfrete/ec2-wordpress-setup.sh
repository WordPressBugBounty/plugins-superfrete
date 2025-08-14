#!/bin/bash

# =========================================
# Complete WordPress Setup Script for EC2
# Includes: Apache, PHP 8.1, MySQL, WordPress, SSL, Security
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

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   print_error "This script should not be run as root for security reasons"
   print_status "Please run as ec2-user or ubuntu user with sudo privileges"
   exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    print_error "Cannot detect OS"
    exit 1
fi

print_header "WordPress Installation Script Starting"
print_status "Detected OS: $OS"

# =========================================
# 1. SYSTEM UPDATE
# =========================================
print_header "Step 1: Updating System"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt update && sudo apt upgrade -y
    APACHE_SERVICE="apache2"
    APACHE_CONFIG_DIR="/etc/apache2"
    APACHE_SITES_DIR="/etc/apache2/sites-available"
    WEB_ROOT="/var/www/html"
    WEB_USER="www-data"
elif [[ "$OS" == "amzn" ]] || [[ "$OS" == "centos" ]] || [[ "$OS" == "rhel" ]]; then
    sudo yum update -y
    APACHE_SERVICE="httpd"
    APACHE_CONFIG_DIR="/etc/httpd"
    APACHE_SITES_DIR="/etc/httpd/conf.d"
    WEB_ROOT="/var/www/html"
    WEB_USER="apache"
else
    print_error "Unsupported OS: $OS"
    exit 1
fi

# =========================================
# 2. INSTALL APACHE WEB SERVER
# =========================================
print_header "Step 2: Installing Apache Web Server"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt install -y apache2
else
    sudo yum install -y httpd
fi

# Start and enable Apache
sudo systemctl start $APACHE_SERVICE
sudo systemctl enable $APACHE_SERVICE

print_status "Apache installed and started"

# =========================================
# 3. INSTALL PHP 8.1 AND EXTENSIONS
# =========================================
print_header "Step 3: Installing PHP 8.1 and Extensions"

if [[ "$OS" == "ubuntu" ]]; then
    # Add PHP repository for Ubuntu
    sudo apt install -y software-properties-common
    sudo add-apt-repository ppa:ondrej/php -y
    sudo apt update
    
    # Install PHP and extensions
    sudo apt install -y \
        php8.1 \
        php8.1-mysql \
        php8.1-curl \
        php8.1-gd \
        php8.1-xml \
        php8.1-xmlrpc \
        php8.1-mbstring \
        php8.1-soap \
        php8.1-intl \
        php8.1-zip \
        php8.1-bcmath \
        php8.1-opcache \
        php8.1-imagick \
        libapache2-mod-php8.1
        
elif [[ "$OS" == "debian" ]]; then
    # Add Sury PHP repository for Debian
    sudo apt install -y lsb-release ca-certificates apt-transport-https software-properties-common gnupg2
    
    # Add Sury PHP repository key and source
    curl -fsSL https://packages.sury.org/php/apt.gpg | sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-keyring.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/sury-php.list
    sudo apt update
    
    # Install PHP and extensions
    sudo apt install -y \
        php8.1 \
        php8.1-mysql \
        php8.1-curl \
        php8.1-gd \
        php8.1-xml \
        php8.1-xmlrpc \
        php8.1-mbstring \
        php8.1-soap \
        php8.1-intl \
        php8.1-zip \
        php8.1-bcmath \
        php8.1-opcache \
        php8.1-imagick \
        libapache2-mod-php8.1
        
else
    # Amazon Linux / CentOS
    sudo yum install -y epel-release
    sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm || true
    sudo yum-config-manager --enable remi-php81 || sudo dnf config-manager --set-enabled remi-php81
    
    sudo yum install -y \
        php \
        php-mysql \
        php-curl \
        php-gd \
        php-xml \
        php-xmlrpc \
        php-mbstring \
        php-soap \
        php-intl \
        php-zip \
        php-bcmath \
        php-opcache \
        php-imagick
fi

# Configure PHP
print_status "Configuring PHP..."

# Find PHP ini file
PHP_INI=$(php --ini | grep "Loaded Configuration File" | sed -e 's|.*:\s*||')

# Optimize PHP settings for WordPress
sudo tee -a $PHP_INI > /dev/null <<EOF

; WordPress Optimizations
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_vars = 3000
session.gc_maxlifetime = 1440

; Security
expose_php = Off
display_errors = Off
log_errors = On

; Performance
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
EOF

print_status "PHP 8.1 installed and configured"

# =========================================
# 4. INSTALL MYSQL/MARIADB
# =========================================
print_header "Step 4: Installing MySQL/MariaDB"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt install -y mariadb-server mariadb-client
else
    sudo yum install -y mariadb-server mariadb
fi

# Start and enable MySQL
sudo systemctl start mariadb
sudo systemctl enable mariadb

# Check if MariaDB is already secured (has root password)
print_status "Checking MySQL/MariaDB status..."

if sudo mysql -e "SELECT 1;" >/dev/null 2>&1; then
    print_status "MariaDB is accessible without password - securing now..."
    
    # Secure MySQL installation
    print_status "Securing MySQL installation..."
    
    # Modern MariaDB secure installation
    sudo mysql <<EOF
-- Set root password using modern syntax
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';

-- Remove anonymous users
DELETE FROM mysql.user WHERE User='';

-- Remove remote root access
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

-- Reload privilege tables
FLUSH PRIVILEGES;
EOF
    
    print_status "MariaDB secured successfully"
else
    print_warning "MariaDB appears to be already secured (root password is set)"
    print_status "Please enter the existing MySQL root password when prompted..."
    
    # Prompt for existing root password
    echo -n "Enter existing MySQL root password: "
    read -s MYSQL_ROOT_PASSWORD
    echo ""
    
    # Test the password
    if ! mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1;" >/dev/null 2>&1; then
        print_error "Invalid MySQL root password provided"
        print_status "You can manually create the WordPress database later"
        print_status "Using a fixed password for WordPress database user only"
        MYSQL_ROOT_PASSWORD="123superfrete321"
    else
        print_status "MySQL root password verified successfully"
    fi
fi

# Create WordPress database and user
WP_DB_NAME="wordpress"
WP_DB_USER="wpuser"
WP_DB_PASSWORD="123superfrete321"

if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
    print_status "Creating WordPress database..."
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS $WP_DB_NAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$WP_DB_USER'@'localhost' IDENTIFIED BY '$WP_DB_PASSWORD';
GRANT ALL ON $WP_DB_NAME.* TO '$WP_DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    if [ $? -eq 0 ]; then
        print_status "WordPress database created successfully"
    else
        print_error "Failed to create WordPress database"
        print_status "You will need to create the database manually"
    fi
else
    print_warning "Skipping automatic database creation (no root password available)"
    print_status "Please create the WordPress database manually with these details:"
    echo "  Database Name: $WP_DB_NAME"
    echo "  Database User: $WP_DB_USER"
    echo "  Database Password: $WP_DB_PASSWORD"
    echo ""
    echo "SQL Commands to run manually:"
    echo "  CREATE DATABASE $WP_DB_NAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo "  CREATE USER '$WP_DB_USER'@'localhost' IDENTIFIED BY '$WP_DB_PASSWORD';"
    echo "  GRANT ALL ON $WP_DB_NAME.* TO '$WP_DB_USER'@'localhost';"
    echo "  FLUSH PRIVILEGES;"
fi

print_status "MySQL installed and configured"

# =========================================
# 5. INSTALL WORDPRESS
# =========================================
print_header "Step 5: Installing WordPress"

# Download WordPress
cd /tmp
wget https://wordpress.org/latest.tar.gz
tar xzf latest.tar.gz

# Copy WordPress files
sudo cp -R wordpress/* $WEB_ROOT/
sudo rm -rf wordpress latest.tar.gz

# Set proper permissions
sudo chown -R $WEB_USER:$WEB_USER $WEB_ROOT
sudo find $WEB_ROOT -type d -exec chmod 755 {} \;
sudo find $WEB_ROOT -type f -exec chmod 644 {} \;

# Create wp-config.php
print_status "Configuring WordPress..."
cd $WEB_ROOT

# Generate WordPress salts
SALTS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/)

sudo tee wp-config.php > /dev/null <<EOF
<?php
define('DB_NAME', '$WP_DB_NAME');
define('DB_USER', '$WP_DB_USER');
define('DB_PASSWORD', '$WP_DB_PASSWORD');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$SALTS

\$table_prefix = 'wp_';

define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

// Security enhancements
define('DISALLOW_FILE_EDIT', true);
define('FORCE_SSL_ADMIN', true);
define('WP_AUTO_UPDATE_CORE', true);

// Performance
define('WP_CACHE', true);
define('COMPRESS_CSS', true);
define('COMPRESS_SCRIPTS', true);
define('CONCATENATE_SCRIPTS', true);
define('ENFORCE_GZIP', true);

// SuperFrete Plugin Requirements
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
EOF

sudo chown $WEB_USER:$WEB_USER wp-config.php
sudo chmod 600 wp-config.php

print_status "WordPress installed and configured"

# =========================================
# 6. INSTALL WP-CLI
# =========================================
print_header "Step 6: Installing WP-CLI"

# Change to a writable directory
cd /tmp

# Download WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/wp-cli/v2.8.1/WP-CLI.phar

# Verify download
if [ ! -f "WP-CLI.phar" ]; then
    print_error "Failed to download WP-CLI"
    exit 1
fi

# Make executable and install
chmod +x WP-CLI.phar
sudo mv WP-CLI.phar /usr/local/bin/wp

# Verify installation
if command -v wp >/dev/null 2>&1; then
    print_status "WP-CLI installed successfully"
    wp --info --allow-root || print_warning "WP-CLI info check failed but installation completed"
else
    print_error "WP-CLI installation failed"
    exit 1
fi

print_status "WP-CLI installed"

# =========================================
# 7. CONFIGURE APACHE
# =========================================
print_header "Step 7: Configuring Apache"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    # Enable Apache modules
    sudo a2enmod rewrite
    sudo a2enmod ssl
    sudo a2enmod headers
    
    # Create virtual host
    sudo tee $APACHE_SITES_DIR/wordpress.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerAdmin admin@localhost
    DocumentRoot $WEB_ROOT
    ServerName localhost
    
    <Directory $WEB_ROOT>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/wordpress_error.log
    CustomLog \${APACHE_LOG_DIR}/wordpress_access.log combined
</VirtualHost>
EOF
    
    sudo a2ensite wordpress
    sudo a2dissite 000-default
    
else
    # CentOS/Amazon Linux Apache config
    sudo tee $APACHE_SITES_DIR/wordpress.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerAdmin admin@localhost
    DocumentRoot $WEB_ROOT
    ServerName localhost
    
    <Directory "$WEB_ROOT">
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog logs/wordpress_error_log
    CustomLog logs/wordpress_access_log combined
</VirtualHost>
EOF

    # Enable mod_rewrite
    echo "LoadModule rewrite_module modules/mod_rewrite.so" | sudo tee -a $APACHE_CONFIG_DIR/conf/httpd.conf
fi

# Create .htaccess for WordPress
sudo tee $WEB_ROOT/.htaccess > /dev/null <<EOF
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress

# Security Headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Hide sensitive files
<Files wp-config.php>
    Order allow,deny
    Deny from all
</Files>

<Files .htaccess>
    Order allow,deny
    Deny from all
</Files>
EOF

sudo chown $WEB_USER:$WEB_USER $WEB_ROOT/.htaccess

# Restart Apache
sudo systemctl restart $APACHE_SERVICE

print_status "Apache configured and restarted"

# =========================================
# 8. INSTALL COMPOSER
# =========================================
print_header "Step 8: Installing Composer"

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

print_status "Composer installed"

# =========================================
# 9. INSTALL CERTBOT FOR SSL
# =========================================
print_header "Step 9: Installing Certbot for SSL"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt install -y certbot python3-certbot-apache
else
    sudo yum install -y certbot python3-certbot-apache
fi

print_status "Certbot installed"

# =========================================
# 10. SETUP FIREWALL
# =========================================
print_header "Step 10: Configuring Firewall"

if [[ "$OS" == "ubuntu" ]]; then
    sudo ufw allow OpenSSH
    sudo ufw allow 'Apache Full'
    sudo ufw --force enable
elif [[ "$OS" == "debian" ]]; then
    # Install and configure ufw for Debian
    sudo apt install -y ufw
    sudo ufw allow ssh
    sudo ufw allow 80/tcp
    sudo ufw allow 443/tcp
    sudo ufw --force enable
else
    sudo firewall-cmd --permanent --add-service=http
    sudo firewall-cmd --permanent --add-service=https
    sudo firewall-cmd --permanent --add-service=ssh
    sudo firewall-cmd --reload || true
fi

print_status "Firewall configured"

# =========================================
# 11. ADDITIONAL SECURITY
# =========================================
print_header "Step 11: Additional Security Setup"

# Install fail2ban
if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt install -y fail2ban
else
    sudo yum install -y fail2ban
fi

# Configure fail2ban for WordPress
sudo tee /etc/fail2ban/jail.local > /dev/null <<EOF
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[sshd]
enabled = true

[apache-auth]
enabled = true

[apache-badbots]
enabled = true

[apache-noscript]
enabled = true

[apache-overflows]
enabled = true
EOF

sudo systemctl enable fail2ban
sudo systemctl start fail2ban

print_status "Security tools installed"

# =========================================
# 12. CREATE SWAP FILE (if needed)
# =========================================
print_header "Step 12: Creating Swap File"

# Check if swap exists
if ! swapon --show | grep -q .; then
    print_status "Creating 1GB swap file..."
    sudo fallocate -l 1G /swapfile
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
    sudo swapon /swapfile
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
    print_status "Swap file created"
else
    print_status "Swap already exists"
fi

# =========================================
# 13. INSTALL ADDITIONAL TOOLS
# =========================================
print_header "Step 13: Installing Additional Tools"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt install -y \
        unzip \
        curl \
        wget \
        git \
        htop \
        nano \
        vim \
        tree \
        rsync
else
    sudo yum install -y \
        unzip \
        curl \
        wget \
        git \
        htop \
        nano \
        vim \
        tree \
        rsync
fi

print_status "Additional tools installed"

# =========================================
# 14. SAVE CREDENTIALS
# =========================================
print_header "Step 14: Saving Credentials"

# Create credentials file
sudo tee /home/$(whoami)/wordpress-credentials.txt > /dev/null <<EOF
=== WordPress Installation Credentials ===
Date: $(date)

MySQL Root Password: $MYSQL_ROOT_PASSWORD
WordPress DB Name: $WP_DB_NAME
WordPress DB User: $WP_DB_USER
WordPress DB Password: $WP_DB_PASSWORD

WordPress Directory: $WEB_ROOT
WordPress URL: http://$(curl -s http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || echo "YOUR_SERVER_IP")

Next Steps:
1. Point your domain to this server's IP address
2. Run: sudo certbot --apache -d your-domain.com
3. Access WordPress setup at: http://your-domain.com
4. Upload your SuperFrete plugin via WordPress admin

Commands:
- Check Apache status: sudo systemctl status $APACHE_SERVICE
- Check MySQL status: sudo systemctl status mariadb
- View logs: sudo tail -f /var/log/apache2/error.log (Ubuntu) or /var/log/httpd/error_log (CentOS)
- Restart services: sudo systemctl restart $APACHE_SERVICE mariadb
EOF

sudo chown $(whoami):$(whoami) /home/$(whoami)/wordpress-credentials.txt
sudo chmod 600 /home/$(whoami)/wordpress-credentials.txt

# =========================================
# INSTALLATION COMPLETE
# =========================================
print_header "Installation Complete!"

echo ""
print_status "WordPress has been successfully installed!"
print_status "Credentials saved to: /home/$(whoami)/wordpress-credentials.txt"
echo ""
print_warning "IMPORTANT NEXT STEPS:"
echo "1. Point your domain to this server's IP: $(curl -s http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || echo 'Check AWS Console')"
echo "2. Setup SSL: sudo certbot --apache -d your-domain.com"
echo "3. Complete WordPress setup at: http://your-domain.com"
echo "4. Upload your SuperFrete plugin"
echo ""
print_status "Access your credentials: cat /home/$(whoami)/wordpress-credentials.txt"
echo ""

# Final system status
print_header "System Status"
echo "Apache: $(sudo systemctl is-active $APACHE_SERVICE)"
echo "MySQL: $(sudo systemctl is-active mariadb)"
echo "PHP Version: $(php -v | head -n1)"
echo "WordPress: Installed at $WEB_ROOT"
echo ""
print_status "Installation completed successfully! 🎉" 