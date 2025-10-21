# Use the official Nginx image.
# https://hub.docker.com/_/nginx
FROM nginx:latest

# Install gettext-base for envsubst
RUN apt-get update && apt-get install -y gettext-base && rm -rf /var/lib/apt/lists/*

# Remove the default Nginx configuration
RUN rm /etc/nginx/conf.d/default.conf

# Copy Nginx configuration template
COPY nginx.conf.template /etc/nginx/conf.d/default.template

# Add entrypoint script to substitute environment variables and start Nginx
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port 8080
EXPOSE 8080

# Set the entrypoint
ENTRYPOINT ["entrypoint.sh"]
