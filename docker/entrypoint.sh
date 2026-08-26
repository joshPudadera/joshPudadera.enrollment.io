#!/bin/bash
set -e

# HostForge injects PORT — Apache must bind to it.
# Default to 80 for local use.
PORT="${PORT:-80}"

echo "[entrypoint] Binding Apache to port ${PORT}"

# Replace every Listen directive (handles both "Listen 80" and "Listen 8080" etc.)
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# Replace VirtualHost port in all available/enabled configs
for conf in /etc/apache2/sites-available/*.conf /etc/apache2/sites-enabled/*.conf; do
    [ -f "$conf" ] || continue
    sed -i "s/<VirtualHost \*:[0-9]\+>/<VirtualHost *:${PORT}>/" "$conf"
done

# Validate
apache2ctl configtest 2>&1

echo "[entrypoint] Apache configured for port ${PORT}. Starting..."
exec apache2-foreground
