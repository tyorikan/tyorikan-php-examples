#!/bin/sh

# Substitute environment variables in the nginx configuration template
envsubst '${PORT} ${PHP_FPM_HOST}' < /etc/nginx/conf.d/default.template > /etc/nginx/conf.d/default.conf

# Start Nginx in the foreground
nginx -g 'daemon off;'