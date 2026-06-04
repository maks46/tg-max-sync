FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    supervisor \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (cache layer)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application source
COPY . .

# Ensure runtime directories exist
RUN mkdir -p /app/data /app/logs /app/storage

# Place supervisord config where Alpine's supervisord expects it
COPY supervisord.conf /etc/supervisord.conf

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
