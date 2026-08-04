FROM php:8.2-apache

# Install system dependencies & libraries required for PECL extensions
RUN apt-get update && apt-get install -y \
    libssl-dev \
    pkg-config \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install PDO MySQL extension
RUN docker-php-ext-install pdo_mysql

# Install PECL extensions (MongoDB and Redis)
RUN pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set Apache DocumentRoot configuration to allow htaccess if needed
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files into web root
COPY . /var/www/html/

# Adjust file permissions for Apache user
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
