FROM php:8.2-apache

# Remove MPMs conflitantes e ativa apenas prefork
RUN a2dismod mpm_event mpm_worker \
 && a2enmod mpm_prefork rewrite

# Dependências do sistema
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
 && docker-php-ext-install zip

# Copia o código
COPY . /var/www/html/

# Instala Composer e dependências
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php composer-setup.php \
 && php composer.phar install --no-dev --optimize-autoloader \
 && rm composer.phar composer-setup.php

RUN chown -R www-data:www-data /var/www/html
