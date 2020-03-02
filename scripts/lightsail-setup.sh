#### Use with 'Launch Script' when creating instance in AWS LightSail

## Set Variables!! You MUST set these!!

# http://<YOUR DEV URL>.ngrok.io, e.g. http://dev-jdoe-magento.ngrok.io
NS8_PROTECT_URL=https://test-protect.ns8.com
# http://<YOUR NS8 PROTECT CLIENT APP DEV URL>.ngrok.io, e.g. https://david-local-protect.ngrok.io/#
NS8_CLIENT_URL=https://test-protect-client.ns8.com
# <YOUR DEV DOMAIN>, e.g. http://dev-jdoe-Magento-2.3.2.ns8demos.com/
DEV_LIGHTSAIL_DOMAIN=
# Your desired SSH password for the "ec2-user" account.
SSH_PASSWORD=
# Set this variable to enable XDEBUG
DEBUG=

MAGENTO_VERSION=2.3.4

## BEGIN--SCRIPT ##
# Magento
# Create SWAP file; 2GB
dd if=/dev/zero of=/swapfile bs=1M count=2048
mkswap /swapfile
chmod 600 /swapfile
swapon /swapfile
echo "swap /swapfile swap defaults 0 0" >> /etc/fstab

# Add nodejs repository
curl -sL https://rpm.nodesource.com/setup_10.x | bash -
# Update packages
yum update -y
# Install LAMP
yum install -y \
fail2ban \
git \
httpd24 \
nodejs \
php73 \
php73-bcmath \
php73-gd \
php73-intl \
php73-mbstring \
php73-mcrypt \
php73-mysqlnd \
php73-soap \
php73-zip \
mysql57-server
# Configure Web and DB servers to start on boot
chkconfig httpd on
chkconfig mysqld on
# Change PHP memory limit // Magento wants > 2GB
sed -i 's/memory_limit = 128M/memory_limit = 4096M/g' /etc/php-*.ini
# Change Apache to allow Overrides
sed -i '151s/None/All/' /etc/httpd/conf/httpd.conf
# Set Composer path
echo "COMPOSER_HOME=/var/www/html/var/composer_home" >> /etc/environment
# Set NS8_PROTECT_URL environment variable
echo "SetEnv NS8_CLIENT_URL $NS8_CLIENT_URL" >> /var/www/html/.htaccess
echo "SetEnv NS8_PROTECT_URL $NS8_PROTECT_URL" >> /var/www/html/.htaccess

# Set file permissions (Force the group apache on all files and give RWX permissions)
chown -R apache:apache /var/www/html
chmod -R 2775 /var/www/html
setfacl -Rdm g:apache:rwx /var/www/html
# Start Web and DB server
service httpd start
service mysqld start
# Create database
mysql -u root -e "CREATE DATABASE magento2"
mysql -u root -e "CREATE USER 'magento_db_user'@'localhost' IDENTIFIED BY 'magento_db_password'"
mysql -u root -e "GRANT ALL PRIVILEGES ON magento2.* TO 'magento_db_user'@'localhost'"
mysql -u root -e "FLUSH PRIVILEGES"

# Download installer with all sample data
cd /var/www && curl -O https://MAG005397149:2f7e27231024a6cbc3e075f5a74b8264e6badb56@www.magentocommerce.com/products/downloads/file/Magento-CE-${MAGENTO_VERSION}_sample_data.zip
# Unzip Magento files to web root
sudo -u apache unzip -qq /var/www/Magento-* -d /var/www/html
# Install Magento via CLI
# NOTE: You MUST modify `base-url` to point to your own subdomain
sudo -u apache php /var/www/html/bin/magento setup:install \
--language=en_US \
--timezone=America/Los_Angeles \
--db-name=magento2 \
--db-user=magento_db_user \
--db-password=magento_db_password \
--backend-frontname=admin_demo \
--admin-firstname=Development \
--admin-lastname=Testing \
--admin-email=dev@ns8demos.com \
--admin-user=development \
--admin-password=YzbLenbGRGN6fxqNsz.ti \
--base-url=$DEV_LIGHTSAIL_DOMAIN
# Setup Magento CRON jobs
sudo -u apache php /var/www/html/bin/magento cron:install
# Set Magento Admin password to not expire
sudo -u apache php /var/www/html/bin/magento config:set admin/security/password_lifetime 0
# Disable all the CAPTCHAs
echo "INSERT INTO core_config_data SET path = 'admin/captcha/enable', value = 0;" | mysql -u root magento2
sudo -u apache /var/www/html/bin/magento msp:security:recaptcha:disable

# Update Composer Auth
sudo -u apache cp /var/www/html/auth.json.sample /var/www/html/auth.json
#### Need to remove git section
## remove lines with 'github*'
## remove lines with '},'
## OR remove line with 'github-oauth' and the following 2 lines (3 lines in total)
sed -i '/github/d' /var/www/html/auth.json
sed -i '/},$/d' /var/www/html/auth.json
sed -i 's/<public-key>/1b8325eb6d792fe22c0fb83f65150281/' /var/www/html/auth.json
sed -i 's/<private-key>/d68ff7618b2f3118a0342d7f914848c8/' /var/www/html/auth.json

# Create the directory where we will test the Protect extension
sudo -u apache mkdir -p /var/www/html/app/code/NS8/Protect

