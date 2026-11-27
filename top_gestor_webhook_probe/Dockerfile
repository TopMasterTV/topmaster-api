FROM php:8.2-cli-alpine

RUN apk add --no-cache curl-dev \
    && docker-php-ext-install curl

WORKDIR /app
COPY top_gestor_webhook_probe/src /app/src
COPY top_gestor_webhook_probe/public /app/public
COPY top_gestor_webhook_probe/tests /app/tests

RUN php -l /app/src/Probe.php \
    && php -l /app/public/index.php \
    && php -l /app/tests/run.php \
    && php /app/tests/run.php \
    && rm -rf /app/tests

RUN addgroup -S probe \
    && adduser -S -G probe probe \
    && chown -R probe:probe /app

USER probe
EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]
