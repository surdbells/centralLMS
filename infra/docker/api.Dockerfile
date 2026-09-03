# syntax=docker/dockerfile:1
#
# Development image for the Slim API.
#
# Production deploys to an aaPanel host with PHP-FPM rather than this image,
# so the extension list here is the contract both must satisfy — if you add an
# ext-* requirement to composer.json, add it here too and the mismatch shows up
# locally instead of on the server.

FROM php:8.3-cli-alpine

# --- system + PHP extensions -------------------------------------------------
RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        git \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
    # ext-redis is required by symfony/redis-messenger; predis alone only
    # satisfies the cache, not the queue transport.
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# --- workbook decryption -----------------------------------------------------
# Several completed JCP workbooks are saved with a password to open. openspout
# (and PHP) cannot read an encrypted OOXML file, so WorkbookDecryptor shells out
# to msoffcrypto-tool to decrypt it first. The cryptography wheel it depends on
# ships a musllinux build, so no compiler is needed here.
#
# NOTE for production (the aaPanel PHP-FPM host, which does not use this image):
# install the same tool there — `pip install msoffcrypto-tool` — or encrypted
# JCP uploads will be reported as needing a password with no way to open them.
RUN apk add --no-cache python3 py3-pip \
    && pip install --no-cache-dir --break-system-packages msoffcrypto-tool

# --- office-document conversion ----------------------------------------------
# The digital library shows PowerPoint and Word inline without a download, which
# a browser cannot do directly. The conversion worker turns them into PDF with
# headless LibreOffice (writer for Word, impress for PowerPoint; noto covers the
# fonts). Only the worker runs the conversion, but api and worker share this
# image. This is a large add (~hundreds of MB); it is why the image grew.
#
# NOTE for production (the aaPanel PHP-FPM host): install libreoffice there too,
# or Office uploads to the library stay in "conversion failed" and cannot be
# viewed. The binary is `soffice`; ConvertDocumentToPdfHandler finds it on PATH.
RUN apk add --no-cache libreoffice-writer libreoffice-impress font-noto

# --- opcache -----------------------------------------------------------------
# validate_timestamps stays ON here. It is off in production, which is why a
# deploy there needs an explicit FPM reload.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=256M'; \
        echo 'post_max_size=256M'; \
        echo 'max_execution_time=120'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api

# Dependencies are installed in their own layer so that editing source does not
# invalidate the composer cache.
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY apps/api /var/www/api
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

EXPOSE 8080

# The built-in server is adequate for local work and keeps the image to a
# single process. Production uses PHP-FPM behind nginx or Apache.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
