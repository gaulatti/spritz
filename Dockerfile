FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    mysql-client \
    curl \
    freetype-dev \
    libavif-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev

RUN docker-php-ext-configure gd \
        --with-avif \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install gd mysqli pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json .
RUN composer install --no-interaction --optimize-autoloader --no-dev
RUN mkdir -p /var/www/html/wordpress/wp-content/plugins \
    && cp -R /var/www/html/wp-content/plugins/. /var/www/html/wordpress/wp-content/plugins/

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

RUN mkdir -p /var/www/html/wordpress/wp-content/mu-plugins \
    && { \
        echo '<?php'; \
        echo 'add_filter("pre_site_transient_update_core","__return_null");'; \
        echo 'add_filter("pre_site_transient_update_plugins","__return_null");'; \
        echo 'add_filter("pre_site_transient_update_themes","__return_null");'; \
        echo 'add_filter("site_transient_update_core","__return_null");'; \
        echo 'add_filter("site_transient_update_plugins","__return_null");'; \
        echo 'add_filter("site_transient_update_themes","__return_null");'; \
        echo 'remove_action("admin_init","_maybe_update_core");'; \
        echo 'remove_action("admin_init","_maybe_update_plugins");'; \
        echo 'remove_action("admin_init","_maybe_update_themes");'; \
    } > /var/www/html/wordpress/wp-content/mu-plugins/disable-updates.php \
    && chown -R www-data:www-data /var/www/html/wordpress/wp-content

COPY wp-content/mu-plugins/ /var/www/html/wordpress/wp-content/mu-plugins/
COPY wp-content/themes/ /var/www/html/wordpress/wp-content/themes/
COPY bin/load-secrets.php /usr/local/bin/load-secrets.php
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
COPY wp-config.php .
COPY docker-entrypoint.sh /usr/local/bin/

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
