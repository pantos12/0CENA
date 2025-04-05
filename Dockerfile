FROM php:8.1-apache

# Install required PHP extensions and dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    zlib1g-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo_sqlite zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application files
COPY . /var/www/html/

# Create required directories
RUN mkdir -p /var/www/html/uploads \
    /var/www/html/database \
    /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html

# Set permissions
RUN chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/uploads \
    && chmod -R 777 /var/www/html/database \
    && chmod -R 777 /var/www/html/logs

# Remove Apache default index
RUN rm -f /var/www/html/index.html

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Set environment variables
ENV APP_NAME="0CENA" \
    APP_DEBUG=false

# Configure Apache document root
RUN sed -i 's/DocumentRoot \/var\/www\/html/DocumentRoot \/var\/www\/html/g' /etc/apache2/sites-available/000-default.conf

# Expose port 80
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"] 