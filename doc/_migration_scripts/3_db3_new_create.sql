-- phpMyAdmin SQL Dump
-- 
-- Server version: 5.5.38-0ubuntu0.14.04.1
-- PHP Version: 5.5.9-1ubuntu4.4

--
-- Database: `agora`
--

-- CREATE DATABASE IF NOT EXISTS `agora3` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `group`
--

CREATE TABLE IF NOT EXISTS `group` (
  `gid` int(11) NOT NULL AUTO_INCREMENT,
  `groupname` char(70) NOT NULL DEFAULT 'a new groupe title',
  `groupdescr` TEXT,
  PRIMARY KEY (`gid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci AUTO_INCREMENT=1 ;


-- --------------------------------------------------------

--
-- Table structure for table `section`
--

CREATE TABLE IF NOT EXISTS `section` (
  `sid` int(11) NOT NULL AUTO_INCREMENT,
  `gid` int(11) NOT NULL,
  `sectionname` char(70) NOT NULL DEFAULT 'A new theme/section',
  `sectiondescr` TEXT,
  `nthread` int(11) NOT NULL,
  `nnode` int(11) NOT NULL,
  `self_sub` CHAR(1) DEFAULT 'y',
  `display_pos` SMALLINT NOT NULL DEFAULT '1',
  PRIMARY KEY (`sid`),
  KEY `idx_gid` (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci AUTO_INCREMENT=1 ;


-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE IF NOT EXISTS `user` (
    `uid` int(11) NOT NULL AUTO_INCREMENT,
    `gid` int(11) NOT NULL,
    `role` CHAR(1) DEFAULT 'u' COMMENT  'u/user, s/superuser, a/administrator',
    `username` varchar(64) UNIQUE NOT NULL DEFAULT 'new username name',
    `realname` varchar(32) NOT NULL DEFAULT 'my_real or nick name',
    `email` varchar(64) NOT NULL DEFAULT 'your.name@gmail.com',
    `viewmax` int(11) NOT NULL DEFAULT '50',
    `language` char(2) NOT NULL DEFAULT 'en',
    `last_login` DATETIME NULL DEFAULT NULL,
    `last_post` DATETIME NULL DEFAULT NULL,
    `last_post_hash` CHAR(40) NULL DEFAULT NULL,
    `passwd` char(40) DEFAULT NULL,
    `passwd_salt` char(8) DEFAULT NULL,
    `sig` varchar(256) NOT NULL DEFAULT '-- me.',
    PRIMARY KEY (`uid`),
    KEY `idx_realname` (`realname`),
    KEY `idx_gid` (`gid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci AUTO_INCREMENT=1 ;


-- --------------------------------------------------------

--
-- Table structure for table `node`
--

CREATE TABLE IF NOT EXISTS `node` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sid` int(11) NOT NULL,
  `nid` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `tid` int(11) NOT NULL,
  `title` varchar(90) NOT NULL,
  `body` text,
  `uid` int(11) NOT NULL,
  `sig` varchar(256),
  `cdate` DATETIME NULL DEFAULT NULL,
  `state` CHAR(1) DEFAULT 'p' NOT NULL COMMENT  'p/posted, r/removed',
  `realname` varchar(32) NOT NULL,
  `email` varchar(64) NOT NULL,
  `ipaddress` char(15) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sid_nid` (`sid`,`nid`),
  UNIQUE KEY `idx_nid_sid` (`nid`,`sid`),
  KEY `idx_tid` (`tid`),
  KEY `idx_srch_thread` (`sid`, `tid`),
  FULLTEXT idx_fulltext (`realname`, `title`, `body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci AUTO_INCREMENT=1 ;


-- --------------------------------------------------------

--
-- Table structure for table `user_flag`
--

CREATE TABLE IF NOT EXISTS `user_flag` (
    `id_node` int(11) NOT NULL,
    `nid` int(11) NOT NULL,
    `sid` int(11) NOT NULL,
    `uid` int(11) NOT NULL,
    `keywords` varchar(128) DEFAULT '',
    UNIQUE KEY `idx_uid_sid_nid` (`uid`,`sid`,`nid`),
    KEY `idx_uid` (`uid`),
    KEY `idx_sid` (`sid`),
    KEY `idx_id_node` (  `id_node` ),
    KEY `idx_nid` (`nid`),
    KEY `idx_sid_uid` (`sid`,`uid`)
)   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `user_status`
--

CREATE TABLE IF NOT EXISTS `user_status` (
    `uid` int(11) NOT NULL,
    `sid` int(11) NOT NULL,
    `nb_flags` INT(11)  NOT NULL DEFAULT '0',
    `cursor` int(11) NOT NULL DEFAULT '0',
    `bitlist` varchar(1024) DEFAULT NULL,
    UNIQUE KEY `idx_sid_uid` (`sid`,`uid`),
    KEY `idx_uid` (`uid`),
    KEY `idx_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `user_subscr`
--

CREATE TABLE IF NOT EXISTS `user_subscr` (
  `uid` int(11) NOT NULL,
  `sid` int(11) NOT NULL,
  UNIQUE KEY `idx_sid_uid` (`uid`,`sid`),
  KEY `idx_uid` (`uid`),
  KEY `idx_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `user_token`
--

CREATE TABLE IF NOT EXISTS `user_token` (
  `token` char(40) NOT NULL,
  `token_exp` DATETIME NULL DEFAULT NULL,
  `uid` int(11) NOT NULL,
  `device` CHAR(96) NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `log_rest`
--

CREATE TABLE IF NOT EXISTS `log_rest` (
  `qid` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `qdate` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `method` varchar(9) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nbqueries` SMALLINT NOT NULL,
  `duration` BIGINT NOT NULL COMMENT 'in second /1''000''000',
  PRIMARY KEY (`qid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1 ;


-- --------------------------------------------------------

--
-- Constraints for table `node`
--
ALTER TABLE `node`
  ADD CONSTRAINT `node_ibfk_1` FOREIGN KEY (`sid`) REFERENCES `section` (`sid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `section`
--
ALTER TABLE `section`
  ADD CONSTRAINT `section_ibfk_1` FOREIGN KEY (`gid`) REFERENCES `group` (`gid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`gid`) REFERENCES `group` (`gid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_flag`
--
ALTER TABLE `user_flag`
  ADD CONSTRAINT `user_flag_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `user` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_flag_ibfk_2` FOREIGN KEY (`sid`) REFERENCES `section` (`sid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_flag_ibfk_3` FOREIGN KEY (`id_node`) REFERENCES `node` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_status`
--
ALTER TABLE `user_status`
  ADD CONSTRAINT `user_status_ibfk_2` FOREIGN KEY (`sid`) REFERENCES `section` (`sid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_status_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `user` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_subscr`
--
ALTER TABLE `user_subscr`
  ADD CONSTRAINT `user_subscr_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `user` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_subscr_ibfk_2` FOREIGN KEY (`sid`) REFERENCES `section` (`sid`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_token`
-- 
ALTER TABLE  `user_token` 
    ADD CONSTRAINT  `user_token_ibfk_1` FOREIGN KEY (`uid`) REFERENCES  `user` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE ;


-- --------------------------------------------------------
-- END. 