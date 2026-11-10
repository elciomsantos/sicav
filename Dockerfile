FROM php:8.4-apache

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

LABEL maintainer="Sistema de Visitantes <dev@example.com>"
LABEL description="Sistema de Gerenciamento de Visitantes"

ARG ENVIRONMENT=production

RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
        git \
        curl \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        gd \
        zip \
    && docker-php-ext-enable pdo_mysql gd zip

RUN set -eux; \
    a2enmod rewrite headers expires; \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

COPY . /var/www/html/

RUN set -eux; \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    if [ "$ENVIRONMENT" = "production" ]; then \
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; \
    else \
        composer install --no-interaction --prefer-dist; \
    fi; \
    rm -f /usr/local/bin/composer

# Install Node.js for JS tests
RUN set -eux; \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y --no-install-recommends nodejs && \
    rm -rf /var/lib/apt/lists/*

# Run tests
RUN set -eux; \
    if [ "$ENVIRONMENT" != "production" ]; then \
        vendor/bin/phpunit; \
        find public/assets/js -name '*.js' -print0 | xargs -0 -n1 node --check; \
    fi

RUN set -eux; \
    if [ "$ENVIRONMENT" = "production" ]; then \
        rm -f /var/www/html/.env; \
        rm -rf /var/www/html/node_modules /var/www/html/db/*.sh; \
        find /var/www/html -name "*.md" -delete; \
    fi

RUN set -eux; \
    groupadd --gid 1000 app && \
    useradd --uid 1000 --gid app --no-create-home --home-dir /var/www/html --shell /usr/sbin/nologin app && \
    sed -ri 's/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=app/; s/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=app/' /etc/apache2/envvars && \
    mkdir -p /var/www/html/fotos_visitantes && \
    chown -R app:app /var/www/html && \
    chmod 755 /var/www/html/fotos_visitantes

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

USER app

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
