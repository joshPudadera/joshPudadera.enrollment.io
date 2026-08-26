#!/bin/bash
# ── HostForge entrypoint ──────────────────────────────────────
# HostForge injects a PORT env var. Apache must listen on that port.
# Default to 80 if PORT is not set (local Docker / other hosts).

PORT="${PORT:-80}"

# Update Apache to listen on the injected PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "Starting Apache on port ${PORT}..."
exec apache2-foreground
