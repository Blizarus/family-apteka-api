FROM php:8.4-fpm-alpine

# Install system dependencies required for PHP extensions and tooling
RUN apk add --no-cache \
    curl \
    git \
    libzip-dev \
    oniguruma-dev \
    libsodium-dev \
    zip \
    unzip

# Install required PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    bcmath \
    opcache \
    zip \
    sodium

# Install Composer from official Composer 2 image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files into the container
COPY . /var/www/html

# Configure ownership and permissions for Laravel writable directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose FastCGI port
EXPOSE 9000

# Run PHP-FPM service
CMD ["php-fpm"]
