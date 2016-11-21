<?php

/**
 * Migrate DB from agora2 to agora 3
 * For agora2 : fill the new user_flag and user_section tables
 * For agora3 : convert crasy ASCII/ISO8859-1/etc. in UTF8
 */


use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class _Migrate {
    
    private static $settings;
    private static $logger;
    private static $dbhroot;

    public static function _init(Container $c) {
        self::$settings = $c->get('settings')['agora'];
        self::$logger   = $c['logger'];
        self::$dbhroot  = $c->get('settings')['dbroot'];
    }
    
    public static function _GET_stage_0 (Request $request, Response $response, $args) {
        self::$logger->debug("_Migrate::stage0 Do nothing, just explain migration

            stage 1 : (mysql - manual)   
                prepare two empty databases agora2 and agora3
                copy db agora1 over the new agora2
                Prepare the database agora2 and agora3 (~19 mn on my virtual machine)

            stage 2 : (mysql - auto)   Transform db shema on agora2
                
            stage 3 : (shell - manual) NOW do the export/export stuff (~5 mn)
    
            stage 4 : (php - auto - now agora3 !) transform node indexation (~25 minutes)

            stage 5 : (shell - manual - optionnal) mysqlcheck --auto-repair --optimize --all-databases

        ");
        return $response->withJson(['result' => 'Done, see log file.' ], 200);
    } // END public static function _GET_stage_0

    
    public static function _GET_stage_1 (Request $request, Response $response, $args) {
        self::$logger->debug("_Migrate::stage1 

        cd /home/pf/rest/doc/_migration_scripts

        cat 1_db_create.sql | mysql -uroot -psoleil

        time mysqldump agora1 -uroot -psoleil | mysql agora2 -uroot -psoleil

        cat 2_db2_migration.sql | mysql -uroot -psoleil agora2

        cat 3_db3_new_create.sql | mysql -uroot -psoleil agora3

        ");

        return $response->withJson(['result' => 'Done, see log file.' ], 200);
    } // END public static function _GET_stage_3
    
    private static function migrateSubscr($dbh) {
        self::$logger->debug("_Migrate::migrateSubscr()");
        $query = "select uid, agora from user;";
        $res = $dbh->fetch_all($query);
        foreach ($res as $user) {
            // self::$logger->debug("Doing : ".$user['uid']." with ".$user['agora']);
            $section_list = explode(':',trim($user['agora'], ':'));
            foreach ($section_list as $sect) {
                $q2 = "INSERT INTO `user_subscr`(`uid`, `sid`) VALUES ( ".$user['uid']." , ".$sect.");\n";
                $dbh->execute($q2);
            }
        } 
    }
        
    private static function migrateFlags($dbh) {
        $query = "select uid, sid, flags from user_status;";
        $res = $dbh->fetch_all($query);
        foreach ($res as $flags) {
            // self::$logger->debug("Doing : ".$flags['uid'].", ".$flags['sid']." with list... ".$flags['flags']);
            $flag_list = preg_split('@:@',$flags['flags'], NULL, PREG_SPLIT_NO_EMPTY);
            foreach ($flag_list as $nid) {
                if ($nid == 0) {
                    continue;
                }
                //self::$logger->debug("Doing : ".$flags['uid'].", ".$flags['sid']." with NODE $nid");
                $query = "
                    INSERT INTO user_flag (id_node, nid, uid, sid)
                    SELECT node.id, $nid, {$flags['uid']} , {$flags['sid']} FROM node 
                    WHERE node.nid = $nid AND node.sid = {$flags['sid']}
                ";
                $dbh->execute($query);
            }
        }
        $dbh->execute("
            UPDATE `user_status` SET nb_flags = 
                (SELECT COUNT(id_node) FROM user_flag 
                 WHERE user_flag.uid = user_status.uid and user_flag.sid = user_status.sid
                 GROUP BY uid, sid
                ) ;
        ");
        $dbh->execute("
            UPDATE `user_status` SET nb_flags = 0 where nb_flags is null;
        ");
    }
    
    public static function _GET_stage_2 (Request $request, Response $response, $args) {
        self::$logger->debug("_Migrate::stage2 

            stage 2 :  (mysql) 
                -> use agora 2 
                _Migrate::migrateSubscr() 
                _Migrate::migrateFlags()
                ALTER TABLE  `user` DROP  `agora` ;
                ALTER TABLE  `user_status` DROP  `flags` ;
        ");
        
        $dbhroot = new Database(self::$dbhroot['dsn'], self::$dbhroot['username'], self::$dbhroot['password'] , self::$logger);
        $dbhroot->execute(" USE agora2 ; ");
        _Migrate::migrateSubscr($dbhroot);
        _Migrate::migrateFlags($dbhroot);
        $dbhroot->execute(" ALTER TABLE  `user` DROP  `agora` ; ");
        $dbhroot->execute(" ALTER TABLE  `user_status` DROP  `flags` ; ");
        
        return $response->withJson(['result' => 'Done.' ], 200);
    } // END public static function _GET_stage_2
    
    public static function _GET_stage_3 (Request $request, Response $response, $args) {
        self::$logger->debug("_Migrate::stage3 

            stage 3 : (shell) (~5 mn)
                NOW do the export/export think :
                4_db_migration_data.sh
        ");
               
        return $response->withJson(['result' => 'Done.' ], 200);
    } // END public static function _GET_stage_3

    private static function import_statut($dbh) {
        self::$logger->debug("\n\n___________________________________________________\n_Migrate::import_statut");
        // for each statut in table
        //      import the vector
        //      export 
        //      save
        $v_size = 4096; // size of all imported vector from agora1
        // re-copy from old databse, for dev...
        // UPDATE agora3.user_status AS us3, agora2.user_status AS us2 
        // SET agora3.`us3`.cursor = agora2.`us2`.cursor, agora3.`us3`.bitlist = agora2.`us2`.bitlist 
        // WHERE agora2.`us2`.uid = agora3.`us3`.uid AND agora2.`us2`.sid = agora3.`us3`.sid
        
        $query = "
            SELECT ust.`uid`, ust.`sid`, ust.`cursor`, ust.`bitlist`, s.nnode 
            FROM `user_status` AS ust, section AS s
            WHERE s.sid=ust.sid ; 
        ";
//        $query = "SELECT ust.`uid`, ust.`sid`, ust.`cursor`, 
//            ust.`bitlist`, s.nnode FROM `user_status` AS ust, section AS s
//            where s.sid=ust.sid AND ust.sid=43; ";
        $stmt = $dbh->execute($query);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
            // show value for : 502'000 + [754,755,723,722,746,721,744,720]
//            foreach([754,755,723,722,746,721,744,720] as $p) {
//                $start = 498970;
//                $p = $p + 502000;
//                $gmp = gmp_init($row['bitlist'],16);
//                self::$logger->debug("bit $p = (real:",$p-$start,"), ",(gmp_testbit($gmp, $p-$start) ? '1' : '0'),"\n");
//            }

            // create by importing/converting the vector
            // import_old_agora($old_string, $old_start, $nnode)
//            self::$logger->debug("OLD",$row['bitlist'], $row['cursor'],$row['nnode']);
            $v = NodeVector::import_old_agora($row['bitlist'], $row['cursor'],$row['nnode'] );
            $export = $v->export();
//            self::$logger->debug("EXPORTED",$export);
//            foreach([754,755,723,722,746,721,744,720] as $p) {
//                $p = $p + 502000;
//                echo "bit $p = ",$v->read($p),"\n";
//            }
    
            // save
            $query = "
                UPDATE  `user_status` 
                SET     `cursor`=:cursor,`bitlist`=:bitlist 
                WHERE   `uid`=:uid AND `sid`=:sid ; 
            ";
            $values = [ 
                'uid' => $row['uid'],
                'sid' => $row['sid'],
                'cursor' => $export['c'],
                'bitlist' => $export['v'] 
            ];
//            self::$logger->debug($query, $values);
            $dbh->execute($query, $values);
        }
    }
    
    private static function latin_to_utf8($dbh) {
        self::$logger->debug("\n\n___________________________________________________\n_Migrate::latin_to_utf8");
        $tables = [
            'user' => ['uid', 'role', 'username', 'realname', 'email', 'language', 'passwd', 'passwd_salt', 'sig' ],
            'group' => ['gid', 'groupname', 'groupdescr' ],
            'section' => ['sid', 'sectionname', 'sectiondescr' ],
            'node' => ['id', 'title', 'body', 'realname', 'email', 'ipaddress' ],
        ];
        foreach($tables as $t => $cols) {
            $qcols = '`'.implode('`, `', $cols).'`';
            $query = "SELECT $qcols FROM `$t` ; ";
            self::$logger->debug("Analyse table : $t... ($query)\n");
            $stmt = $dbh->execute($query);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { // PDO::FETCH_NUM, PDO::FETCH_ORI_PRIOR, 
                foreach ($cols as $c) {
                    $enc = mb_detect_encoding($row[$c],[ 'ASCII',  'UTF-8', 'ISO-8859-1'], true);
                    //self::$logger->debug("Table $t, col $c, $cols[0] ".$row[$cols[0]].", encoding : [$enc]");
                    if ($enc === 'ISO-8859-1' ) {
                        //$trans = iconv("ISO-8859-1", "UTF-8//TRANSLIT", $row[$c]);
                        $trans = iconv("ISO-8859-1", "UTF-8//IGNORE", $row[$c]);
                        $query2 = "
                            UPDATE `$t` SET $c=:trans WHERE {$cols[0]}={$row[$cols[0]]} ; 
                        ";
                        //self::$logger->debug("__________ $query2", $trans);
                        $dbh->execute($query2, [ ':trans' => $trans ]);
                    } elseif (!($enc === 'ASCII' or $enc === 'UTF-8')) {
                        self::$logger->debug("ERROR: Unknown encoding : --> $t, $c,  {$row[$cols[0]]} [".mb_detect_encoding($row[$c])."] !\n");
                    }
                }
            }
        }
    }
    
    private static function remove_unused_flags($dbh) {
        // delete flags where node are removed
        $query = "
            DELETE `user_flag`
            FROM   `user_flag`, `node`
            WHERE  `node`.state != 'p' AND `node`.nid = `user_flag`.nid AND `node`.sid = `user_flag`.sid
        ";
        $dbh->execute($query);
    }
    
    public static function _GET_stage_4 (Request $request, Response $response, $args) {
        self::$logger->debug("_Migrate::stage4 

            stage 4 : (php - now agora3 !) (~25 minutes)
                _Migrate::latin_to_utf8(...);
                _Migrate::import_statut(...);
                _Migrate::remove_unused_flags(...);
                
            PLEASE REMEMBER
            
            set dbh->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); before this command
            
            ... and remove it after.

        ");
        $dbhroot = new Database(self::$dbhroot['dsn'], self::$dbhroot['username'], self::$dbhroot['password'], self::$logger );
        $dbhroot->execute(" USE agora3 ; ");
        
        // convert table in a php script - maybe try on the database level first ?
        // NEED NO BUFFERING, see Database.php
        // 1026-05-20 : inutile avec mysql5.7 et php7 ? merde tout ce travail pour rien.
        //_Migrate::latin_to_utf8($dbhroot);

        // convert the status table in the new format (no BIGINDIAN, no startno)
        _Migrate::import_statut($dbhroot);
        
        // delete flags where node are removed
        _Migrate::remove_unused_flags($dbhroot);

        return $response->withJson(['result' => 'Done.' ], 200);
    } // END public static function _GET_stage_4
    
    public static function _GET_stage_5 (Request $request, Response $response, $args) {
        $root = self::$dbhroot['username'];
        $pwd  = self::$dbhroot['password'];
        self::$logger->debug("_Migrate::stage5 

            stage 5 : (shell) End with :
                time mysqlcheck -u $root -p$pwd --auto-repair --optimize --all-databases
        ");
               
        return $response->withJson(['result' => 'Done.' ], 200);
    } // END public static function _GET_stage_5
    
} // END class Migrate