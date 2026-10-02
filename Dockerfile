# AVSCMS — imagem para Cloud Run.
#
# Espelha o PHP da VM (8.4 + mysqli/pdo_mysql/gd/intl/zip/opcache) para o app
# rodar sem mudança de código. O Cloud Run manda o tráfego em $PORT (8080),
# então o Apache escuta em 8080 (ver docker/avscms.conf).
FROM php:8.4-apache

# Dependências de build das extensões usadas pelo app (GD, ZIP, intl).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    for ext in mysqli pdo_mysql gd exif bcmath zip intl opcache; do \
        php -m | grep -qx "$ext" || docker-php-ext-install -j"$(nproc)" "$ext"; \
    done; \
    a2enmod rewrite headers expires deflate; \
    rm -rf /var/lib/apt/lists/*

# Opcache ligado com timestamps válidos (imagem imutável: o código só muda em
# novo deploy, então não precisa revalidar a cada request).
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=60'; \
    } > /usr/local/etc/php/conf.d/zz-avscms-opcache.ini

# Limites do upload de mídia iguais aos da VM (200M).
RUN { \
      echo 'upload_max_filesize=200M'; \
      echo 'post_max_size=200M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=0'; \
      echo 'output_buffering=0'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-avscms.ini

WORKDIR /var/www/html

# Só o código: mídia, cache e segredos ficam fora (.gcloudignore).
COPY . /var/www/html

# O rootfs do Cloud Run é em memória: cache do Smarty e tmp têm que morar em
# /tmp (o entrypoint recria os diretórios no boot de cada instância).
RUN set -eux; \
    rm -rf /var/www/html/cache /var/www/html/tmp /var/www/html/logs; \
    ln -s /tmp/avscms-cache /var/www/html/cache; \
    ln -s /tmp/avscms-tmp /var/www/html/tmp; \
    mkdir -p /tmp/avscms-cache /tmp/avscms-tmp/logs; \
    mkdir -p media/videos/vid media/videos/tmb media/videos/h264 media/videos/hd \
             media/videos/iphone media/videos/flv media/users media/albums; \
    chown -R www-data:www-data /var/www/html /tmp/avscms-cache /tmp/avscms-tmp; \
    rm -f /etc/apache2/sites-enabled/000-default.conf; \
    a2dissite 000-default >/dev/null 2>&1 || true

COPY docker/avscms.conf /etc/apache2/sites-available/avscms.conf
COPY docker/avscms-bots.conf /etc/apache2/conf-available/avscms-bots.conf
COPY docker/entrypoint.sh /usr/local/bin/avscms-entrypoint.sh

RUN set -eux; \
    a2ensite avscms; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    chmod +x /usr/local/bin/avscms-entrypoint.sh; \
    { echo 'ServerName pornozinho.com'; } > /etc/apache2/conf-available/avscms-servername.conf; \
    a2enconf avscms-servername

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/avscms-entrypoint.sh"]
CMD ["apache2-foreground"]
