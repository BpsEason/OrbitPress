FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    librabbitmq-dev \
    zlib1g-dev \
    libxml2-dev \
    gnupg \
    --no-install-recommends && rm -rf /var/lib/apt/lists/*

# 安裝 PHP 擴展
RUN docker-php-ext-install pdo_pgsql mbstring zip exif pcntl gd intl
RUN docker-php-ext-enable pdo_pgsql

# 安裝 MongoDB 驅動
RUN pecl install mongodb && docker-php-ext-enable mongodb

# 安裝 AMQP 用於 RabbitMQ
RUN pecl install amqp && docker-php-ext-enable amqp

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /var/www/html

# 暴露 80 端口用於 Nginx/FastAPI，9000 端口用於 Prometheus 指標
EXPOSE 80 9000

# 啟動 PHP-FPM
CMD ["php-fpm"]
