# Use the official PHP 8.2 image with Apache
FROM php:8.2-apache

# Step 1: Update package lists
RUN apt-get update

# Step 2: Install system dependencies
RUN apt-get install -y --no-install-recommends \
    libxml2-dev \
    libcurl4-openssl-dev

# Step 3: Install PHP extensions
RUN docker-php-ext-install curl mbstring simplexml

# Step 4: Clean up apt cache
RUN rm -rf /var/lib/apt/lists/*

# Step 5: Copy application files
COPY . /var/www/html/
