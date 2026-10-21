# Sử dụng image PHP 8.2 với Apache
FROM php:8.2-apache

# Cài đặt các extension cần thiết cho MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# QUAN TRỌNG: Cấu hình Apache lắng nghe trên cổng 10000 mà Render yêu cầu
RUN sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

# Sao chép toàn bộ code trong thư mục hiện tại vào thư mục web của Apache
COPY . /var/www/html/

# Cấp quyền cho thư mục web
RUN chown -R www-data:www-data /var/www/html

# Mở cổng 10000
EXPOSE 10000