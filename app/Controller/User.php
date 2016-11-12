<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class User {
    
    private static $dbh;
    private static $logger;

    public static function _init(Container $c)
    {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }
    
    private static function checkPasswordStreng($password) {
        // I wont check for special chars, it's a friends discussion forum, not a FBI X-Files repository
        if ( strlen( $password ) < 12 ) {
            // 'Password empty ot too short (min 12 chars, and better with 2 specials, please --> http://xkcd.com/936/ ).';
            return false;
        }
        return true;
    }
    
    private static function auth_is_admin($context) {
        // NOTE $args must be set with the gid BEFORE checking for auth...
        if ($context['role'] === 'a') {
            return true;
        }
        return false;
    }
    
    private static function auth_is_superuser($args, $context) { // need $args['gid']
        // NOTE $args must be set with the gid BEFORE checking for auth...
        if (!isset($args['gid'])) {
            self::$logger->error("User::auth_is_superuser - \$args[\'gid\'] is not set !");
        }
        if ($context['role'] === 's' and $args['gid'] == $context['gid']) {
            return true;
        }
        return false;
    }
    
    private static function auth_is_current_user($args, $context) { // need $args['uid']
        // NOTE $args must be set with the gid BEFORE checking for auth...
        if (!isset($args['uid'])) {
            self::$logger->error("User::auth_is_current_user - \$args[\'uid\'] is not set !");
        }
        if ($args['uid'] === $context['uid']) {
            return true;
        }
        return false;
    }

    public static function _POST(Request $request, Response $response, $args){
        // Route : '/user/gid/{gid:[0-9]+}/'
        $context  = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');

        if ($args['gid'] !== $context['gid'] AND !self::auth_is_admin($context)) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are neither admin nor superuser of this group.' ] , 403);
        }
        
        if (!self::auth_is_superuser($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are not superuser of this group.' ] , 403);
        }
        
        if (    !isset( $bodyJson['username'] )
             OR !isset( $bodyJson['email'] )
             OR !isset( $bodyJson['realname'] )
             OR !isset( $bodyJson['passwd'] ) ) {
            return $response->withJson( [ 'error' => 'Bad request, missing parameters. Mandatories : username, email, realname, passwd.' ] , 400);
        }
        
        if (isset($bodyJson['passwd']) ) {
            if (!self::checkPasswordStreng($bodyJson['passwd'])) {
                return $response->withJson( [ 'error' => 'Bad request, password is not good enough (min 12 chars, so far).' ] , 400);
            }
            $bodyJson['passwd_salt'] = sha1($bodyJson['passwd'].rand());
            $bodyJson['passwd'] = sha1($bodyJson['passwd'].$bodyJson['passwd_salt']);
        }
        
        // username is UNIQUE in database, so check if already exists
        $test_username_unique = self::$dbh->fetch_one(" SELECT `username` FROM `user` WHERE `username`=:username ;", [ ':username' => $bodyJson['username'] ]);
        if (isset($test_username_unique['username'])) { 
            return $response->withJson( [ 'error' => 'Username must be unique. This one is already in use (maybe in another group).' ] , 400);
        }
        
        $bodyJson['role']     = isset($bodyJson['role'])     ? $bodyJson['role']     : 'u';
        $bodyJson['viewmax']  = isset($bodyJson['viewmax'])  ? $bodyJson['viewmax']  : 50;
        $bodyJson['language'] = isset($bodyJson['language']) ? $bodyJson['language'] : 'en';
        $bodyJson['sig']      = isset($bodyJson['sig'])      ? $bodyJson['sig']      : "\n".$bodyJson['realname']."\n\n--\nMy signature here.";
        
        $bodyJson['gid']        = $args['gid'];
        $bodyJson['last_login'] = 0;
        $bodyJson['last_post']  = 0;

        $query = "
            INSERT INTO `user` (`gid`, `username`, `email`, `realname`, `role`, `viewmax`, `language`, `last_login`, `last_post`, `passwd`, `passwd_salt`, `sig`) 
            VALUES (:gid, :username, :email, :realname, :role, :viewmax, :language, :last_login, :last_post, :passwd, :passwd_salt, :sig) ; 
        ";
        self::$dbh->execute($query, $bodyJson);
        $args['uid'] = self::$dbh->last_insert_id();
        return self::_GET_one($request, $response, $args);
    }
    
    public static function _GET_one(Request $request, Response $response, $args){
        // Route : '/user/uid/{uid:[0-9]+}/'
        $context = $request->getAttribute('context');

        // Auth is done after getting user - to check if superuser is in the same gid
        
        $query = "
            SELECT `uid`, `gid`, `username`, `email`, `realname`, `role`, 
                `viewmax`, `language`, `last_login`, `last_post`, `sig`
            FROM `user`
            WHERE uid=:uid ;
        ";
        $user = self::$dbh->fetch_one($query, [ ':uid' => $args['uid'] ]);
        
        // Auth
        $args['gid'] = $user['gid'];
        if (!self::auth_is_admin($context) AND !self::auth_is_superuser($args, $context) AND !self::auth_is_current_user($args, $context)) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are not admin, superuser or the requested user.' ] , 403);
        }
        
        // get the section the user is subcribed to
        $query = '
            SELECT user_subscr.sid
            FROM user_subscr INNER JOIN user ON user.uid=user_subscr.uid
            WHERE user.uid=:uid;
        ';
        $user['subscr'] = self::$dbh->fetch_list($query, [ ':uid' => $args['uid'] ]);
              
        return $response->withJson( $user , 200);
    }
    
    public static function _GET_all(Request $request, Response $response, $args){
        // Route : '/user/gid/{gid:[0-9]+}/all'
        $context = $request->getAttribute('context');

        if ( !self::auth_is_admin($context) AND !self::auth_is_superuser($args, $context) ) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are neither admin nor superuser.' ] , 403);
        }
        
        $query = '
            SELECT `uid`, `gid`, `role`, `username`, `realname`, `email`, 
            `language`, `last_login`, `last_post`
            FROM `user` 
            WHERE `gid`=:gid
        ';
        $res = self::$dbh->fetch_all($query, $args);

        return $response->withJson( $res , 200);
    }
    
    public static function _PUT(Request $request, Response $response, $args){
        // Route : '/user/uid/{uid:[0-9]+}/'
        $context  = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');

        if ($args['uid'] !== $context['uid']) { // request is for another user
            $query = ' SELECT `gid`, `passwd_salt` FROM user WHERE user.uid=:uid; ';
            $user = self::$dbh->fetch_one($query, [ ':uid' => $args['uid'] ]); // get gid...
            
            if ($user['gid'] !== $context['gid']) {
                if (!self::auth_is_admin($context)) {
                    return $response->withJson( [ 'error' => 'Unauthorised. You are not admin.' ] , 403);
                }
            } else {
                $args['gid'] = $user['gid'];
                if (!self::auth_is_superuser($args, $context)) {
                    return $response->withJson( [ 'error' => 'Unauthorised. You are not superuser.' ] , 403);
                }
            }
        }
        
        if (isset($bodyJson['passwd']) ) {
            if (!self::checkPasswordStreng($bodyJson['passwd'])) {
                return $response->withJson( [ 'error' => 'Bad request, password is not good enough (min 12 chars, so far).' ] , 400);
            }
            $bodyJson['passwd_salt'] = sha1($bodyJson['passwd'].rand());
            $bodyJson['passwd'] = sha1($bodyJson['passwd'].$res['passwd_salt']);
        }
        
        // could be called field by field, or with a big PUT
        // No performance needed here, it's not on the critical path

        $fieds = [ 'username', 'email', 'realname', 'role', 'viewmax', 'language', 'passwd', 'passwd_salt', 'sig' ];
        $bodyJson['uid'] = $args['uid'];
        //for each fields, if defines, do upddate
        foreach($fieds as $f) {
            if (isset($bodyJson[$f])) {
                $query = " UPDATE `user` SET `$f` =:$f WHERE user.uid=:uid ; ";
                self::$dbh->execute($query, $bodyJson);
            }
        }
        
        return self::_GET_one($request, $response, $args);
    }
    
    public static function _DELETE(Request $request, Response $response, $args){
        // Route : '/user/uid/{uid:[0-9]+}/Iknowwhatimdoing/'
        $context = $request->getAttribute('context');

        $query = " SELECT `gid` FROM `user` WHERE uid=:uid ; ";
        $user = self::$dbh->fetch_one($query, [ ':uid' => $args['uid'] ]);
        
        $args['gid'] = $user['gid'];
        if (!self::auth_is_admin($context) AND !self::auth_is_superuser($args, $context) ) {
            return $response->withJson( [ 'error' => 'Unauthorised. You are neither admin nor superuser.' ] , 403);
        }
        
        self::$dbh->execute(" DELETE FROM `user` WHERE uid=:uid ; ", [ ':uid' => $args['uid'] ]);
        
        return $response->withJson( [ 'statut' => 'Done. You KELL HIM.' ] , 200);
    }

} // END class Users