# Image de TomTroc : PHP 8.3 + Apache, sans root, écoute sur le port 8080.
# Construite depuis la racine du dépôt :
#   docker build -f docker/Dockerfile -t tomtroc .
FROM php:8.3-apache-bookworm

# extensions PHP : pdo_mysql pour la base, redis pour stocker les sessions
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# mod_rewrite pour le .htaccess, mod_headers pour l'en-tête X-App-Version, port 8080
# (un utilisateur non-root ne peut pas ouvrir un port < 1024), PHP de production
RUN a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini $PHP_INI_DIR/conf.d/tomtroc.ini

# sessions en fichiers par défaut ; Kubernetes et compose passent à Redis.
# Cookie de session "Secure" (envoyé seulement en HTTPS) : activé dans Kubernetes.
ENV SESSION_SAVE_HANDLER=files \
    SESSION_SAVE_PATH=/tmp \
    SESSION_COOKIE_SECURE=0

# version de l'image, renvoyée dans l'en-tête X-App-Version (A/B testing, canary)
#   docker buildx build --build-arg APP_VERSION=1.0.2 ...
ARG APP_VERSION=dev
ENV APP_VERSION=$APP_VERSION

# le code appartient à root : www-data peut le lire mais pas le modifier
WORKDIR /var/www/html
COPY app/src src
COPY app/public public
COPY docker/config.php config.php

# seul dossier où Apache peut écrire : les images envoyées par les membres
RUN mkdir -p public/uploads && chown www-data:www-data public/uploads

USER www-data
EXPOSE 8080
