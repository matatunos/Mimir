FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libzip-dev zip unzip libpng-dev libonig-dev libxml2-dev libldap2-dev libsasl2-dev git \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install pdo_mysql zip gd ldap opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install composer (copy from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# If composer.json exists, try to install vendors (mounts in dev will override)
COPY composer.json composer.lock* /var/www/html/
RUN if [ -f /var/www/html/composer.json ]; then composer install --no-interaction --no-dev --optimize-autoloader || true; fi

# Configure Apache DocumentRoot to point to the public directory
RUN sed -ri "s!DocumentRoot /var/www/html!DocumentRoot ${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf \
 && grep -q "ServerName" /etc/apache2/sites-available/000-default.conf || sed -i "/DocumentRoot/a\
    ServerName localhost" /etc/apache2/sites-available/000-default.conf

# Add a directory config for the public directory to allow overrides and indexes
RUN printf '%s\n' "<Directory ${APACHE_DOCUMENT_ROOT}>" \
 "    Options Indexes FollowSymLinks" \
 "    AllowOverride All" \
 "    Require all granted" \
 "    DirectoryIndex index.php index.html" \
 "</Directory>" > /etc/apache2/conf-available/mimir.conf \
 && a2enconf mimir

# Ensure ServerName is set to avoid Apache warning about FQDN
RUN printf '%s\n' "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# Ensure permissions for storage and uploads
RUN mkdir -p /var/www/html/storage /var/www/html/storage/uploads \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/public || true

EXPOSE 80

CMD ["apache2-foreground"]
