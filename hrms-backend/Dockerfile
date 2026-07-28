# 1. Use the official PHP image with an Apache web server
FROM php:8.4-apache

# 2. Install system dependencies and Postgres extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql zip

# 3. Enable Apache URL routing (required for Laravel)
RUN a2enmod rewrite

# 4. Point Apache to Laravel's "public" folder
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 5. Configure Apache to listen on Render's dynamic PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 6. Set working directory
WORKDIR /var/www/html

# 7. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 8. Copy your backend code into the container
COPY . .

# 9. Create missing Laravel folders and fix permissions
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 10. Install Laravel dependencies
RUN composer install --optimize-autoloader --no-interaction

# 11. Run optimizations and turn on the server
CMD php artisan config:cache && php artisan route:cache && apache2-foreground
