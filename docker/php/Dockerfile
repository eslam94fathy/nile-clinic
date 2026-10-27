# Local development PHP-FPM image for the Laravel API (plan: research R2, R4, R13).
FROM php:8.5.12-fpm-trixie

ARG UID=1000
ARG GID=1000

# git/unzip are needed by Composer; only the extensions the foundation requires.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql pcntl

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

COPY conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

# Non-root user so files created on bind mounts are not owned by root.
# vendor/ is pre-created and owned by app because it is mounted as a named volume
# (approved D8); a fresh named volume copies this ownership.
RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app \
    && mkdir -p /var/www/html/vendor \
    && chown app:app /var/www/html/vendor

WORKDIR /var/www/html

USER app
