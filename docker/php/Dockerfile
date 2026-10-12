FROM php:8.2-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      libcurl4-openssl-dev libldap2-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libonig-dev libzip-dev libxml2-dev unzip git util-linux \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl dom gd ldap mbstring pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/runtime.ini /usr/local/etc/php/conf.d/openwiki.ini
COPY docker/php/entrypoint.sh /usr/local/bin/openwiki-entrypoint
COPY docker/php/worker.sh /usr/local/bin/openwiki-worker
RUN chmod 755 /usr/local/bin/openwiki-entrypoint /usr/local/bin/openwiki-worker \
    && printf '[www]\nclear_env = no\ncatch_workers_output = yes\n' > /usr/local/etc/php-fpm.d/zz-openwiki.conf
WORKDIR /app
ENTRYPOINT ["openwiki-entrypoint"]
CMD ["php-fpm", "-F"]
