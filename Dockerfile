FROM php:8.3-cli-alpine

# ── xray-core version (override at build time: --build-arg XRAY_VERSION=1.8.x) ──
ARG XRAY_VERSION=25.3.6

RUN apk add --no-cache \
    supervisor \
    curl \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    icu-dev \
    icu-libs \
    sqlite-dev \
    && docker-php-ext-install pdo pdo_sqlite intl pcntl

# ── Install xray-core ──────────────────────────────────────────────────────────
# Detect CPU arch and pick the matching release asset.
RUN ARCH=$(uname -m) && \
    case "$ARCH" in \
      x86_64)  XRAY_ARCH="64" ;; \
      aarch64) XRAY_ARCH="arm64-v8a" ;; \
      armv7l)  XRAY_ARCH="arm32-v7a" ;; \
      *)       echo "Unsupported arch: $ARCH" && exit 1 ;; \
    esac && \
    curl -fsSL \
      "https://github.com/XTLS/Xray-core/releases/download/v${XRAY_VERSION}/Xray-linux-${XRAY_ARCH}.zip" \
      -o /tmp/xray.zip && \
    unzip -q /tmp/xray.zip xray geoip.dat geosite.dat -d /usr/local/bin/ && \
    chmod +x /usr/local/bin/xray && \
    rm /tmp/xray.zip

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
