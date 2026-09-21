FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libpq-dev \
        libsasl2-dev \
        libssl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli pdo_pgsql pgsql mbstring opcache \
    && pecl install redis mongodb \
    && docker-php-ext-enable redis mongodb \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/learn.conf \
    && a2enconf learn \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
