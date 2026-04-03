FROM php:8.2-apache

WORKDIR /var/www/html

# Минимально нужные расширения для текущего проекта
# libonig-dev — для mbstring, libcurl4-openssl-dev — для ext-curl
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install mbstring curl \
    && docker-php-ext-enable curl \
    && printf "%s\n" \
        "allow_url_fopen=On" \
        "memory_limit=512M" \
        "max_execution_time=0" \
        "max_input_time=300" \
        "output_buffering=Off" > /usr/local/etc/php/conf.d/zz-railway.ini \
    && a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite headers \
    && php -r "exit(function_exists('curl_init') ? 0 : 1);" \
    && php -r "exit((int)!ini_get('allow_url_fopen'));" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY dashboard/ /var/www/html/

# Права для кэша и снапшотов
RUN mkdir -p /var/www/html/cache /var/www/html/snapshots \
    && chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshots

# Apache слушает PORT из Railway
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf
COPY docker/apache-railway.conf /etc/apache2/sites-enabled/000-default.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
