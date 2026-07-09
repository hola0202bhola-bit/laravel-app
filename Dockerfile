FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    unzip \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo pdo_sqlite zip

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache DocumentRoot to Laravel's public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure Apache port to listen to Render's dynamic PORT env var
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/0000-default.conf /etc/apache2/sites-available/default-ssl.conf /etc/apache2/ports.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Set correct permissions for Laravel directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Run composer installation
RUN composer install --no-dev --optimize-autoloader

# Expose port
EXPOSE 80

# Start apache
CMD ["apache2-foreground"]
