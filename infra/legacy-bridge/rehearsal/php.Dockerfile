FROM php:8.2-cli
RUN docker-php-ext-install mysqli
WORKDIR /var/www
CMD ["php", "-S", "0.0.0.0:8090", "-t", "/var/www"]
