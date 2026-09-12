# Patch version is pinned on purpose: "reproducible environment" means the reviewer
# gets the same PHP build we developed against, not whatever 8.5 resolves to later.
FROM php:8.5.9-cli

# pdo_mysql is NOT present in the base image -- it ships only PDO and pdo_sqlite.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && docker-php-ext-install pdo_mysql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