# Install Protect SDK via composer
cd /var/www/html
sudo -u apache php -d memory_limit=-1 /var/www/html/vendor/composer/composer/bin/composer require ns8/protect-sdk

# Update environment to utilize development environment if the module is installed
sed -i "s/\"default_environment\": \"production\"/\"default_environment\": \"development\"/" /var/www/html/vendor/ns8/protect-sdk/assets/configuration/core_configuration.json
sed -i "s^\"api_url\": \"https://test-protect.ns8.com\"^\"api_url\": \"$NS8_PROTECT_URL\"^" /var/www/html/vendor/ns8/protect-sdk/assets/configuration/core_configuration.json
sed -i "s^\"client_url\": \"https://test-protect-client.ns8.com\"^\"client_url\": \"$NS8_CLIENT_URL\"^" /var/www/html/vendor/ns8/protect-sdk/assets/configuration/core_configuration.json

# Add Protect (CSP) Module
# cd /var/www/html
## Following command will run php with no memory limit (not necessary if set high enough in a php.ini file)
#sudo -u apache php -d memory_limit=-1 /var/www/html/vendor/composer/composer/bin/composer require ns8/csp
#Create the directory where we will test the Protect extension
#sudo -u apache mkdir -p /var/www/html/app/code/NS8/Protect

#Make the magento command executable
sudo chmod +x /var/www/html/bin/magento

# Allow access in root html directory
sudo chmod -R ugo+rwx /var/www/html/

# TODO: finish this
## The following 3 lines will (eventually) install Protect via the Magento Marketplace
#sudo -u apache php /var/www/html/vendor/composer/composer/bin/composer require ns8/protect
#sudo -u apache php /var/www/html/bin/magento module:enable NS8_Protect
#sudo -u apache php /var/www/html/bin/magento setup:upgrade
## The following lines will (eventually) download and install a Magento Module manually
# sudo -u apache wget -O /var/www/html/app/ns8-module.zip https://ns8.s3.amazonaws.com/builds/TBD
# This assumes the module root is at the root of the zip file. If they are in a subfolder, you'll need to handle that
# sudo -u apache unzip /var/www/html/app/ns8-module.zip -d /var/www/html/app/code/NS8/Protect
# sudo -u apache php /var/www/html/bin/magento setup:upgrade
# rm /var/www/html/app/ns8-module.zip
# Remove Composer Auth
#rm /var/www/html/auth.json.sample
#rm /var/www/html/auth.json

# Configure AdminAuthLog+fail2ban to block anyone who uses invalid login credentials 10 times in 1 hour for 1 day
sudo -u apache git clone https://github.com/crankycyclops/AdminAuthLog.git /var/www/html/app/code/Crankycyclops/AdminAuthLog
sudo -u apache /var/www/html/bin/magento setup:upgrade
ln -s /var/www/html/app/code/Crankycyclops/AdminAuthLog/fail2ban/filter.d/magento-adminauthlog.conf /etc/fail2ban/filter.d/magento.conf
sudo -u apache mkdir -p /var/www/html/var/log/crankycyclops/adminauthlog
sudo -u apache touch /var/www/html/var/log/crankycyclops/adminauthlog/auth.log
echo '
[magento]
action = iptables[name=Magento, port="http"]
bantime = 86400
enabled = true
filter = magento
findtime = 3600
logpath = /var/www/html/var/log/crankycyclops/adminauthlog/auth.log
maxretry = 10
' >> /etc/fail2ban/jail.conf
chkconfig fail2ban on
service fail2ban start

# Setup xdebug
if [ -n "${DEBUG}" ]; then
  sudo -u apache /var/www/html/bin/magento deploy:mode:set developer

  sed -i "s/#\(ini_set('display_errors', 1)\)/\1/" /var/www/html/app/bootstrap.php
  mv /var/www/html/pub/errors/local.xml.sample /var/www/html/pub/errors/local.xml

  sudo yum -y install php7-pear php73-devel gcc
  sudo pecl7 channel-update pecl.php.net
  sudo pecl7 install xdebug

  cp /etc/php.ini /tmp
  cat >> /tmp/php.ini << EOF
[xdebug]
zend_extension=/usr/lib64/php/7.3/modules/xdebug.so
xdebug.remote_enable = 1
xdebug.remote_port = 9000
xdebug.remote_autostart = 1
xdebug.remote_host = 127.0.0.1
xdebug.remote_connect_back = 0
EOF
  sudo mv /tmp/php.ini "$(realpath /etc/php.ini)"

  echo 'fs.inotify.max_user_watches=524288' | sudo tee -a /etc/sysctl.conf > /dev/null
  sudo sysctl -p

  cat >> ~/.bashrc << EOF
alias magento="sudo -u apache /var/www/html/bin/magento"
alias composer="sudo -u apache /var/www/html/vendor/composer/composer/bin/composer"
alias req-dev-php-sdk="(cd /var/www/html && composer require ns8/protect-sdk dev-dev)"
EOF
fi

# Clear the Magento caches
sudo -u apache /var/www/html/bin/magento cache:clean

# Configure SSH
echo "$SSH_PASSWORD" | passwd --stdin ec2-user
sed -i 's/PasswordAuthentication no/PasswordAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/#Port 22/Port 65422/' /etc/ssh/sshd_config
service sshd restart

## END--SCRIPT ##
