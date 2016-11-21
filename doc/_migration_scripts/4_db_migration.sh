#/bin/bash
#
# export/import (new DB has not the same charset !)
# tables : group user section user_subscr user_status user_flag node

export $DBPASSWD=soleil; # No, it's NOT my real password

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 group | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 section | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_subscr | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_status | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset  --skip-extended-insert --complete-insert=TRUE \
    --lock-tables=false --skip-add-locks --extended-insert=FALSE --skip-comments \
    agora2 user_flag | mysql -uroot -p$DBPASSWD agora3

time mysqldump -uroot -p$DBPASSWD --no-create-info --skip-set-charset --complete-insert=TRUE --lock-tables=false \
    --skip-add-locks --skip-comments --where "1=1 ORDER BY cdate asc" -r temporary.sql agora2 node \
&& time mysql -uroot -p$DBPASSWD agora3 < temporary.sql > /dev/null \
&& rm temporary.sql

exit # (in case of capy/pate...)
