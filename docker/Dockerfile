FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev curl ca-certificates gnupg \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip pcntl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app || true && useradd -m -u ${UID} -g ${GID} app || true
USER ${UID}:${GID}

WORKDIR /var/www/html
