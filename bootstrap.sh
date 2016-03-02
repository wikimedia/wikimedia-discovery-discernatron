#!/bin/bash

if [ ! -f /usr/bin/mysql ]; then
	export DEBIAN_FRONTEND=noninteractive
	debconf-set-selections <<< "mariadb-server-5.5 mysql-server/root_password password root"
	debconf-set-selections <<< "mariadb-server-5.5 mysql-server/root_password_again password root"
	
	apt-get update
	apt-get -y install apache2 libapache2-mod-php5 mariadb-server-5.5 php5-curl php5-mysql php5-cli
fi

# Setup the default app.ini if not already configured
if [ ! -f /vagrant/app.ini ]; then
	cp /vagrant/app.vagrant.ini /vagrant/app.ini
fi

# Initialize the database
echo "CREATE DATABASE IF NOT EXISTS relevance" | mysql -u root -proot 
mysql -u root -proot relevance < /vagrant/schema.mysql.sql

# Setup the apache2 configuration
cat > /etc/apache2/sites-available/000-default.conf <<EOD
<Directory />
	Options Indexes FollowSymLinks
	AllowOverride None
	Require all granted
</Directory>

<VirtualHost *:80>
	ServerAdmin webmaster@localhost
	DocumentRoot /vagrant/public

	ErrorLog \${APACHE_LOG_DIR}/error.log
	CustomLog \${APACHE_LOG_DIR}/access.log combined

	RewriteEngine on
	RewriteCond /vagrant/public/%{REQUEST_FILENAME} !-f
	RewriteCond /vagrant/public/%{REQUEST_FILENAME} !-d
	RewriteRule ^(.*)$ /index.php/$1 [L]
</VirtualHost>
EOD
a2enmod rewrite
service apache2 reload

# The JWT lib really doesn't like the clock being off, even by 15s or so,
# So lets make sure it's reasonable
ntpdate-debian

# setup composer
if [ ! -f /usr/local/bin/composer ]; then
	curl -s https://getcomposer.org/download/1.0.0-beta1/composer.phar > /usr/local/bin/composer
	if ! echo "4344038a546bd0e9e2c4fa53bced1c7faef1bcccab09b2276ddd5cc01e4e022a  /usr/local/bin/composer" | sha256sum -c; then
		rm /usr/local/bin/composer
		exit 1
	fi
	chmod +x /usr/local/bin/composer
fi

cd /vagrant
/usr/local/bin/composer install

# Make sure apache can write to the cache directory
# For a prod deployment it is better to pre-build the twig's
# and not have anything writable by www-data
sudo chown -R www-data /vagrant/cache/
