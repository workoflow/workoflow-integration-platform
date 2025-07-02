# Build stage
FROM dunglas/frankenphp:php8.4 AS builder

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock symfony.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy package files for npm
COPY package.json package-lock.json webpack.config.js ./

# Install npm dependencies
RUN npm ci --no-audit

# Copy rest of application
COPY . .

# Build assets
RUN npm run build

# Final stage
FROM dunglas/frankenphp:php8.4

# Install runtime dependencies only
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Set working directory
WORKDIR /app

# Copy application from builder
COPY --from=builder --chown=www-data:www-data /app /app

# Remove unnecessary files
RUN rm -rf node_modules .git .env.* docker* tests

EXPOSE 80 443