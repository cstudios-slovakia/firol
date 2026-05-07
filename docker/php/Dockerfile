FROM php:8.4-fpm
# Note: dev image uses PHP 8.4, prod (Websupport) is 8.5. We don't depend
# on any 8.5-only language features yet, so this is compatible. The 8.5
# Docker image had a broken docker-php-ext-install pipeline at the time
# of this writing — switch back when fixed.

# Minimal extensions for the current phase: pdo_mysql + opcache.
# (gd / intl / zip / mbstring will be added in Phase 3 when PDF generation
# starts needing them.) Installing extensions one at a time avoids a known
# alpine-image quirk where parallel install-modules can't find the .so output.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'memory_limit=512M'; \
        echo 'date.timezone=Europe/Bratislava'; \
    } > /usr/local/etc/php/conf.d/firol.ini

WORKDIR /var/www/backend

EXPOSE 9000
CMD ["php-fpm"]
