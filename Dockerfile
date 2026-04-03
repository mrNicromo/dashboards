FROM php:8.2-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install mbstring curl \
    && docker-php-ext-enable curl \
    && printf "%s\n" \
        "allow_url_fopen=On" \
        "memory_limit=512M" \
        "max_execution_time=0" \
        "max_input_time=300" \
        "output_buffering=Off" > /usr/local/etc/php/conf.d/zz-railway.ini \
    && php -r "exit(function_exists('curl_init') ? 0 : 1);" \
    && php -r "exit((int)!ini_get('allow_url_fopen'));" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY dashboard/ /var/www/html/

# Права для кэша и снапшотов
RUN mkdir -p /var/www/html/cache /var/www/html/snapshots \
    && chown -R www-data:www-data /var/www/html/cache /var/www/html/snapshots

EXPOSE 80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-80} -t /var/www/html"]
