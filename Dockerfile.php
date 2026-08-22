FROM php:8.2-apache

# pdo_pgsql needs libpq's headers present at build time (pdo_mysql didn't
# need an extra apt package here, since libmysqlclient ships in the base image).
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_pgsql

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Render (and most PaaS hosts) inject a $PORT env var and expect the
# container to listen on it instead of the fixed port 80 that Apache
# uses by default. This entrypoint rewrites Apache's config to listen
# on $PORT at boot, falling back to 80 for local `docker compose up`.
COPY docker-entrypoint-apache.sh /usr/local/bin/docker-entrypoint-apache.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-apache.sh

ENV PORT=80
EXPOSE 80

ENTRYPOINT ["docker-entrypoint-apache.sh"]
CMD ["apache2-foreground"]
