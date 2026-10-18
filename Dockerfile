# ==============================================================================
# Multi-stage Dockerfile for Laravel Snake Arena (Railway + MySQL + Reverb)
# ==============================================================================

# Stage 1: Build Frontend Assets
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm install --no-audit --no-fund --legacy-peer-deps
COPY resources resources/
COPY public public/
COPY vite.config.js ./
RUN npm run build 2>/dev/null || echo "No Vite build — using pre-built public/ assets"

# Stage 2: Composer Dependencies
FROM composer:2.7 AS composer-stage
WORKDIR /app
COPY composer*.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# Stage 3: Production Runtime
FROM php:8.3-cli-alpine AS runtime

# Install system dependencies & PHP extensions
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    mysql-client \
    linux-headers \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    pcntl \
    sockets \
    bcmath \
    zip \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

# Copy application code
COPY . .
COPY --from=composer-stage /app/vendor vendor/
COPY --from=frontend /app/public public/

# Configure permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy startup script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["web"]
