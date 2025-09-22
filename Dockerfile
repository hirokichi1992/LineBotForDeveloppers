# Use the official PHP 8.2 image with Apache
FROM php:8.2-apache

# Install system dependencies for PHP extensions, then the extensions themselves
RUN apt-get update && apt-get install -y --no-install-recommends \
    libxml2-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libpq-dev \
    && docker-php-ext-install \
    curl \
    mbstring \
    pdo_pgsql \
    simplexml \
    && rm -rf /var/lib/apt/lists/*

# Copy application files to the Apache document root
COPY . /var/www/html/