#!/bin/sh

# Set a default for the PORT environment variable if it's not already set.
export PORT=${PORT:-8080}

# Substitute environment variables in the nginx configuration template
envsubst '${PORT}' < /etc/nginx/sites-available/default.template > /etc/nginx/sites-available/default

# If arguments are passed to the script (e.g., from `podman-compose run app [command]`),
# execute them directly. This is useful for running `artisan` commands.
if [ "$#" -gt 0 ]; then
    exec "$@"
else
    # If no arguments are passed, start the web server for regular operation.

    # Optimize configuration, route, and view caches now that all environment
    # variables (including secrets from Cloud Run) are available.
    echo "Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # Generate session table migration if it doesn't exist.
    php artisan session:table

    # Wait for the database to be ready by retrying the migrate command.
    echo "Waiting for database to be ready..."
    retries=5
    while [ $retries -gt 0 ]; do
        # The --force flag is important for non-interactive execution.
        php artisan migrate --force && break
        retries=$((retries-1))
        echo "Migration failed. Retrying in 5 seconds..."
        sleep 5
    done

    # Exit if migrations failed after all retries.
    if [ $retries -eq 0 ]; then
        echo "Could not connect to the database after several attempts. Aborting."
        exit 1
    fi

    echo "Database ready and migrations complete."

    # Start PHP-FPM in the background
    php-fpm &

    # Start Nginx in the foreground
    nginx -g 'daemon off;'
fi