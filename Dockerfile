# ── BCP Student Management System ────────────────────────────
# PHP 8.2 with Apache
FROM php:8.2-apache

# Install PHP extensions + curl (needed for HEALTHCHECK)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    curl \
    msmtp \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Copy Apache virtual host config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy custom entrypoint that rewrites Apache port from $PORT env var
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY app/ /var/www/html/

# Copy .env example
COPY app/.env.example /var/www/html/.env.example

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/requirements/uploads \
    && mkdir -p /var/www/html/enrollment_tab/uploads \
    && chmod -R 777 /var/www/html/requirements/uploads \
    && chmod -R 777 /var/www/html/enrollment_tab/uploads

EXPOSE 80

# Health check using the PORT env var (defaults to 80)
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f "http://localhost:${PORT:-80}/landing.php" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
