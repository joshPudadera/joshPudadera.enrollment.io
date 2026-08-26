# ── BCP Student Management System ────────────────────────────
# PHP 8.2 with Apache
FROM php:8.2-apache

# Install PHP extensions required by the project
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Enable PHP mail (uses msmtp as sendmail replacement)
RUN apt-get update && apt-get install -y msmtp && rm -rf /var/lib/apt/lists/*

# Copy Apache virtual host config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY app/ /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/requirements/uploads \
    && mkdir -p /var/www/html/enrollment_tab/uploads \
    && chmod -R 777 /var/www/html/requirements/uploads \
    && chmod -R 777 /var/www/html/enrollment_tab/uploads

# Copy .env example if .env doesn't exist
COPY app/.env.example /var/www/html/.env.example

EXPOSE 80

# Tell orchestrators (and HostForge) how to verify the app is alive
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost/landing.php || exit 1

CMD ["apache2-foreground"]
