# GIT address
https://gist.github.com/philfx/ea476f0d31784849c500

# GitHub : origin (local), master (github)
# https://github.com/philfx/Agora
cd /home/www/uniag.ch/aora/rest && git push -f origin master

# Git - install AgoraRest from git
cd /home/www/uniag.ch/aora
git clone  https://github.com/philfx/Agora rest


# tester à la main
php -S localhost:8080 -t /home/www/uniag.ch/aora/rest/

# logs
cd /home/www/uniag.ch/aora/rest && tail -f  /var/log/apache2/error.log -f logs/logs.txt

# backup
rdiff-backup -v 2 --print-statistics --exclude /home/www/uniag.ch/aora/rest/logs /home/www/uniag.ch/aora/rest root@www.uniag.ch::/root/rest_backup/

