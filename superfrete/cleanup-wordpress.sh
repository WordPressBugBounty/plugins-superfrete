#!/bin/bash

# =========================================
# WordPress Complete Cleanup Script
# Removes: WordPress, Apache, PHP, MySQL/MariaDB, Security tools
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

print_header "WordPress Complete Cleanup Starting"
print_status "Detected OS: $OS"

# Ask for confirmation
print_warning "This will completely remove:"
echo "  - WordPress and all files"
echo "  - Apache web server"
echo "  - PHP and all extensions"
echo "  - MariaDB/MySQL (including all databases!)"
echo "  - All configuration files"
echo "  - Security tools (fail2ban, ufw)"
echo "  - Composer, WP-CLI"
echo "  - All related packages"
echo ""
print_warning "THIS CANNOT BE UNDONE!"
echo ""
read -p "Are you sure you want to continue? (type 'YES' to confirm): " confirm

if [ "$confirm" != "YES" ]; then
    print_status "Cleanup cancelled"
    exit 0
fi

print_header "Starting Cleanup Process"

# =========================================
# 1. STOP ALL SERVICES
# =========================================
print_header "Step 1: Stopping Services"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo systemctl stop apache2 2>/dev/null || true
    sudo systemctl stop mariadb 2>/dev/null || true
    sudo systemctl stop mysql 2>/dev/null || true
    sudo systemctl stop fail2ban 2>/dev/null || true
    
    sudo systemctl disable apache2 2>/dev/null || true
    sudo systemctl disable mariadb 2>/dev/null || true
    sudo systemctl disable mysql 2>/dev/null || true
    sudo systemctl disable fail2ban 2>/dev/null || true
else
    sudo systemctl stop httpd 2>/dev/null || true
    sudo systemctl stop mariadb 2>/dev/null || true
    sudo systemctl stop fail2ban 2>/dev/null || true
    
    sudo systemctl disable httpd 2>/dev/null || true
    sudo systemctl disable mariadb 2>/dev/null || true
    sudo systemctl disable fail2ban 2>/dev/null || true
fi

print_status "Services stopped"

# =========================================
# 2. BACKUP IMPORTANT DATA (OPTIONAL)
# =========================================
print_header "Step 2: Creating Backup (Optional)"

if [ -d "/var/www/html" ] && [ "$(ls -A /var/www/html)" ]; then
    read -p "Do you want to backup WordPress files before deletion? (y/n): " backup_wp
    if [[ $backup_wp =~ ^[Yy]$ ]]; then
        BACKUP_DIR="/home/$(whoami)/wordpress-backup-$(date +%Y%m%d-%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        sudo cp -r /var/www/html/* "$BACKUP_DIR/" 2>/dev/null || true
        sudo chown -R $(whoami):$(whoami) "$BACKUP_DIR"
        print_status "WordPress files backed up to: $BACKUP_DIR"
    fi
fi

# =========================================
# 3. REMOVE APACHE/HTTPD
# =========================================
print_header "Step 3: Removing Apache Web Server"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt purge -y apache2 apache2-utils apache2-bin apache2-data libapache2-mod-php* 2>/dev/null || true
    sudo apt autoremove -y 2>/dev/null || true
    
    # Remove Apache directories
    sudo rm -rf /etc/apache2/ 2>/dev/null || true
    sudo rm -rf /var/log/apache2/ 2>/dev/null || true
    sudo rm -rf /var/lib/apache2/ 2>/dev/null || true
else
    sudo yum remove -y httpd httpd-tools 2>/dev/null || true
    sudo rm -rf /etc/httpd/ 2>/dev/null || true
    sudo rm -rf /var/log/httpd/ 2>/dev/null || true
fi

print_status "Apache removed"

# =========================================
# 4. REMOVE PHP
# =========================================
print_header "Step 4: Removing PHP"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    # Remove all PHP packages
    sudo apt purge -y php* libapache2-mod-php* 2>/dev/null || true
    sudo apt autoremove -y 2>/dev/null || true
    
    # Remove PHP repository (Ubuntu)
    if [[ "$OS" == "ubuntu" ]]; then
        sudo add-apt-repository --remove ppa:ondrej/php -y 2>/dev/null || true
    fi
    
    # Remove Sury repository (Debian)
    if [[ "$OS" == "debian" ]]; then
        sudo rm -f /etc/apt/sources.list.d/sury-php.list 2>/dev/null || true
        sudo rm -f /etc/apt/trusted.gpg.d/sury-keyring.gpg 2>/dev/null || true
    fi
    
    sudo apt update 2>/dev/null || true
    
    # Remove PHP directories
    sudo rm -rf /etc/php/ 2>/dev/null || true
    sudo rm -rf /var/lib/php/ 2>/dev/null || true
else
    sudo yum remove -y php* 2>/dev/null || true
    sudo rm -rf /etc/php* 2>/dev/null || true
fi

print_status "PHP removed"

# =========================================
# 5. REMOVE MARIADB/MYSQL
# =========================================
print_header "Step 5: Removing MariaDB/MySQL"

