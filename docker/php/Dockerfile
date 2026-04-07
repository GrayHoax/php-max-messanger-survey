FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    curl \
    libsqlite3 \
    sqlite-dev \
    unzip \
    git

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app

WORKDIR /var/www/html

# Copy composer files and install dependencies first (layer caching)
COPY --chown=app:app composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-dev --prefer-dist 2>/dev/null || true

# Copy application source
COPY --chown=app:app . .

# Finish autoloader optimisation
RUN composer dump-autoload --optimize --no-dev

# Ensure data directory is writable
RUN mkdir -p /var/www/html/data && chown app:app /var/www/html/data

USER app

EXPOSE 9000

CMD ["php-fpm"]
