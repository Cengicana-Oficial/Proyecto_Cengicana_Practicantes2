FROM php:8.2-apache

ENV TZ=America/Guatemala

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html

RUN if [ -f login/composer.json ]; then \
        cd login && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
    fi

RUN chown -R www-data:www-data /var/www/html

