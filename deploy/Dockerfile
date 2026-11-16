FROM node:24-bookworm-slim@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6 AS node
FROM dunglas/frankenphp:1.13.5-php8.4.27-bookworm@sha256:30061df7626971d597e45962269dfa7c077d972e96d1c4baf7c9259d5f7ecd55 AS source
RUN install-php-extensions bcmath intl opcache pcntl pdo_pgsql redis zip \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && groupadd --gid 10001 kernscale \
    && useradd --uid 10001 --gid 10001 --create-home --shell /usr/sbin/nologin kernscale \
    && setcap -r /usr/local/bin/frankenphp
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY database/migrations ./database/migrations
COPY routes ./routes
COPY resources ./resources
COPY public ./public
COPY artisan ./artisan
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data /config \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chown -R kernscale:kernscale storage bootstrap/cache /data /config

FROM source AS frontend
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY package.json package-lock.json vite.config.ts tsconfig.json ./
RUN npm ci --ignore-scripts && npm run build

FROM source AS runtime
ARG SOURCE_COMMIT
ARG SOURCE_REPOSITORY
ARG SOURCE_REF
ARG SOURCE_CREATED
ARG SOURCE_VERSION
LABEL org.opencontainers.image.title="Kernscale Trading Hub" \
      org.opencontainers.image.revision=$SOURCE_COMMIT \
      org.opencontainers.image.source=$SOURCE_REPOSITORY \
      org.opencontainers.image.ref.name=$SOURCE_REF \
      org.opencontainers.image.created=$SOURCE_CREATED \
      org.opencontainers.image.version=$SOURCE_VERSION
COPY --from=frontend /app/public/build ./public/build
COPY deploy/Caddyfile /etc/caddy/Caddyfile
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
USER kernscale
EXPOSE 8000
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
