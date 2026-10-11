FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN mkdir -p /opt/gasgo-original-photos
COPY public/uploads/stations/ /opt/gasgo-original-photos/
COPY docker/railway-entrypoint.sh /usr/local/bin/gasgo-entrypoint
RUN chmod +x /usr/local/bin/gasgo-entrypoint \
    && mkdir -p /var/www/html/uploads/stations /var/www/html/public/uploads/stations \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/public/uploads

WORKDIR /var/www/html
EXPOSE 8080
CMD ["gasgo-entrypoint"]
