FROM php:8.3-apache

# Install system dependencies for zip extension
RUN apt-get update && apt-get install -y libzip-dev && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed by Sleuth
RUN docker-php-ext-install pdo pdo_mysql zip

# Enable Apache mod_rewrite (in case needed later)
RUN a2enmod rewrite

# Set the document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Raise PHP upload limits for game import
RUN echo "upload_max_filesize = 200M\npost_max_size = 200M" > /usr/local/etc/php/conf.d/uploads.ini

# Copy application code
COPY . /var/www/html/

# Create directories for generated artwork and ensure Apache can write to them
RUN mkdir -p /var/www/html/assets/images /var/www/html/assets/covers \
    && chown -R www-data:www-data /var/www/html/assets/images /var/www/html/assets/covers \
    && chown www-data:www-data /var/www/html/config.json 2>/dev/null || true

EXPOSE 80
