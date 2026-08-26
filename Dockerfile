FROM php:8.2-apache

# Install required packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    curl \
    msmtp \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Apache configuration
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Application directory
WORKDIR /var/www/html

# Copy application
COPY app/ /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/requirements/uploads \
    && mkdir -p /var/www/html/enrollment_tab/uploads \
    && chmod -R 777 /var/www/html/requirements/uploads \
    && chmod -R 777 /var/www/html/enrollment_tab/uploads

# Apache's default port.
# HostForge will provide the actual runtime PORT.
EXPOSE 80

# Startup script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]