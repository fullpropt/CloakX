FROM php:8.2-apache

RUN a2enmod rewrite

# Dependências do sistema
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
 && docker-php-ext-install zip

# Copia código
COPY . /var/www/html/

# Instala dependências PHP
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php \
 && php composer.phar install --no-dev --optimize-autoloader \
 && rm composer.phar composer-setup.php

RUN chown -R www-data:www-data /var/www/html
