<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Note : Some function of Class::Session are on the critical path. Each request need auth before lauching.
//        Try to avoid complexity and db request.

class Node {
    
    private static $dbh;
    private static $logger;

    public static function _init(Container $c)
    {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }

    
    private static function auth_can_access_section($args, $context) {
        // user must be subscribed to section to access node functions (no administrator/superuser special rights)
        if (in_array($args['sid'], $context['subscr'])) {
            return true;
        } else {
            return false;
        }
    }
    
    
    public static function _POST (Request $request, Response $response, $args) {
        // Route : '/node/sid/{sid:[0-9]+}/'
        // Route : '/node/sid/{sid:[0-9]+}/{pid:[0-9]+}/'
        // $bodyJson : title, body
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // check if title is set
        if ( !isset( $bodyJson['title'] ) ) {
            return $response->withJson(  ['error' => 'Bad request. Missing title.' ], 400);
        }
        $bodyJson['title'] = trim($bodyJson['title']);
        // if body is empty, add "(nt)", if not already
        if ((!isset($bodyJson['body']) OR trim($bodyJson['body']) === '') AND !preg_match('/\(nt\)$/', $bodyJson['title']) ) {
            $bodyJson['title'] .= ' (nt)';
        }
        
        if (isset($bodyJson['body'])) {
            $bodyJson['body'] = trim($bodyJson['body']);
        } else {
            $bodyJson['body'] = '';
        }

        // check last_post_hash; store the new last_post_hash if ok
        $post_hash = sha1($bodyJson['title'].$bodyJson['body']);
        if ( $post_hash === $context['last_post_hash'] ) {
            return $response->withJson( [ 'error' => 'Well, you post the same text twice. I can\'t allow this lack of creativity (or your browser make a POST update).' ] , 400);
        }
        //self::$logger->debug("VARS : ".print_r([':uid' => $context['uid'], ':last_post_hash' => $post_hash ], true));
        self::$dbh->execute( 
            " UPDATE `user` SET `last_post_hash`=:last_post_hash WHERE `uid`=:uid ; ",
            [':uid' => $context['uid'], ':last_post_hash' => $post_hash ] );
        
        // get user sig (not per default in $context)
        $user = self::$dbh->fetch_one( 
            " SELECT `sig` FROM `user` WHERE `uid`=:uid ; ",
            [':uid' => $context['uid'] ] );
        
        // increment nnode - and nthread if parent = 0
        // In a perfect world, this should be a transaction (maybe once...)
        // select nid, tid from node
        // update nid=nid+1, (si nouveau thred) tid=tid+1
        
        // LE FIL FANTÔME se cache probablement ici (grep: fantome)
        // increment of nnode is done before inserting node. If insert failed, nnode is wrong.
        
        $section = self::$dbh->fetch_one( 
            " SELECT `nthread`, `nnode` FROM `section` WHERE `sid`=:sid ; ",
            [':sid' => $args['sid'] ] );
        $section['nnode'] = $section['nnode'] + 1;
        $tid = 0; // MUST BE SET below
        if (!isset($args['pid'])) { // new thread
            $args['pid'] = -1;
            $section['nthread'] = $section['nthread'] + 1;
            $pid = $section['nthread'];
            self::$dbh->execute(
            " UPDATE `section` SET `nthread`=:nthread, `nnode`=:nnode WHERE `sid`=:sid ; ",
            [ ':nthread' => $section['nthread'], ':nnode' => $section['nnode'], ':sid' => $args['sid'] ] );
        } else {
            self::$dbh->execute( 
                " UPDATE `section` SET `nnode`=:nnode WHERE `sid`=:sid ; ",
                [ ':nnode' => $section['nnode'], ':sid' => $args['sid'] ] );
            //self::$logger->debug("VARS : ".print_r([ ':sid' => $args['sid'], ':nid' => $args['pid'] ], true));
            $parent = self::$dbh->fetch_one( 
                " SELECT `tid` FROM `node` WHERE `sid`=:sid AND `nid`=:nid ; ",
                [ ':sid' => $args['sid'], ':nid' => $args['pid'] ] );
            $tid = $parent['tid']; // thread of the parent. Could be sent as parameter but... it could be wrong with malicius users or stupid frontend.
        }
        
        // in db, store the new node with title, body, and sig.
        $qparams = [
            ':sid'       => $args['sid'],
            ':nid'       => $section['nnode'],
            ':pid'       => $args['pid'],
            ':tid'       => $tid,
            ':title'     => $bodyJson['title'],
            ':body'      => $bodyJson['body'],
            ':sig'       => $user['sig'],
            ':cdate'     => time(),
            ':uid'       => $context['uid'],
            ':realname'  => $context['realname'],
            ':email'     => $context['email'],
            ':ipaddress' => $request->getAttribute('ip_address'), 
        ];
        
        self::$dbh->execute( 
            " INSERT INTO `node`
                (`sid`, `nid`, `pid`, `tid`, `title`, `body`, `sig`, `cdate`, `state`, `uid`, `realname`, `email`, `ipaddress`)
                VALUES (:sid, :nid, :pid, :tid, :title, :body, :sig, FROM_UNIXTIME(:cdate), 'p', :uid, :realname, :email, :ipaddress) ; ",
            $qparams );
        
        // update user last_post date
        self::$dbh->execute("UPDATE `user` SET `last_post`=NOW() WHERE uid=5");
        
        // this response is like a _GET (probably, i don't check)
        return $response->withJson( $qparams , 200);
    } // END public static function _POST
    
    
    public static function _GET (Request $request, Response $response, $args) {
        // Route : '/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        $query = "
            SELECT
                `id`, `sid`, `nid`, `pid`, `tid`, `title`, `body`, `sig`, `cdate`, `state`, `uid`, `realname`, `email`, `ipaddress`
            FROM `node` 
            WHERE `sid`=:sid AND `nid`=:nid AND `state` = 'p'
        ";
        $qparam = [
            ':sid' => $args['sid'],
            ':nid' => $args['nid']
        ];
        $res = self::$dbh->fetch_one($query, $qparam );
        
        if (!isset($res['nid'])) {
            return $response->withJson( [ 'error' => 'Message not found. What the fuck are you doing ?!' ] , 404 );
        }
        
        if ($context['role'] === 'u') {
            unset($res['ipaddress']); // only for admins. Has always been useless, in fact.
        }
        if ($res['state'] === 'r') {
            return $response->withJson( [ 'error' => 'Message has been removed. Sorry. Not fast enough.' ] , 404 );
        }
        
        // get the flag. If exists, add $keywords to $res 
        $flag = self::$dbh->fetch_one(
            "  SELECT `id_node`, `keywords` FROM `user_flag` WHERE `nid`=:nid AND `sid`=:sid AND `uid`=:uid  ", 
            [ ':sid' => $args['sid'], ':nid' => $args['nid'], ':uid' => $context['uid'] ]);
        if (isset($flag['id_node'])) {
            $res['flag']          = 'on'; // it could comes with a flag set, but without keywords
            $res['flag_keywords'] = $flag['keywords'];
        } else {
            $res['flag']          = 'off'; // just in case ;)
        }
        
        // get the vector, set as read, and if changed : store the new vector
        $vector = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        $vector->off($args['nid']);
        $vector->db_set();
        
        return $response->withJson( $res , 200 );
    } // END public static function _GET
    
