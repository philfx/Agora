SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: `agora2`
--

-- misc cleaning, drop future unused tables and colons
DROP TABLE `chatmsg`, `chatroom`, `chatusers`, `nbseq`, `skins`, `webnotes`;
ALTER TABLE `agroup` DROP `security`, DROP `vars`, DROP `chat`, DROP `webnotes`;
ALTER TABLE `section` DROP `access`;
ALTER TABLE `users` DROP `skin`;
ALTER TABLE `threads` DROP `attach`;
ALTER TABLE `users` DROP `noframe`, DROP `logout_redir`, DROP `nb_webnotes`;
ALTER TABLE `users` DROP `webnote_search`, DROP `webnote_own`, DROP `webnote_author`, DROP `webnote_body`;

-- prepare table group
RENAME TABLE `agroup` TO `group` ;
ALTER TABLE  `group` CHANGE  `num`  `gid` INT( 11 );
ALTER TABLE  `group` CHANGE  `name`  `groupname` CHAR(64);
ALTER TABLE  `group` CHANGE  `descr`  `groupdescr` TEXT;
UPDATE `group` SET `gid`=2 WHERE gid=1;
UPDATE `group` SET `gid`=1 WHERE gid=0;
UPDATE `section` SET `ngroup`=2 WHERE ngroup=1;
UPDATE `section` SET `ngroup`=1 WHERE ngroup=0;
UPDATE `status` SET `ngroup`=2 WHERE ngroup=1;
UPDATE `status` SET `ngroup`=1 WHERE ngroup=0;
UPDATE `users` SET `ngroup`=2 WHERE ngroup=1;
UPDATE `users` SET `ngroup`=1 WHERE ngroup=0;
UPDATE `threads` SET `ngroup`=2 WHERE ngroup=1;
UPDATE `threads` SET `ngroup`=1 WHERE ngroup=0;

-- prepare table section
ALTER TABLE  `section` CHANGE  `num`  `sid` INT( 11 );
ALTER TABLE  `section` CHANGE  `ngroup`  `gid` INT( 11 );
ALTER TABLE  `section` CHANGE  `descr`  `sectiondescr` TEXT;
UPDATE `section` SET `selfsub`='y' WHERE selfsub=1;
UPDATE `section` SET `selfsub`=null WHERE selfsub not like 'y';
ALTER TABLE  `section` CHANGE  `selfsub`  `self_sub` CHAR( 1 );
ALTER TABLE  `section` CHANGE  `name`  `sectionname` CHAR(64);
UPDATE `section` SET `self_sub`='n' WHERE `self_sub` is null;
ALTER TABLE `section` ADD `display_pos` SMALLINT NOT NULL DEFAULT '1' AFTER `self_sub`;
UPDATE `section` SET `display_pos`=1;

-- prepare table user
RENAME TABLE `users` TO `user` ;
-- change time from int to unixtime and change the column's type
ALTER TABLE `user` ADD `lastdel` INT;
UPDATE `user` SET lastdel=lastuse;
ALTER TABLE `user` CHANGE `lastuse` `last_post` DATETIME NULL DEFAULT '1000-01-01 00:00:00';
UPDATE `user` SET last_post=FROM_UNIXTIME(lastdel);
ALTER TABLE  `user` DROP  `lastdel`;
-- change time from int to unixtime and change the column's type
ALTER TABLE `user` ADD `lastdel` INT;
UPDATE `user` SET lastdel=last_login;
ALTER TABLE `user` CHANGE `last_login` `last_login` DATETIME NULL DEFAULT '1000-01-01 00:00:00';
UPDATE `user` SET last_login=FROM_UNIXTIME(lastdel);
ALTER TABLE  `user` DROP  `lastdel`;
-- SHA1 encrypt with salt
ALTER TABLE  `user` DROP  `md5check` ;
ALTER TABLE user ADD passwd_salt CHAR(8);
ALTER TABLE `user` CHANGE `passwd` `passwd` CHAR(64);
UPDATE user SET passwd_salt = SHA1(RAND());
-- DONT ONLY FOR TESTING - 
UPDATE `user` SET `passwd`= 'aaa';
UPDATE `user` SET `passwd`=sha1(concat(passwd,passwd_salt));
ALTER TABLE  `user` CHANGE  `admin`  `role` CHAR(1);
UPDATE `user` SET role='s' WHERE role = '1';
UPDATE `user` SET role='u' WHERE role = '0' OR role='' OR role is null;
ALTER TABLE  `user` CHANGE  `name`  `realname` CHAR(64);
ALTER TABLE  `user` CHANGE  `login`  `username` CHAR(64);
UPDATE `user` SET role='a' WHERE username='admin';
ALTER TABLE  `user` CHANGE  `ngroup`  `gid` INT( 11 );
ALTER TABLE  `user` CHANGE  `num`  `uid` INT( 11 );
UPDATE  `user` SET  `language` =  'en';

