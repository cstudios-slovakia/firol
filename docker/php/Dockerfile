FROM php:8.5-fpm-alpine

# System dependencies + PHP extensions potrebné pre MariaDB, intl, zip, gd.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && apk add --no-cache \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        git \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        intl \
        zip \
        gd \
        mbstring \
        opcache \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Rozumné defaulty pre dev.
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'date.timezone=Europe/Bratislava'; \
    } > /usr/local/etc/php/conf.d/firol.ini

WORKDIR /var/www/backend

EXPOSE 9000
CMD ["php-fpm"]
