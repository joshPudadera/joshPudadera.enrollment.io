#!/bin/bash
set -e

# HostForge (and similar PaaS platforms) inject a PORT env var.
# Apache must listen on exactly that port for the health check to pass.
# Default to 80 for local Docker usage.
PORT="${PORT:-80}"

echo "[entrypoint] Configuring Apache to listen on port ${PORT}"

# Rewrite the Listen directive in ports.conf
sed -i "s/Listen [0-9]*/Listen ${PORT}/" /etc/apache2/ports.conf

# Rewrite the VirtualHost binding
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# Verify Apache config is valid before starting
apache2ctl configtest 2>&1 || true

echo "[entrypoint] Starting Apache on port ${PORT}..."
exec apache2-foreground
