# ── BCP Student Management System ────────────────────────────
# PHP 8.2 with Apache
FROM php:8.2-apache

# Install PHP extensions + tools
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

# Default port — HostForge overrides this with its own PORT env var
ENV PORT=80

# Copy Apache virtual host config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy entrypoint that patches Apache port from $PORT at runtime
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set working directory and copy app
WORKDIR /var/www/html
COPY app/ /var/www/html/
COPY app/.env.example /var/www/html/.env.example

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/requirements/uploads \
    && mkdir -p /var/www/html/enrollment_tab/uploads \
    && chmod -R 777 /var/www/html/requirements/uploads \
    && chmod -R 777 /var/www/html/enrollment_tab/uploads

# Expose default port (runtime override via $PORT)
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
