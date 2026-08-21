FROM php:8.2-apache

ENV TZ=America/Guatemala

RUN sed -i 's|http://deb.debian.org/debian|https://deb.debian.org/debian|g; s|http://deb.debian.org/debian-security|https://deb.debian.org/debian-security|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        pdo_pgsql \
        zip \
        mbstring \
        gd \
    && a2enmod rewrite headers proxy proxy_http \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY dockerfiles/modulos-app/no-php-uploads.conf /etc/apache2/conf-available/no-php-uploads.conf
RUN a2enconf no-php-uploads

COPY dockerfiles/modulos-app/error-pages.conf /etc/apache2/conf-available/error-pages.conf
RUN a2enconf error-pages

# Reenvia /sigec/* al contenedor "sigec" (docker-compose.prod.yml), sin
# exponer su puerto publicamente: modulos.cengicana.org/sigec/... se sirve
# asi bajo el mismo dominio ya configurado, sin necesitar un subdominio ni
# tocar el proxy externo. Ver dockerfiles/modulos-app/sigec-proxy.conf y
# sigec/app/Providers/AppServiceProvider.php (URL::forceRootUrl) para la
# otra mitad de este arreglo: sin eso, los enlaces/assets que genera
# Laravel saldrian sin el prefijo /sigec.
COPY dockerfiles/modulos-app/sigec-proxy.conf /etc/apache2/conf-available/sigec-proxy.conf
RUN a2enconf sigec-proxy

COPY dockerfiles/modulos-app/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www/html
COPY . /var/www/html

RUN if [ -f login/composer.json ]; then \
        cd login && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
    fi

RUN if [ -f cengicursos/composer.json ]; then \
        cd cengicursos && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
    fi

RUN chown -R www-data:www-data /var/www/html

# Nota: este chown solo afecta el contenido copiado dentro de la imagen. Los
# directorios montados como bind mount en runtime (p. ej. `./uploads` en
# docker-compose.prod.yml) conservan la propiedad/permisos del host y NO
# quedan cubiertos por este RUN. Ver dockerfiles/modulos-app/entrypoint.sh,
# que corrige eso en cada arranque del contenedor.
COPY dockerfiles/modulos-app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]