print_warning "This will delete ALL databases including any existing data!"
read -p "Continue with database removal? (y/n): " confirm_db
if [[ $confirm_db =~ ^[Yy]$ ]]; then
    
    if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
        sudo apt purge -y mariadb-server mariadb-client mysql-server mysql-client 2>/dev/null || true
        sudo apt autoremove -y 2>/dev/null || true
    else
        sudo yum remove -y mariadb-server mariadb mysql-server mysql 2>/dev/null || true
    fi
    
    # Remove MySQL/MariaDB directories and data
    sudo rm -rf /var/lib/mysql/ 2>/dev/null || true
    sudo rm -rf /etc/mysql/ 2>/dev/null || true
    sudo rm -rf /var/log/mysql/ 2>/dev/null || true
    sudo rm -rf /etc/my.cnf 2>/dev/null || true
    sudo rm -rf /etc/my.cnf.d/ 2>/dev/null || true
    
    print_status "MariaDB/MySQL removed"
else
    print_status "Skipping database removal"
fi

# =========================================
# 6. REMOVE WORDPRESS FILES
# =========================================
print_header "Step 6: Removing WordPress Files"

# Remove WordPress directory
sudo rm -rf /var/www/html/* 2>/dev/null || true
sudo rm -rf /var/www/html/.* 2>/dev/null || true

# Create default index page
sudo tee /var/www/html/index.html > /dev/null <<EOF
<!DOCTYPE html>
<html>
<head>
    <title>Default Page</title>
</head>
<body>
    <h1>Server Clean - Ready for Installation</h1>
    <p>WordPress and related components have been removed.</p>
</body>
</html>
EOF

print_status "WordPress files removed"

# =========================================
# 7. REMOVE SECURITY TOOLS
# =========================================
print_header "Step 7: Removing Security Tools"

# Remove fail2ban
if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt purge -y fail2ban 2>/dev/null || true
    sudo apt autoremove -y 2>/dev/null || true
else
    sudo yum remove -y fail2ban 2>/dev/null || true
fi

sudo rm -rf /etc/fail2ban/ 2>/dev/null || true

# Reset UFW firewall
if command -v ufw >/dev/null 2>&1; then
    sudo ufw --force reset 2>/dev/null || true
    sudo ufw disable 2>/dev/null || true
    
    if [[ "$OS" == "debian" ]]; then
        sudo apt purge -y ufw 2>/dev/null || true
        sudo apt autoremove -y 2>/dev/null || true
    fi
fi

print_status "Security tools removed"

# =========================================
# 8. REMOVE ADDITIONAL TOOLS
# =========================================
print_header "Step 8: Removing Additional Tools"

# Remove Composer
sudo rm -f /usr/local/bin/composer 2>/dev/null || true

# Remove WP-CLI
sudo rm -f /usr/local/bin/wp 2>/dev/null || true

# Remove Certbot
if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt purge -y certbot python3-certbot-apache 2>/dev/null || true
    sudo apt autoremove -y 2>/dev/null || true
else
    sudo yum remove -y certbot python3-certbot-apache 2>/dev/null || true
fi

sudo rm -rf /etc/letsencrypt/ 2>/dev/null || true

print_status "Additional tools removed"

# =========================================
# 9. CLEAN UP CONFIGURATION FILES
# =========================================
print_header "Step 9: Cleaning Configuration Files"

# Remove credential files
sudo rm -f /home/$(whoami)/wordpress-credentials.txt 2>/dev/null || true

# Remove swap file (if created by our script)
if [ -f "/swapfile" ]; then
    sudo swapoff /swapfile 2>/dev/null || true
    sudo rm -f /swapfile 2>/dev/null || true
    sudo sed -i '/\/swapfile/d' /etc/fstab 2>/dev/null || true
fi

# Clean package manager cache
if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt autoclean 2>/dev/null || true
    sudo apt autoremove -y 2>/dev/null || true
else
    sudo yum clean all 2>/dev/null || true
fi

print_status "Configuration cleaned"

# =========================================
# 10. FINAL SYSTEM UPDATE
# =========================================
print_header "Step 10: Final System Update"

if [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]]; then
    sudo apt update 2>/dev/null || true
    sudo apt upgrade -y 2>/dev/null || true
else
    sudo yum update -y 2>/dev/null || true
fi

print_status "System updated"

# =========================================
# CLEANUP COMPLETE
# =========================================
print_header "Cleanup Complete!"

echo ""
print_status "All WordPress components have been removed successfully!"
echo ""
print_status "System is now clean and ready for a fresh installation"
echo ""
print_warning "What was removed:"
echo "  ✅ WordPress files and configuration"
echo "  ✅ Apache web server"
echo "  ✅ PHP and all extensions"
if [[ $confirm_db =~ ^[Yy]$ ]]; then
    echo "  ✅ MariaDB/MySQL and all databases"
else
    echo "  ⏸️  MariaDB/MySQL (skipped by user)"
fi
echo "  ✅ Security tools (fail2ban, ufw reset)"
echo "  ✅ Composer and WP-CLI"
echo "  ✅ SSL certificates"
echo "  ✅ Configuration files"
echo ""

if [ -n "$BACKUP_DIR" ]; then
    print_status "WordPress backup available at: $BACKUP_DIR"
    echo ""
fi

print_status "You can now run the installation script again with a clean system!"
print_status "Command: bash ec2-wordpress-setup.sh"
echo ""
print_status "Cleanup completed successfully! 🎉" 