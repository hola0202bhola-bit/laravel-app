FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    unzip \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo pdo_sqlite zip bcmath

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache DocumentRoot to Laravel's public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf


# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install the academic deployment entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set correct permissions for Laravel directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Run composer installation
RUN composer install --no-dev --optimize-autoloader

# Expose port
EXPOSE 80

# Prepare the ephemeral demo database, then start Apache
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
