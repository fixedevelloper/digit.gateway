# Image de développement : privilégie un démarrage rapide (php artisan serve)
# à une pile nginx/php-fpm de production, hors périmètre de cette étape.
FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite sqlite-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql mbstring bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
