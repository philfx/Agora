# mysql version must be 5.6 or greater, for FULLTEXT indexing and search


# install composer, local, for php
php -r "readfile('https://getcomposer.org/installer');" > composer-setup.php
php -r "if (hash('SHA384', file_get_contents('composer-setup.php')) === '41e71d86b40f28e771d4bb662b997f79625196afcca95a5abf44391188c695c6c1456e16154c75a211d238cc3bc5cb47') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# install slim, monolog
php composer.phar require slim/slim
php composer.phar require monolog/monolog
php composer.phar require akrabat/rka-ip-address-middleware

# testing with postman
Install Postman
    (here) https://chrome.google.com/webstore/detail/postman/fhbjgbiflinjbdggehcddcbncdddomop?hl=en
Install PostmanInterceptor (because Chrome restrict headers) : 
    (here) https://chrome.google.com/webstore/detail/postman-interceptor/aicmkgpgakddgnaphhhpliifpcfhicfo
If cookies problem with Postman and token cookies test, remove them manually : chrome://settings/cookies

Postman shared : https://www.getpostman.com/collections/cfa406693cb246b8f4e4


# Configure apache :

vi /etc/apache2/sites-enabled/000-default.conf
        <Directory /var/www/html/agora3/>
         <IfModule mod_rewrite.c>
          RewriteEngine On

          RewriteBase /rest ## ??? /agora3/rest ???
          RewriteCond %{REQUEST_FILENAME} !-f
          RewriteRule ^(.*)$ index.php [QSA,L]

         </IfModule>
        </Directory>

/etc/init.d/apache2 restart

# testing - reset all users passwd to 'aaa'
UPDATE  `user` SET  `passwd` = "aaa" ; 
UPDATE  `user` SET  `passwd` = SHA1( CONCAT( passwd, passwd_salt ) ) ; 
UPDATE `user` SET `username` = 'dosh' WHERE `user`.`uid` = 4 ;
# login dosh
UPDATE `user_token` SET `token` = 'ef9286' WHERE `user_token`.`token` = 'b9ee01';



# Codiad
# https://www.digitalocean.com/community/tutorials/how-to-install-and-configure-codiad-a-web-based-ide-on-an-ubuntu-vps
# plugins : http://market.codiad.com/

# Postman
# configure postman
# Set environnement, variable baseurl --> http://localhost/aora
# use it as {{baseurl}}/rest/_migrate/stage0