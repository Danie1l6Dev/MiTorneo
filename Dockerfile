# --- Stage 1: build frontend assets ---
FROM node:22-slim AS node-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- Stage 2: PHP application ---
FROM php:8.4-cli-alpine AS app
WORKDIR /var/www/html

RUN apk add --no-cache \
        git \
        unzip \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=node-build /app/public/build ./public/build

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["start.sh"]
