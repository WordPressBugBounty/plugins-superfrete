#!/bin/bash

# =========================================
# Fix Broken MariaDB/MySQL Package State
# =========================================

set +e  # Don't exit on errors - we expect some

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE} $1${NC}"
    echo -e "${BLUE}================================${NC}"
}

print_header "Fixing Broken MariaDB/MySQL Package State"

# Step 1: Stop all MySQL/MariaDB processes
print_status "Stopping all MySQL/MariaDB processes..."
sudo systemctl stop mariadb 2>/dev/null || true
sudo systemctl stop mysql 2>/dev/null || true
sudo pkill -f mysql 2>/dev/null || true
sudo pkill -f mariadb 2>/dev/null || true
sleep 2

# Step 2: Fix broken package configuration
print_status "Attempting to fix broken package configuration..."
sudo dpkg --configure -a 2>/dev/null || true

# Step 3: Force remove problematic packages
print_status "Force removing broken packages..."
for pkg in mysql-common mariadb-common galera-4 libmariadb3 mariadb-client-core mariadb-client mariadb-server-core mariadb-server libdbi-perl libconfig-inifiles-perl; do
    sudo dpkg --remove --force-remove-reinstreq $pkg 2>/dev/null || true
    sudo dpkg --purge --force-remove-reinstreq $pkg 2>/dev/null || true
done

# Step 4: Complete package purge
print_status "Purging all MySQL/MariaDB packages..."
sudo apt purge -y mysql-* mariadb-* galera-* libmariadb* 2>/dev/null || true
sudo apt autoremove -y 2>/dev/null || true

# Step 5: Remove configuration files and directories
print_status "Removing configuration files and data directories..."
sudo rm -rf /etc/mysql/ 2>/dev/null || true
sudo rm -rf /var/lib/mysql/ 2>/dev/null || true
sudo rm -rf /var/log/mysql/ 2>/dev/null || true
sudo rm -rf /etc/my.cnf 2>/dev/null || true
sudo rm -rf /etc/my.cnf.d/ 2>/dev/null || true
sudo rm -rf /var/cache/mysql/ 2>/dev/null || true
sudo rm -rf /var/run/mysqld/ 2>/dev/null || true

# Step 6: Remove users and groups
print_status "Removing MySQL users and groups..."
sudo userdel mysql 2>/dev/null || true
sudo groupdel mysql 2>/dev/null || true

# Step 7: Clean package system
print_status "Cleaning package system..."
sudo apt clean
sudo apt autoclean
sudo apt autoremove -y 2>/dev/null || true

# Step 8: Fix broken dependencies
print_status "Fixing broken dependencies..."
sudo apt update
sudo apt install -f -y 2>/dev/null || true

# Step 9: Verify clean state
print_status "Verifying clean state..."
if dpkg -l | grep -i mysql | grep -v grep; then
    print_warning "Some MySQL packages still found:"
    dpkg -l | grep -i mysql | grep -v grep
else
    print_status "No MySQL packages found - clean!"
fi

if dpkg -l | grep -i mariadb | grep -v grep; then
    print_warning "Some MariaDB packages still found:"
    dpkg -l | grep -i mariadb | grep -v grep
else
    print_status "No MariaDB packages found - clean!"
fi

# Step 10: Final system check
print_status "Running final system check..."
sudo apt update
sudo apt upgrade -y 2>/dev/null || true

print_header "Fix Complete!"
print_status "The system should now be ready for a fresh MariaDB installation"
print_status "You can now run: bash ec2-wordpress-setup.sh" 