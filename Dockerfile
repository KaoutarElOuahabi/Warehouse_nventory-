FROM php:8.2-cli

WORKDIR /app

COPY . /app

RUN docker-php-ext-install pdo pdo_mysql

EXPOSE 8080

ENV PORT=8080

CMD ["bash", "-lc", "php -S 0.0.0.0:${PORT} -t public"]
