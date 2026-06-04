#!/bin/bash

# Setup directories
BIN_DIR="/Users/rickyperera/Documents/Projects/My Projects/pos_system/bin"
mkdir -p "$BIN_DIR/php"
mkdir -p "$BIN_DIR/mariadb"

# Download and extract PHP 8.1 x64 TS
echo "Downloading PHP 8.1 for Windows..."
curl -o /tmp/php.zip -L "https://windows.php.net/downloads/releases/archives/php-8.1.25-Win32-vs16-x64.zip"
echo "Extracting PHP..."
unzip -q /tmp/php.zip -d "$BIN_DIR/php/"
rm /tmp/php.zip

# Create php.ini from development template and enable required extensions for Laravel
cp "$BIN_DIR/php/php.ini-development" "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension_dir = "ext"/extension_dir = "ext"/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=curl/extension=curl/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=fileinfo/extension=fileinfo/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=mbstring/extension=mbstring/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=openssl/extension=openssl/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=pdo_mysql/extension=pdo_mysql/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=pdo_sqlite/extension=pdo_sqlite/' "$BIN_DIR/php/php.ini"
sed -i '' 's/;extension=zip/extension=zip/' "$BIN_DIR/php/php.ini"

# Download and extract MariaDB 10.6 x64
echo "Downloading MariaDB 10.6 for Windows..."
curl -o /tmp/mariadb.zip -L "https://archive.mariadb.org/mariadb-10.6.17/winx64-packages/mariadb-10.6.17-winx64.zip"
echo "Extracting MariaDB..."
unzip -q /tmp/mariadb.zip -d /tmp/mariadb-ext
# Move contents of the inner folder to our bin/mariadb folder
mv /tmp/mariadb-ext/mariadb-10.6.17-winx64/* "$BIN_DIR/mariadb/"
rm -rf /tmp/mariadb-ext
rm /tmp/mariadb.zip

echo "Done! The portable Windows binaries have been placed in bin/php and bin/mariadb."
