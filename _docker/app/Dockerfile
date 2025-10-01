FROM php:8.2-fpm

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    libmemcached-dev \
    zlib1g-dev \
    gnupg \
    libcurl4-openssl-dev \
    apt-utils \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    zip unzip \
    git \
    cron \
    htop \
    libkrb5-dev \
    libssl-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install gd \
    && docker-php-ext-install zip \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Установка и конфигурация PHP расширений
# Если не нужна поддержка Kerberos и SSL для IMAP, используйте следующую команду:
# RUN docker-php-ext-configure imap && docker-php-ext-install imap pdo_mysql bcmath gd zip curl

RUN docker-php-ext-install pdo_mysql bcmath gd zip curl

# Добавление команды планировщика Laravel в Crontab
RUN echo "* * * * * /usr/local/bin/php /var/www/artisan schedule:run >> /var/log/cron.log 2>&1" | crontab -

# Установка Node.js (например, через NodeSource)
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs

# Копирование только package.json для установки зависимостей
COPY package*.json ./

# Установка зависимостей Node.js (npm или yarn)
RUN npm install


# Установка Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- \
    --filename=composer \
    --install-dir=/usr/local/bin

# Копирование настроек PHP
COPY ./_docker/app/php.ini /usr/local/etc/php/conf.d/php.ini

WORKDIR /var/www

CMD ["sh", "-c", "pkill cron; cron -f & php-fpm"]
