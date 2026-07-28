FROM php:8.4-fpm
# Note: dev image uses PHP 8.4, prod runs PHP 8.5. We don't depend on any
# 8.5-only language features yet, so this is compatible. The 8.5 Docker
# image had a broken docker-php-ext-install pipeline at the time of this
# writing — switch back when fixed.

# Extensions needed across phases:
#   pdo_mysql + opcache — core
#   gd + mbstring        — required by mPDF (Phase 3a-3 PDF generation)
#   zip                  — required by PhpSpreadsheet (Excel import/export);
#                          .xlsx is a ZIP archive, needs the ZipArchive class
# Installing one at a time avoids a known alpine-image quirk where
# parallel install-modules can't find the .so output.
# gd needs jpeg/png/freetype headers + zlib at build time.
# zip needs libzip headers at build time.
#
# `docker-php-ext-configure gd` MUST stay immediately before its matching
# `docker-php-ext-install gd`. Both helpers extract /usr/src/php on demand and
# mark it for deletion, and ext-install removes it again when it finishes — so
# any ext-install in between silently discards gd's configured Makefile, and
# ext-install gd then reconfigures with defaults, producing a GD without JPEG
# support. That breaks field-photo processing (change request 2.2) while
# leaving `extension_loaded('gd')` true, so it fails far from its cause.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip \
        libjpeg-dev libpng-dev libfreetype6-dev libwebp-dev zlib1g-dev libonig-dev \
        libzip-dev \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install opcache \
    && docker-php-ext-install mbstring \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install gd \
    && docker-php-ext-install zip \
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
