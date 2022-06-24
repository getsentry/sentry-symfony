FROM php:7.3-alpine

RUN apk --no-cache add \
        $PHPIZE_DEPS \
        curl \
        git \
        zsh \
        openssh-client \
        supervisor \
        sudo \
        less \
        vim \
        nano \
        libzip-dev \
    && docker-php-ext-install -j5 zip pcntl \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

#COMPOSER
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN adduser -u 1000 -G wheel -D sentry -s /bin/zsh
USER sentry

WORKDIR /home/sentry/projects

#Zsh minimal installation
RUN sh -c "$(curl -fsSL https://raw.githubusercontent.com/ohmyzsh/ohmyzsh/master/tools/install.sh)"

ENV TERM xterm-256color
