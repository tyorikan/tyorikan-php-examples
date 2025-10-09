# Use the official PHP-FPM image.
# https://hub.docker.com/_/php
FROM php:8.4-fpm

# Install system dependencies, Nginx and required PHP extensions
RUN apt-get update && apt-get install -y \
    nginx \
    gettext-base \
    zip \
    unzip \
    && docker-php-ext-install -j "$(nproc)" opcache pdo_mysql bcmath \
    # Clean up
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP dependency manager)
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy PHP configuration for Cloud Run
RUN set -ex; \
  { \
    echo "; Cloud Run enforces memory & timeouts"; \
    echo "memory_limit = -1"; \
    echo "max_execution_time = 0"; \
    echo "; File upload at Cloud Run network limit"; \
    echo "upload_max_filesize = 32M"; \
    echo "post_max_size = 32M"; \
    echo "; Configure Opcache for Containers"; \
    echo "opcache.enable = On"; \
    echo "opcache.validate_timestamps = Off"; \
    echo "; Configure Opcache Memory (Application-specific)"; \
    echo "opcache.memory_consumption = 128"; \
  } > "$PHP_INI_DIR/conf.d/cloud-run.ini"

# Set working directory
WORKDIR /var/www/html

# Copy composer files and install dependencies to leverage Docker cache
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-plugins

# Copy application code
COPY . .

# Generate optimized autoloader and cache configurations
RUN composer dump-autoload --optimize

# Copy Nginx configuration and entrypoint script
COPY nginx.conf.template /etc/nginx/sites-available/default.template
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# Set permissions for Laravel storage and bootstrap/cache directories
RUN chown -R www-data:www-data /var/www/html && \
    chmod +x /usr/local/bin/entrypoint.sh && \
    chmod -R 775 storage bootstrap/cache

# Expose port 8080
EXPOSE 8080

# Set the entrypoint
ENTRYPOINT ["entrypoint.sh"]
