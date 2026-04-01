FROM php:8.2-apache

WORKDIR /var/www/html

# Минимально нужные расширения для текущего проекта
# libonig-dev — зависимость mbstring (oniguruma) в официальном образе php
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Включаем mod_headers на случай будущих заголовков безопасности
RUN a2enmod headers

COPY railway-entrypoint.sh /usr/local/bin/railway-entrypoint.sh
RUN chmod +x /usr/local/bin/railway-entrypoint.sh

COPY dashboard/ /var/www/html/

# Права для кэша и снапшотов
RUN mkdir -p /var/www/html/cache /var/www/html/snapshots \
    && chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshots

EXPOSE 80

CMD ["/usr/local/bin/railway-entrypoint.sh"]
