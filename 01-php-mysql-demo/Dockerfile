# Use the official PHP-FPM image.
# https://hub.docker.com/_/php
FROM php:8.4-fpm

# Install system dependencies and Nginx
RUN apt-get update && apt-get install -y \
    nginx \
    gettext-base \
    # Clean up
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP dependency manager)
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install PHP extensions
RUN docker-php-ext-install -j "$(nproc)" opcache pdo_mysql

# Copy PHP configuration
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
    echo "opcache.memory_consumption = 32"; \
  } > "$PHP_INI_DIR/conf.d/cloud-run.ini"

# Copy application code
WORKDIR /var/www/html
COPY . ./ 

# Copy Nginx configuration and entrypoint script
COPY nginx.conf.template /etc/nginx/sites-available/default.template
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod +x /usr/local/bin/entrypoint.sh

# Expose port 8080
EXPOSE 8080

# Set the entrypoint
ENTRYPOINT ["entrypoint.sh"]