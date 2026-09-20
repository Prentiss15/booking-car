FROM php:8.4-apache

# Set timezone to Asia/Bangkok
ENV TZ=Asia/Bangkok
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install SQLite dependencies and extensions
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure PHP settings
RUN echo "date.timezone = Asia/Bangkok" > /usr/local/etc/php/conf.d/timezone.ini \
    && echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "expose_php = Off" > /usr/local/etc/php/conf.d/security.ini

# Copy project files
WORKDIR /var/www/html
COPY . /var/www/html/

# Create data directory for SQLite database persistence and set permissions
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data

# Environment variable for database path
ENV DB_PATH=/var/www/html/data/database.sqlite

EXPOSE 80

CMD ["apache2-foreground"]
