FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git unzip libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

COPY . .

RUN chown -R www-data:www-data /var/www/html

RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    ServerName localhost' \
    '    DocumentRoot /var/www/html/public' \
    '' \
    '    <Directory /var/www/html/public>' \
    '        Options -Indexes' \
    '        AllowOverride None' \
    '        Require all granted' \
    '' \
    '        RewriteEngine On' \
    '        RewriteCond %{REQUEST_FILENAME} !-f' \
    '        RewriteCond %{REQUEST_FILENAME} !-d' \
    '        RewriteRule ^ index.php [QSA,L]' \
    '    </Directory>' \
    '' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$c=@file_get_contents("http://127.0.0.1/health"); if($c===false) exit(1);'
