FROM php:8.2-apache

WORKDIR /var/www/html

# Минимально нужные расширения для текущего проекта
# libonig-dev — для mbstring, libcurl4-openssl-dev — для ext-curl
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install mbstring curl \
    && echo "allow_url_fopen=On" > /usr/local/etc/php/conf.d/zz-railway.ini \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY dashboard/ /var/www/html/

# Права для кэша и снапшотов
RUN mkdir -p /var/www/html/cache /var/www/html/snapshots \
    && chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshots

EXPOSE 80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-80} -t /var/www/html"]