-- prepare table node
-- convert time from int to unixtime
RENAME TABLE  `threads` TO  `node` ;
ALTER TABLE node ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST;
ALTER TABLE `node` ADD `lastdel` INT;
UPDATE `node` SET lastdel=cdate;
ALTER TABLE `node` CHANGE `cdate` `cdate` DATETIME NULL DEFAULT '1000-01-01 00:00:00';
UPDATE `node` SET cdate=FROM_UNIXTIME(lastdel);
ALTER TABLE  `node` DROP  `lastdel`;
ALTER TABLE  `node` CHANGE  `name`  `realname` CHAR(64);
ALTER TABLE  `node` CHANGE  `num`  `nid` INT( 11 );
ALTER TABLE  `node` CHANGE  `section`  `sid` INT( 11 );
ALTER TABLE  `node` CHANGE  `ipaddr`  `ipaddress` VARCHAR( 15 ) ;
ALTER TABLE  `node` CHANGE  `parent`  `pid` INT( 11 );
ALTER TABLE  `node` CHANGE  `thrnum`  `tid` INT( 11 );
ALTER TABLE  `node` DROP  `ngroup` ; 
ALTER TABLE  `node` CHANGE  `erased`  `state` CHAR( 1 );
UPDATE `node` SET state='r' WHERE state='y';
UPDATE `node` SET state='p' WHERE state='' OR state IS null;
ALTER TABLE  `node` CHANGE  `author`  `uid` INT( 11 );

-- prepare table user_statut
RENAME TABLE  `status` TO  `user_status` ;
DELETE FROM `user_status` WHERE `user_status`.`nuser` = 9 AND `user_status`.`nsection` = 1415;
DELETE FROM `user_status` WHERE `user_status`.`nuser` = 28 AND `user_status`.`nsection` = 1415;
DELETE FROM `user_status` WHERE `user_status`.`nuser` = 76 AND `user_status`.`nsection` = 1818;
DELETE FROM `user_status` WHERE `user_status`.`nuser` = 65 AND `user_status`.`nsection` = 1415;
ALTER TABLE  `user_status` DROP  `maxsize` ;
ALTER TABLE  `user_status` DROP  `ngroup` ;
ALTER TABLE  `user_status` CHANGE  `nuser`  `uid` INT( 11 );
ALTER TABLE  `user_status` CHANGE  `nsection`  `sid` INT( 11 );
ALTER TABLE  `user_status` CHANGE  `start`  `cursor` INT( 11 ) NOT NULL DEFAULT  '0';
-- remove status for non existant sections or user
DELETE  `user_status` FROM  `user_status` LEFT JOIN section ON user_status.sid = section.sid WHERE section.sid IS NULL;
DELETE  `user_status` FROM  `user_status` LEFT JOIN user ON user_status.uid = user.uid WHERE user.uid IS NULL;
ALTER TABLE  `user_status` ADD  `nb_flags` INT NULL AFTER  `sid` ;
-- 1024 can store the read/unread state of the last 8192 messages
ALTER TABLE  `user_status` ADD  `hexstring` VARCHAR( 4096 ) NOT NULL ; 
UPDATE `user_status` SET`hexstring`= cast(bitlist as char);
ALTER TABLE  `user_status` DROP  `bitlist` ;
ALTER TABLE  `user_status` CHANGE  `hexstring`  `bitlist` VARCHAR( 4096 ) ;


-- prepare table user_subscr
CREATE TABLE IF NOT EXISTS `user_subscr` (
  `uid` int(11) NOT NULL,
  `sid` int(11) NOT NULL,
  UNIQUE KEY `idx_user_section` (`uid`,`sid`),
  KEY `idx_user` (`uid`),
  KEY `idx_section` (`sid`)
);


-- prepare table user_flags
CREATE TABLE IF NOT EXISTS `user_flag` (
    `id_node` int(11) NOT NULL,
    `nid` int(11) NOT NULL,
    `uid` int(11) NOT NULL,
    `sid` int(11) NOT NULL,
    `keywords` varchar(128) NOT NULL DEFAULT '',
    UNIQUE KEY `idx_section_node_user` (`uid`,`sid`,`nid`),
    KEY `id_user` (`uid`),
    KEY `id_section` (`sid`),
    KEY `id_node` (  `id_node` ),
    KEY `no_node` (`nid`),
    KEY `idx_user_section` (`uid`,`sid`)
) ENGINE=MyISAM;




-- END.