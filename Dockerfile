# Sử dụng image PHP 8.2 với Apache
FROM php:8.2-apache

# Cài đặt các extension cần thiết cho MySQL (rất có thể dự án của bạn cần)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Sao chép toàn bộ code trong thư mục hiện tại vào thư mục web của Apache
COPY . /var/www/html/

# Cấp quyền cho thư mục web (tùy chọn, giúp tránh lỗi phân quyền)
RUN chown -R www-data:www-data /var/www/html

# Mở cổng 80 (cổng mặc định của Apache)
EXPOSE 80