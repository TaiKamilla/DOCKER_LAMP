## image
FROM php:7.3.6-apache-stretch

## envs
ENV INSTALL_DIR /var/www/html

## install composer
RUN curl -sS https://getcomposer.org/installer | php \
&& mv composer.phar /usr/local/bin/composer

## install libraries
RUN requirements="vim sudo mlocate git-core curl build-essential openssl libssl-dev nodejs mysql-client git cron libpng-dev libmcrypt-dev libmcrypt4 libcurl3-dev libfreetype6 libjpeg62-turbo libjpeg62-turbo-dev libfreetype6-dev libicu-dev libxslt1-dev libzip-dev zip" \
 && apt-get update \
 && apt-get install -y $requirements
 
RUN rm -rf /var/lib/apt/lists/* \
 && apt-get update -yq \
 && apt-get install curl gnupg -yq \
 && curl -sL https://deb.nodesource.com/setup_14.x | bash \
 && apt-get install nodejs -yq
 
RUN docker-php-ext-install pdo_mysql \
 && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
 && docker-php-ext-install gd \
 && yes '' | pecl install mcrypt-1.0.2
 
RUN docker-php-ext-enable mcrypt \
 && docker-php-ext-install mbstring \ 
 && docker-php-ext-install zip \
 && docker-php-ext-install intl \
 && docker-php-ext-install xsl \
 && docker-php-ext-install soap \
 && docker-php-ext-install bcmath

 RUN apt-get autoremove -y \
 && apt-get clean

## copy configs files
COPY config/000-default.conf /etc/apache2/sites-available/

## turn on mod_rewrite
RUN a2enmod rewrite

## set memory limits
RUN echo "memory_limit=2048M" > /usr/local/etc/php/conf.d/memory-limit.ini

## clean apt-get
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

## www-data should own /var/www
RUN chown -R www-data:www-data /var/www/html

# ## switch user to www-data
# USER www-data

# ## copy sources with proper user
# COPY --chown=www-data code $INSTALL_DIR

# ## set working dir
# WORKDIR $INSTALL_DIR

# RUN composer global require hirak/prestissimo
# # RUN ln -s /var/www/html/public/bundles/storefront/assets public/assets
# RUN chown -R www-data /var/www/html
# RUN chgrp -R www-data /var/www/html
# RUN composer install
# RUN composer config
# # RUN bin/setup -n -vvv
# RUN chown -R www-data /var/www/html
# RUN chgrp -R www-data /var/www/html
# # RUN chmod 600 /var/www/html/config/jwt/private.pem 
# # RUN chmod 600 /var/www/html/config/jwt/public.pem 

# ## switch back
# USER root

## run cron alongside apache
CMD [ "sh", "-c", "cron && apache2-foreground" ]

