FROM php:8.2-apache

WORKDIR /var/www/html

# Минимально нужные расширения для текущего проекта
RUN apt-get update && apt-get install -y libonig-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mbstring

# Включаем mod_headers на случай будущих заголовков безопасности
RUN a2dismod mpm_event mpm_worker; a2enmod mpm_prefork
RUN a2enmod headers

COPY dashboard/ /var/www/html/

# Права для кэша и снапшотов
RUN mkdir -p /var/www/html/cache /var/www/html/snapshots \
    && chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshots

EXPOSE 80
