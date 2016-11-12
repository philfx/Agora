<?php

/**
 * Description of Section
 *
 * @author FroidevauxPh
 * 
 */

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class Section {

    private static $dbh;
    private static $logger;

    public static function _init(Container $c)
    {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }

    private static function get_section_gid_from_sid($sid) {
        $query = ' SELECT `gid`, `self_sub` FROM `section` WHERE sid=:sid ; ';
        $res = self::$dbh->fetch_one($query, [ ':sid' => $sid ]);
        return $res;
    }
    
    /*
     * Note on security (before I forgot why this complicated things)
     * allow_CRUD_on_section _> is user admin, or is superuser, or is superuser of the group of the given section
     * allow_common_usage -> is the given section in the same group of the user, and is the user subscribed to the section, or is the section free
     */
    
    private static function allow_CRUD_on_section($args, $context) { // need $context['role'], $args['sid']
        //self::$logger->debug("Section->allow_CRUD_on_section\nargs:".print_r($args,true)."\ncontext:".print_r($context,true));
        if ($context['role'] === 'a') {
            return true;
        } 
        if ($context['role'] === 's' AND !isset($args['sid'])) { // no SID, it's a POST request or a get_all
            return true;
        }
        $section     = self::get_section_gid_from_sid($args['sid']);
        self::$logger->debug("Section->allow_CRUD_on_section\n section:".print_r($section,true));
        if ($context['role'] === 's' AND $context['gid'] === $section['gid']) { // verify that $args[sid]->[gid] is the same as $context[gid ] !
            return true;
        }
        return false;
    }

    private static function allow_common_usage($args, $context) {
        // in : $args['sid'] (maybe), $args['self_sub'] (or query), $context
        // for a stadard user, for standard operations
        // test if admin or superuser, group ok, or subscription is open
        self::$logger->debug("Section->allow_common_usage\nargs:".print_r($args,true)."\ncontext:".print_r($context,true));
        if ($context['role'] === 'a') {
            return true;
        } 
        if (!isset($args['sid'])) { // no SID, it's a GET_all or POST request, and result will be filtered there
            return true;
        }
        
        if (!isset($args['self_sub'])) {
            $section          = self::get_section_gid_from_sid($args['sid']);
            $args['gid']      = $section['gid'];
            $args['self_sub'] = $section['self_sub'];
        }
        
        // Note : "admin" has no special rights on section, he's a standard user. Only superusers are special.
        if ($context['role'] === 's' AND $context['gid'] === $args['gid']) { // verify that $args[sid]->[gid] is the same as $context[gid ] !
            return true;
        }
        
        if ($args['gid'] == $context['gid'] ) {
            if ($args['self_sub'] === 'y') { 
                return true;
            } elseif  (in_array($args['sid'], $context['subscr'])) {
                return true;
            }
        }
        return false;
    } 
    
    private static function is_name_unique($gid, $sectionname) {
        // Section name should be unique... inside a group, not for every groups
        $query = ' SELECT `sid` FROM `section` WHERE sectionname=:sectionname AND gid=:gid; ';
        $res = self::$dbh->fetch_one($query, [ ':sectionname' => $sectionname , ':gid' => $gid]);
        if ($res) {
            return false;
        } else {
            return true;
        }
    }
    
    public static function _POST(Request $request, Response $response, $args){
        // Route : '/section/' <-- {"sectiontitle":"my_section","sectionbody":"Oh! ôh."}
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::allow_CRUD_on_section($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are neither admin not superuser.' ] , 403);
        }
        if (!isset($bodyJson['sectionname']) || strlen($bodyJson['sectionname']) < 4) {
            return $response->withJson( [ 'error' => 'Bad request. Missing section name, or name length < 4.' ] , 400);
        }
        if (!self::is_name_unique($context['gid'], $bodyJson['sectionname'])) {
            return $response->withJson( [ 'error' => 'Bad request. Section name already in use.' ] , 400);
        }
        $bodyJson['sectiondescr'] = isset($bodyJson['sectiondescr']) ? $bodyJson['sectiondescr'] : 'Description of your new section here.';
        $bodyJson['display_pos'] = isset($bodyJson['display_pos']) ? $bodyJson['display_pos'] : 1;
        
        $query = "INSERT 
            -- create a new section
            INTO `section` ( `gid`, `sectionname`, `sectiondescr`, `nthread`, `nnode`, `self_sub`) 
            VALUES ( :gid, :sectionname, :sectiondescr, :nthread, :nnode, :self_sub) ;
        ";
        $params = [ 
            ':gid' => $context['gid'], 
            ':sectionname' => $bodyJson['sectionname'], 
            ':sectiondescr' => $bodyJson['sectiondescr'],
            ':nthread' => 0,
            ':nnode' => 0,
            ':self_sub' => 'y',
            ':display_pos' => $bodyJson['display_pos']
        ];
        
        self::$dbh->execute($query, $params);
        $args['sid'] = self::$dbh->last_insert_id(); 
        return self::_GET_one($request, $response, $args);
    }

    public static function _GET_one(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        // Auth is dome after the get, because I need to get the section to perform check.
        // function allow_common_usage() will get the params in database if not present - but here they are

        $query = "
            -- get infos about one section
            SELECT `sid`, `gid`, `sectionname`, `sectiondescr`, `nthread`, `nnode`, `self_sub`, `display_pos`
            FROM `section`
            WHERE sid=:sid ;
        ";
        $res = self::$dbh->fetch_one($query, [ ':sid' => $args['sid'] ]);
        if (!isset($res['gid'])) {
             return $response->withJson( [ 'error' => 'Section not found.' ] , 404);
        }
        
        // Auth now
        $args['gid']       = $res['gid'];       // needed by auth check
        $args['self_sub']  = $res['self_sub'];  // needed by auth check
        if (!self::allow_common_usage($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorized. No rights for this section, or not subscribed.' ] , 403);
        } else {
            return $response->withJson(  $res  , 200);
        }
    }
    
    public static function db_get_section_subscribed($context) {
        // show where i'm subscr, or only subscr with new messages
        $qparams = [ ':gid' => $context['gid'], ':uid' => $context['uid'] ];
        // ust.`nb_drafts` ?? Once...
        $query = "
            SELECT s.`sid`, s.`gid`, s.`sectionname`, s.`nthread`, s.`nnode`, s.`display_pos`,
               ust.`nb_flags` , ust.`cursor`, ust.`bitlist`
            FROM `section` AS s, `user_subscr` AS usu, user_status AS ust
            WHERE s.`gid`=:gid
                AND ( ust.uid=:uid AND ust.sid=s.sid)
                AND ( usu.sid = s.sid AND usu.uid=:uid ) ;
        ";
        $res = self::$dbh->fetch_all($query, $qparams);
        // go throw res, compute "new" = nnode - count(vector), unset(cursor and bitlist)
        foreach ($res as &$row) { // ref &$row by value - it will be edited
            $v = new NodeVector( [ 'v' => $row['bitlist'], 'c' => $row['cursor']] );
            $row['unread'] = $v->count($row['nnode']);
            unset($row['cursor']);
            unset($row['bitlist']);
        }
        return $res;
    }
    
    public static function _GET_subscribed(Request $request, Response $response, $args){
        // Route : '/section/subscribed/'
        $context = $request->getAttribute('context');
        $res = self::db_get_section_subscribed($context);
        return $response->withJson(  $res  , 200);
    }
    
    public static function _GET_subscribe_with_unread_msg(Request $request, Response $response, $args){
        // Route : '/section/unread/'
        $context = $request->getAttribute('context');
        $res = self::db_get_section_subscribed($context);
        $res = array_filter($res, function ($row){ return ($row['unread'] != 0); });
        return $response->withJson(  $res  , 200);
    }
    
    public static function _GET_all(Request $request, Response $response, $args){
        // Route : '/section/all/'
        $context = $request->getAttribute('context');
        
        $qparams[':gid'] = $context['gid'];
        $query   = '';
        if ($context['role'] === 'a' or $context['role'] === 's') {
            $query = "
                SELECT s.`sid`, s.`gid`, s.`sectionname`, s.`nthread`, s.`nnode`, 
                    s.`self_sub`, s.`display_pos`
                FROM `section` AS s
                WHERE s.`gid`=:gid ; ";
        } else { // role=u
            $query = "
                SELECT s.`sid`, s.`gid`, s.`sectionname`, s.`nthread`, s.`nnode`, 
                    s.`self_sub`, s.`display_pos`
                FROM `section` AS s, `user_subscr` AS usu
                WHERE s.`gid`=:gid AND (( usu.sid=s.sid AND usu.uid=:uid ) OR s.self_sub='y' )
                GROUP BY s.sid ;
            ";
            $qparams[':uid'] = $context['uid'];
        }
        $res = self::$dbh->fetch_all($query, $qparams);
        return $response->withJson(  $res  , 200);
    }

    public static function _PUT_edit(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/'
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::allow_CRUD_on_section($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorized. Must be superuser of this group, or admin.' ] , 403);
        }

        if (isset($bodyJson['sectionname']) && (strlen($bodyJson['sectionname']) < 4 || !self::is_name_unique($context['gid'], $bodyJson['sectionname']))) {
            return $response->withJson( [ 'error' => 'Bad request. Section name duplicate, or name length < 4.'.
                'Or the whole section defs were sent as parameter, and sectionname already exists, of course.' ] , 400);
        }
        if (isset($bodyJson['sectionname'])) {
            $query = " UPDATE `section` SET `sectionname`=:sectionname WHERE `sid`=:sid ; ";
            $values = [ ':sid' => $args['sid'], ':sectionname' => $bodyJson['sectionname'] ];
            self::$dbh->execute($query, $values);
        } 
        if (isset($bodyJson['sectiondescr'])) {
            $query = " UPDATE `section` SET `sectiondescr`=:sectiondescr WHERE `sid`=:sid ; ";
            $values = [ ':sid' => $args['sid'], ':sectiondescr' => $bodyJson['sectiondescr'] ];               
            self::$dbh->execute($query, $values);
        }
        if (isset($bodyJson['display_pos'])) {
            $query = " UPDATE `section` SET `display_pos`=:display_pos WHERE `sid`=:sid ; ";
            $values = [ ':sid' => $args['sid'], ':display_pos' => $bodyJson['display_pos'] ];               
            self::$dbh->execute($query, $values);
        }
        
        return self::_GET_one($request, $response, $args);
    }
    
    public static function _PUT_subscribe_all(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/subscribe/{who:noone|everyone}/'
        $context = $request->getAttribute('context');
        self::$logger->debug("Section->_PUT_subscribe_all\n args[sid]:".$args['sid']."\n context[gid]:".$context['gid']);
        
        if (!self::allow_CRUD_on_section($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorized. Must be superuser of this group, or admin.' ] , 403);
        }
        
        if ($args['who'] === 'noone') { // Pronounce "No one" ;)
            // this query leave user_status intact. If the user subscribe again in the future, it will recover its read/unred status
            $query = "   DELETE FROM `user_subscr` WHERE `sid`=:sid ;  ";
            self::$dbh->execute($query, [ ':sid' => $args['sid'] ] );
        } else { // suscribe everyone. INSERT IGNORE because re-subscribe even if already subscribed.
            // subscribe all users
            // because each user is a case, we need to loop into each users
            // cases : 1/ already subscribed, 2/ not subscribed at all, 3/ was subscribed once and thus only need to update statut

            $query = " SELECT `uid` FROM `user` WHERE `gid`=:gid ; ";
            $qparams = [ ':gid' => $context['gid'] ];
            $users = self::$dbh->fetch_list($query, $qparams);
            foreach($users as $uid) {
                $args['uid'] = $uid;
                //$args['sid'] = $args['sid'];
                $args['what'] = 'subscribe';
                self::_PUT_subscribe($request, $response, $args); 
            }
        }
        return $response->withJson( [ 'statut' => 'Done.' ] , 200);
    }
    
    public static function _PUT_freesubscribe(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/freesubscribe/{what:on|off}/'
        $context = $request->getAttribute('context');

        if (!self::allow_CRUD_on_section($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorized. Must be superuser of this group, or admin. Or section does not exists...' ] , 403);
        }
        
        if ($args['what'] === 'on') { // Pronounce "No one" ;)
            $query = "UPDATE `section` SET `self_sub`='y' WHERE sid=:sid ; ";
            $qparams = [ ':sid' => $args['sid'] ];
            $res = self::$dbh->execute($query, $qparams);
        } else { // Users can not freely subscribe/unsubscribe on this section
            $query = "UPDATE `section` SET `self_sub`='n' WHERE sid=:sid ; ";
            $qparams = [ ':sid' => $args['sid'] ];
            $res = self::$dbh->execute($query, $qparams);
        }
        
        return $response->withJson( [ 'statut' => 'Done.' ] , 200);
    }
    
    public static function _PUT_subscribe(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/uid/{uid:[0-9]+}/{what:subscribe|unsubscribe}/'
        $context = $request->getAttribute('context');
        
        if ($args['uid'] === $context['uid']) { // for myself, a common user operation
            if (!self::allow_common_usage($args, $context)) {
                return $response->withJson( [ 'error' => 'Unauthorized. No rights for this section, or not subscribed.' ] , 403);
            } 
        } else {
            if (!self::allow_CRUD_on_section($args, $context)) {
                return $response->withJson( [ 'error' => 'Unauthorized. Must be superuser of this group, or admin.' ] , 403);
            }
        }
        
        if ($args['what'] === 'unsubscribe') {
            // just remove subscription. Do NOT remove user_statut, it could be usefull when subscribing back
            $query = " DELETE FROM `user_subscr` WHERE `uid`=:uid AND `sid`=:sid; ";
            $qparams = [ ':uid' => $args['uid'], ':sid' => $args['sid'] ];
            self::$dbh->execute($query, $qparams);
        } else { // --> subscribe
            self::$logger->debug("Section->_PUT_subscribe -> subscribe \nargs:".print_r($args,true));
            
            // TODO cases with $args['uid'], $args['sid']
            // 1/ already subscribed
            // 2/ was subscribed once and thus only need to update statut
            // 3/ not subscribed at all

            $user_subscr = self::$dbh->fetch_one("SELECT `uid` FROM `user_subscr` WHERE `uid`=:uid AND `sid`=:sid; ", [ ':uid' => $args['uid'], ':sid' => $args['sid'] ]);
            if (isset($user_subscr['uid'])) {
                // This 'if' here is due to a Slim3 bug : $response should be an immutable object, but is not.
                // setting the response here when _PUT_subscribe is called multiple times and only after 
                // returning a response will... overwrtite the second response as string on the previous response.
                if (!isset($args['who'])) { 
                    return $response->withJson( [ 'statut' => 'Done. (...but user was already subscribed...).' ] , 200);
                }
            } else {
                self::$dbh->execute("INSERT INTO `user_subscr` (`uid`, `sid`) VALUES (:uid, :sid); ", [ ':uid' => $args['uid'], ':sid' => $args['sid'] ]);
            }

            $user_status = self::$dbh->fetch_one("SELECT `uid` FROM `user_status` WHERE `uid`=:uid AND `sid`=:sid; ", [ ':uid' => $args['uid'], ':sid' => $args['sid'] ]);;
            if (isset($user_status['uid'])) {
                if (!isset($args['who'])) { // This 'if' here is due to a Slim3 bug (see above)
                    return $response->withJson( [ 'statut' => 'Done (Note : user was already subscribed in the past, just recover it).' ] , 200);
                }
            } else {
                $vector = new NodeVector();
                $vexport = $vector->export();
                $query = " INSERT INTO `user_status` (`uid`, `sid`, `nb_flags`, `cursor`, `bitlist`) VALUES (:uid, :sid, :nb_flags, :cursor, :bitlist); ";
                $qparams = [ 
                    ':uid' => $args['uid'], ':sid' => $args['sid'], 
                    ':nb_flags' => 0, ':cursor' => $vexport['c'], ':bitlist' => $vexport ['v']
                ];
                self::$dbh->execute($query, $qparams);
            }
        }
        if (!isset($args['who'])) { // This 'if' here is due to a Slim3 bug (see above)
            return $response->withJson( [ 'statut' => 'Done.' ] , 200);
        }
    }
    
    public static function _DELETE(Request $request, Response $response, $args){
        // Route : '/section/sid/{sid:[0-9]+}/ImSUREIwantdeleteafullsection/'
        $context = $request->getAttribute('context');

        if (!self::allow_CRUD_on_section($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are neither admin nor superuser of the given section (or error 400, section does not exist).' ] , 403);
        }
        self::$dbh->execute(' DELETE FROM `section` WHERE `sid`=:sid ; ', [ ':sid' => $args['sid'] ] );
        return $response->withJson( [ 'statut' => 'Done. Hope you think before acting.' ] , 200);
    }

} // END class Section