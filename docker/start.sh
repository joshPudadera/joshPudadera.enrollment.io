#!/bin/bash
set -e

PORT="${PORT:-80}"

echo "[start] PORT=${PORT}"

# Configure Apache to listen on the port provided by HostForge
cat > /etc/apache2/ports.conf <<EOF
Listen ${PORT}
EOF

# Configure Apache VirtualHost
cat > /etc/apache2/sites-available/000-default.conf <<EOF
ServerName localhost

<VirtualHost *:${PORT}>
    DocumentRoot /var/www/html

    DirectoryIndex index.php index.html landing.php

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "[start] Apache configured for port ${PORT}"

# Verify Apache configuration
apache2ctl configtest

echo "[start] Starting Apache..."

exec apache2-foreground