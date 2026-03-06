FROM php:8.2-apache

# Bật mod_rewrite + headers
RUN a2enmod rewrite headers

# Cài các package hệ thống + extension PHP cần thiết
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libssl-dev \
    ca-certificates \
    unzip \
    git \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Cài Composer từ image chính thức
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set WORKDIR
WORKDIR /var/www/html

# Copy composer.json trước để tận dụng Docker cache
COPY composer.json .

# Cài PHPMailer
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Copy toàn bộ code còn lại
COPY . .

# Phân quyền
RUN chown -R www-data:www-data /var/www/html

# Cho phép .htaccess override
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-enabled/allow-override.conf

EXPOSE 80
