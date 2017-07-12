FROM colinodell/php-7.2:apache
RUN docker-php-ext-install pdo pdo_mysql bcmath
RUN curl https://getcomposer.org/download/1.3.2/composer.phar > /usr/local/bin/composer && chmod +x /usr/local/bin/composer
