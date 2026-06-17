# ============================================================
#  Imagen para Cloud Run — PHP 8.2 + Apache
#  El portal se sirve en la raíz del contenedor (/var/www/html).
#  Cloud Run inyecta el puerto en $PORT (8080 por defecto).
# ============================================================
FROM php:8.2-apache

# Librerías del sistema que necesitan algunas extensiones de PHP
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libonig-dev \
 && rm -rf /var/lib/apt/lists/*

# Extensiones que usa el portal:
#   pdo_mysql/mysqli -> BD;  zip -> ZipArchive (openspout/XLSX);  mbstring -> mb_*
RUN docker-php-ext-install pdo_mysql mysqli zip mbstring \
 && a2enmod rewrite headers

# Permitir que los .htaccess del proyecto tomen efecto
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Ajustes de PHP para producción (sin mostrar errores al usuario, subidas razonables)
RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'memory_limit=256M'; \
      echo 'date.timezone=America/Mexico_City'; \
    } > /usr/local/etc/php/conf.d/zz-portal.ini

# Código de la aplicación
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

ENV PORT=8080
EXPOSE 8080

# Apache debe escuchar en el puerto que pida Cloud Run ($PORT).
# Sustituimos el puerto en arranque y levantamos Apache en primer plano.
CMD ["sh", "-c", "sed -i \"s/Listen 80$/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
