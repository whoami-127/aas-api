FROM php:8.2-apache

# Bật mod_rewrite + headers
RUN a2enmod rewrite headers

# Cho phép .htaccess
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-enabled/allow-override.conf

WORKDIR /var/www/html
COPY . .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
