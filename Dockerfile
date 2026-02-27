FROM composer/composer:2-bin AS composer

FROM mlocati/php-extension-installer:latest AS php_extension_installer

FROM php:8.5-zts-trixie

# php extensions installer: https://github.com/mlocati/docker-php-extension-installer
COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        unzip \
    ; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        pcntl \
        event \
        pcov \
    ;

COPY --from=composer /composer /usr/local/bin/

RUN set -eux; \
    groupadd --gid 1000 app; \
    useradd --uid 1000 --gid 1000 --create-home --shell /bin/bash app; \
    mkdir -p /app /home/app/.composer/cache; \
    chown -R app:app /app /home/app

ENV HOME=/home/app
ENV PATH="${PATH}:/home/app/.composer/vendor/bin"
ENV COMPOSER_HOME=/home/app/.composer
ENV COMPOSER_CACHE_DIR=$COMPOSER_HOME/cache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER app
WORKDIR /app

RUN set -eux; \
    git config --global --add safe.directory /app

COPY --chown=app:app composer.* ./
RUN --mount=type=cache,target=$COMPOSER_CACHE_DIR,uid=1000,gid=1000 \
  composer install --prefer-dist --no-autoloader --no-scripts --no-progress

COPY --chown=app:app . .

RUN set -eux; \
    composer dump-autoload --optimize

STOPSIGNAL SIGTERM

CMD ["php", "./bin/worker.php"]
