#!/bin/bash
set -e

PORT="${PORT:-80}"
echo "[start] PORT=${PORT}"

# Patch Apache to listen on $PORT
# Use printf to be safe with special characters
printf "Listen ${PORT}\n" > /etc/apache2/ports.conf

# Write a fresh VirtualHost config pointing at $PORT
cat > /etc/apache2/sites-available/000-default.conf << EOF
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

echo "[start] Apache config written for port ${PORT}"
apache2ctl configtest

echo "[start] Starting Apache..."
exec apache2-foreground
