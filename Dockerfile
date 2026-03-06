# Dockerfile
FROM hyperf/hyperf:8.4-alpine-v3.22-swoole-slim

LABEL maintainer="Bruno Aurélio Rovela <bruno.rovela@principia.net>"

# Na Slim, precisamos instalar os drivers de banco que o Hyperf/Cycle vão usar
RUN apk add --no-cache php84-pdo_mysql php84-bcmath php84-intl

WORKDIR /opt/www

# Ajustes de performance do PHP
RUN ln -sf /usr/bin/php84 /usr/bin/php \
    && echo "memory_limit=1G" > /etc/php84/conf.d/00_default.ini

# Copia o código e instala dependências
COPY . /opt/www
# RUN composer install --no-dev --optimize-autoloader

RUN git config --global --add safe.directory /opt/www

EXPOSE 9501

# ENTRYPOINT sem "start" para o compose poder sobrescrever com command: ["server:watch"]
ENTRYPOINT ["php", "/opt/www/bin/hyperf.php"]

CMD ["start"]