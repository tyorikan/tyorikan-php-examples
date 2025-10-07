#!/bin/sh

# Substitute environment variables in the nginx configuration template
envsubst '${PORT}' < /etc/nginx/sites-available/default.template > /etc/nginx/sites-available/default

# Start PHP-FPM in the background
php-fpm &

# Start Nginx in the foreground
nginx -g 'daemon off;'
