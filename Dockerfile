FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql \
 && apt-get update && apt-get install -y --no-install-recommends curl unzip \
 && rm -rf /var/lib/apt/lists/* \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --optimize-autoloader || true
COPY . .
RUN composer dump-autoload --optimize --no-dev

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
