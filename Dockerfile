FROM php:8.2-apache

# Bật mod_rewrite để Apache xử lý routing
RUN a2enmod rewrite headers

# Cài extension cần thiết cho PHPMailer + SSL
RUN apt-get update && apt-get install -y \
    libssl-dev \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Cài Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy toàn bộ code vào Apache web root
WORKDIR /var/www/html
COPY . .

# Cài PHPMailer qua Composer
RUN composer require phpmailer/phpmailer --no-interaction --no-progress

# Phân quyền
RUN chown -R www-data:www-data /var/www/html

# Cấu hình Apache: cho phép .htaccess
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-enabled/allow-override.conf

EXPOSE 80
