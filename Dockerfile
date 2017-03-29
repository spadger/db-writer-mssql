FROM quay.io/keboola/docker-base-php56:0.0.2
MAINTAINER Miroslav Cillik <miro@keboola.com>

RUN yum -y --enablerepo=epel,remi,remi-php56 install unixODBC unixODBC-devel msodbcsql php-mssql php-common php-pecl-xdebug

# FreeTDS driver
ADD driver/freetds.conf /etc/freetds.conf

# Initialize
ADD . /code
WORKDIR /code

RUN composer selfupdate
RUN composer install --no-interaction

CMD php ./run.php --data=/data
