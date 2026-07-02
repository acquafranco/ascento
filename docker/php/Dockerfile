FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    default-mysql-client

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

RUN docker-php-ext-install gd

RUN docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl

RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
