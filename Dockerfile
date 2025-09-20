# Use the official PHP 8.2 image with Apache
FROM php:8.2-apache

# Install required PHP extensions that the script uses
RUN docker-php-ext-install curl mbstring simplexml

# Copy application files to the Apache document root
COPY . /var/www/html/
