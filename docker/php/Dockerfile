# Infernix Workspace — application image (PHP 8.3 CLI + Chromium for Browsershot PDF rendering)
FROM php:8.3-cli-bookworm

ARG NODE_MAJOR=22
ENV DEBIAN_FRONTEND=noninteractive \
    CHROME_PATH=/usr/bin/chromium \
    NODE_BINARY=/usr/bin/node \
    NPM_BINARY=/usr/bin/npm \
    TZ=Asia/Kuala_Lumpur

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gnupg libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev \
        libfreetype6-dev libonig-dev chromium fonts-liberation fonts-noto-color-emoji tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl gd bcmath pcntl opcache exif \
    && pecl install redis && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g puppeteer --unsafe-perm=true \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-infernix.ini

WORKDIR /var/www/html
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
