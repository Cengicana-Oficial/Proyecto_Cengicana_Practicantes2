FROM php:8.1-apache

ENV TZ=America/Guatemala

# Nota: v1 corre sin worker de Horizon ni cron de artisan schedule (se
# difiere junto con Redis, ver deploy/env/sigec.env.example). Se instala
# ext-pcntl igual porque laravel/horizon lo exige como dependencia dura de
# composer (composer install falla la verificacion de plataforma sin el),
# aunque el proceso del worker no se arranque todavia.
RUN sed -i 's|http://deb.debian.org/debian|https://deb.debian.org/debian|g; s|http://deb.debian.org/debian-security|https://deb.debian.org/debian-security|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libicu-dev \
        libxml2-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mysqli \
        mbstring \
        zip \
        gd \
        intl \
        bcmath \
        xml \
        pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY dockerfiles/sigec-app/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html/sigec
COPY sigec/. /var/www/html/sigec

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/sigec

# Ver dockerfiles/sigec-app/entrypoint.sh: corrige en cada arranque los
# permisos de storage/ y bootstrap/cache/ (y de los volumenes montados en
# runtime, que no quedan cubiertos por el chown de build) y regenera el
# symlink public/storage.
COPY dockerfiles/sigec-app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
