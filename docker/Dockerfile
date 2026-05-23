# Multi-stage Dockerfile for FlowForge
# Stage 1: Build frontend assets
FROM node:20-alpine AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --frozen-lockfile

COPY resources/ resources/
COPY vite.config.ts tsconfig.json ./
RUN npm run build

# Stage 2: Production image
FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    libpq \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    git \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    mbstring \
    && apk del libzip-dev oniguruma-dev icu-dev

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application code
COPY . .

# Copy built assets
COPY --from=frontend-builder /app/public/build ./public/build

# Install PHP dependencies (production only)
RUN composer install --optimize-autoloader --no-dev --no-scripts

# Run production scripts
RUN composer dump-autoload --optimize

# Set permissions
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Expose port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]