    public static function _GET_flags (Request $request, Response $response, $args) {
        // Route : '/node/flags/'
        // Route : '/node/flags/sid/{sid:[0-9]+}/'
        // Param : ?filter=aaa
        $context = $request->getAttribute('context');
        $params  = $request->getParams();
       
        $sql_where = '';
        if (isset($args['sid'])) { // only for a section
            $sql_where = " AND uf.`sid`=".$args['sid']." "; // only numers - no SQL injection here
        }
        
        $sql_filter = '';
        if (isset($params['filter'])) { // only for a section
            $params['filter'] = preg_replace("/[^\w]+/", "", $params['filter'] ); // remove all but letters, num and spaces
            $sql_filter = " AND ( uf.`keywords` LIKE '%".$params['filter']."%' ".
                    "OR n.`title` LIKE '%".$params['filter']."%' ) "; // has been checked above - no SQL injection here
        }
        
        $res = self::$dbh->fetch_all("
            SELECT s.`sid`, uf.`nid`, s.`sectionname`, n.`title`, n.`realname`,uf.`keywords`
            FROM `user_flag` AS uf, `section`AS s, `node` AS n
            WHERE uf.id_node=n.id AND uf.sid=s.sid AND uf.uid=:uid
                $sql_where $sql_filter
        ", [ ':uid' => $context['uid'] ]);
        
        return $response->withJson( $res , 200);
    } // END public static function _GET_flags
   
    public static function _PUT_mark (Request $request, Response $response, $args) {
        // Route : '/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/mark/{what:read|unread}/'
        $context = $request->getAttribute('context');
        
        // Warning. You cannot unread a thread older than vector_size. 
        // It retain the read/unread status of only the last vector_size nodes of a given section
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        $vector = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        $success_on = true;
        if ($args['what'] === 'read') {
            $vector->off($args['nid']);
        } else { // unread
            $success_on = $vector->on($args['nid']);
        }
        $vector->db_set();
        
        if (!$success_on) {
            $vector_size = NodeVector::$mem_size - NodeVector::$mem_buffer; // somewhere between this and $mem_size
            return $response->withJson( [ 'error' => "Sorry. You cannot mark as 'unread' a thread older than $vector_size. It's not a bug... it's a feature." ] , 400);
        }
                
        return $response->withJson( [ 'status' => 'Ok.' ] , 200);
    } // END public static function _PUT_mark
    
    
    public static function _PUT_flags (Request $request, Response $response, $args) {
        // Route : '/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/flag/{what:set|delete}/'
        // params is json { "keywords" = "ijfdg idfujgfoig gfgfg"}
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }     

        // if set, create or update flag
        // if delete, remove flag
        if ($args['what'] === 'set') {
            // select id of node (it's not the couple sid/nid, but a general auto increment used for tables Constraint properties)
            // maybe today Constraint properties could be used with non-incrementals index, don't know, I did it on mysql 5.3
            $res = self::$dbh->fetch_one(
                "  SELECT `id` FROM `node` WHERE `nid`=:nid AND `sid`=:sid ", 
                [ ':sid' => $args['sid'], ':nid' => $args['nid'] ]);
            if (!isset($res['id'])) {
                return $response->withJson( [ 'error' => 'Wait, wait. This nid/sid doesn\'t exists...' ] , 404);
            } else {
                // UPDATE flag or INSERT a new one ? => REPLACE !
                self::$dbh->execute(
                "  REPLACE INTO `user_flag` (`id_node`, `nid`, `sid`, `uid`, `keywords`)
                   VALUES (:id_node, :nid, :sid, :uid, :keywords);
                ", 
                [ ':id_node' => $res['id'], ':sid' => $args['sid'], ':nid' => $args['nid'], 
                  ':uid' => $context['uid'], ':keywords' => $bodyJson['keywords'] ]);
            }
        } else { // if delete, remove flag
            self::$dbh->execute(
                "  DELETE FROM `user_flag` WHERE `nid`=:nid AND `sid`=:sid AND `uid`=:uid  ", 
                [ ':sid' => $args['sid'], ':nid' => $args['nid'], ':uid' => $context['uid'] ]);
        }
        
        return $response->withJson( [ 'status' => 'Done.' ] , 200);
    } // END public static function _PUT_flags
    
    
    public static function _DELETE (Request $request, Response $response, $args) {
        // Route : '/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // test if admin or author
        $query = "
            SELECT `id`, `sid`, `nid`, `uid`
            FROM   `node` 
            WHERE  `sid`=:sid AND `nid`=:nid
        ";
        $res1 = self::$dbh->fetch_one($query, [ ':sid' => $args['sid'], ':nid' => $args['nid'] ] );
        
        if ( !( $context['role'] === 'a' OR $context['role'] === 's' ) AND $res1['uid'] !== $context['uid'] ) {
            return $response->withJson( [ 'error' => 'You must be the author of the node to delete it (or admin or supoeruser).' ] , 403);
        }
        
        // set statut as 'r' (removed)
        self::$dbh->execute(
            "  UPDATE `node` SET `state`='r' WHERE `sid`=:sid AND `nid`=:nid ", 
            [ ':sid' => $args['sid'], ':nid' => $args['nid'] ]);
        
        // remove all associated flags
        self::$dbh->execute(
            "  DELETE FROM `user_flag` WHERE `sid`=:sid AND `nid`=:nid ", 
            [ ':sid' => $args['sid'], ':nid' => $args['nid'] ]);
        
        // After delete a node, I could mark it as "read" for every users
        // ... but this wouldn't be a complexity of O(nb_users), and I prefer complexity of O(1) ;)
        
        // return ok
        return $response->withJson( [ 'status' => 'Done.' ] , 200);
    } // END public static function _DELETE
    
    
} // END class Node
