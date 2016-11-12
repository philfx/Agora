#/bin/bash
#
# at this step, you have already :
# 1. create agora2, a copy of the original agora1, and 
#    - modified it with db_old_to_new_agora.sql 
#    - php DataHandler::Migration::migrate($dbh)
# 2. created agora3, and the table structure with db_create.sql
#
# user root can read/write on both.

# get the data from the server (for dev env)
# service mysql stop
# cd /var/lib/mysql/agora1/
# scp -p root@www.uniag.ch:/var/lib/mysql/agora1/* .
# service mysql start

# export/import (new DB has not the same charset !)
# tables : group user section user_subscr user_status user_flag node

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 group | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 section | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_subscr | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_status | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_flag | mysql -uroot -psoleil agora3

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --skip-comments    -r temporary.sql agora2 node \
&& time mysql -uroot -psoleil agora3 < temporary.sql > /dev/null \
&& rm temporary.sql

-------------------

time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 node | mysql -uroot -psoleil agora3




time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    -r ex1.sql agora2 group user section user_subscr user_status
time mysqldump -uroot -psoleil --no-create-info --skip-set-charset --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --skip-comments    -r ex2.sql agora2 node
time mysqldump -uroot -psoleil --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments    -r ex3.sql agora2 user_flag

time mysql -uroot -psoleil agora3 < ex1.sql > /dev/null
time mysql -uroot -psoleil agora3 < ex2.sql > /dev/null
time mysql -uroot -psoleil agora3 < ex3.sql > /dev/null

rm ex1.sql ex2.sql ex3.sql






#First step : 
#Create a user in the db and set passwd to sha1(passwd,passwd_salt)

