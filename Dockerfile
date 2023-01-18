FROM php:8.1-alpine
COPY --from=composer /usr/bin/composer /usr/bin/composer
