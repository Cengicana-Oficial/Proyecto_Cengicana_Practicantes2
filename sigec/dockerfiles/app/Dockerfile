FROM ubuntu:22.04

LABEL maintainer="SIGEC"

ARG NODE_VERSION=20

WORKDIR /var/www

ENV DEBIAN_FRONTEND noninteractive
ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN echo 'alias migrate="php artisan migrate"' >> ~/.bashrc
RUN echo 'alias rollback="php artisan migrate:rollback"' >> ~/.bashrc
RUN echo 'alias seed="php artisan db:seed"' >> ~/.bashrc
RUN echo 'alias cda="composer dump-autoload"' >> ~/.bashrc

RUN sed -i 's|http://archive.ubuntu.com|https://archive.ubuntu.com|g; s|http://security.ubuntu.com|https://security.ubuntu.com|g' /etc/apt/sources.list \
    && apt-get -o Acquire::https::Verify-Peer=false update \
    && apt-get -o Acquire::https::Verify-Peer=false install -y ca-certificates \
    && update-ca-certificates \
    && apt-get update \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y gnupg gosu curl zip unzip supervisor libcap2-bin libpng-dev \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor | tee /usr/share/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/usr/share/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y php7.4-fpm php7.4-cli php7.4-dev \
    php7.4-gd \
    php7.4-curl php7.4-memcached \
    php7.4-imap php7.4-mysql php7.4-mbstring \
    php7.4-xml php7.4-zip php7.4-bcmath php7.4-soap \
    php7.4-intl php7.4-readline \
    php7.4-msgpack php7.4-igbinary php7.4-ldap \
    php7.4-redis php7.4-imagick \
    && apt-get install -y nginx \
    && update-rc.d nginx defaults \
    && php -r "readfile('https://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update && apt-get install -y nodejs mysql-client \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php7.4

COPY dockerfiles/app/start-container /usr/local/bin/start-container
COPY dockerfiles/app/scheduler.sh /usr/local/bin/scheduler.sh
COPY dockerfiles/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY dockerfiles/app/php.ini /etc/php/7.4/fpm/conf.d/99-sigec.ini
COPY dockerfiles/app/vhost.conf /etc/nginx/sites-available/default

RUN chmod +x /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/scheduler.sh

ENTRYPOINT ["start-container"]
