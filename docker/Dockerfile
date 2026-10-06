# Based on fkde/aspx: Alpine, native PHP packages, nginx and supervisor.
ARG ALPINE_IMAGE=alpine:3.24.1@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b
FROM ${ALPINE_IMAGE} AS runtime
RUN apk add --no-cache ca-certificates curl msmtp nginx supervisor tzdata \
    php85 php85-fpm php85-pdo_mysql \
    php85-session php85-mbstring php85-openssl php85-curl php85-dom php85-xml \
    php85-xmlwriter php85-simplexml php85-fileinfo php85-tokenizer php85-ctype \
    php85-iconv php85-phar php85-ldap php85-intl php85-sodium \
    && ln -sf /usr/bin/php85 /usr/bin/php \
    && addgroup -g 1000 www && adduser -D -u 1000 -G www www \
    && mkdir -p /var/www/storage /tmp/nginx /tmp/php \
    && chown -R www:www /var/www /tmp/nginx /tmp/php
COPY docker/rootfs/ /

WORKDIR /var/www
USER www
ENV NAFINITY_BACKGROUND_ENABLED=true
# supervisord refuses to start at all when a variable its programs name is unset.
ENV WEBSOCKET_ENABLED=false
EXPOSE 8080 8443
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s CMD ["/usr/local/bin/nafinity-healthcheck"]
ENTRYPOINT ["/usr/local/bin/nafinity-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM runtime AS development
USER root
RUN apk add --no-cache composer git
COPY docker/development/rootfs/ /
USER www

# The installation as it ships: dependencies from the published packages (from
# app/composer.lock when there is one), their public files copied into public/,
# and no Composer left in the image.
FROM runtime AS build
USER root
RUN apk add --no-cache composer
USER www
COPY --chown=www:www app/ /var/www/
# The board refuses to boot without database settings; publishing never connects.
RUN composer install --no-dev --no-interaction --no-progress --classmap-authoritative \
    && mkdir -p storage/sessions storage/logs storage/attachments storage/queue storage/schedule storage/oauth \
    && DB_DRIVER=mysql DB_HOST=unused DB_DATABASE=unused DB_USERNAME=unused DB_PASSWORD=unused \
        php vendor/bin/naf nafinity:assets:publish

FROM runtime AS production
COPY --from=build --chown=www:www /var/www /var/www
