#!/bin/bash
set -e

# Default to 80 for local use.
PORT="${PORT:-80}"

echo "[entrypoint] Binding Apache to port ${PORT}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

for conf in /etc/apache2/sites-available/*.conf /etc/apache2/sites-enabled/*.conf; do
    [ -f "$conf" ] || continue
    sed -i "s/<VirtualHost \*:[0-9]\+>/<VirtualHost *:${PORT}>/" "$conf"
done

# Validate
apache2ctl configtest 2>&1

echo "[entrypoint] Apache configured for port ${PORT}. Starting..."
exec apache2-foreground
