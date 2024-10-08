FROM composer/composer:2-bin AS composer

FROM mlocati/php-extension-installer:latest AS php_extension_installer

FROM php:8.3-cli-alpine

# php extensions installer: https://github.com/mlocati/docker-php-extension-installer
COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk update && apk upgrade

RUN set -eux; \
    install-php-extensions \
        pcntl \
    	event \
        pcov \
    ;

COPY --from=composer /composer /usr/local/bin/

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="${PATH}:/root/.composer/vendor/bin"
ENV COMPOSER_HOME=/var/composer
ENV COMPOSER_CACHE_DIR=$COMPOSER_HOME/cache

RUN mkdir -m 0777 $COMPOSER_HOME

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www

COPY composer.* ./
RUN --mount=type=cache,target=$COMPOSER_CACHE_DIR \
  composer install --prefer-dist --no-autoloader --no-scripts --no-progress

COPY . .

RUN set -eux; \
    composer dump-autoload --classmap-authoritative

STOPSIGNAL SIGTERM

CMD ["php", "./bin/worker.php"]
