<?php

/**
 * Description of Group
 *
 * @author FroidevauxPh
 * 
 */

// Note : Some function of Class::Session are on the critical path. Each request need auth before lauching.
//        Try to avoid complexity and db request.

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class Group {
    
    private static $dbh;
    private static $logger;

    public static function _init(Container $c)
    {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }
    
        
    private static function auth_is_admin($context) {
        if ($context['role'] === 'a') {
            return true;
        }
        return false;
    }
    
    private static function auth_is_superuser($args, $context) {
        //self::$logger->debug("Group->auth_is_superuser\nargs:".print_r($args,true)."\n$context:".print_r($context,true));
        if (!isset($args['gid'])) {
            self::$logger->error("Group::auth_is_superuser - \$args[\'gid\'] is not set !");
        }
        if ($context['role'] === 'a' || ( $context['role'] === 's' && ($context['gid'] === $args['gid']) ) ) {
            return true;
        } else {
            return false;
        }
    }
    
    private static function is_name_unique($groupname) {
        $query = ' SELECT `gid` FROM `group` WHERE groupname=:groupname ; ';
        $res = self::$dbh->fetch_one($query, [ ':groupname' => $groupname ]);
        return $res ? true : false;
    }
    
    public static function _POST(Request $request, Response $response, $args){
        // Route : '/group/'
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::auth_is_admin($context)) {
            return $response->withJson(  ['error' => 'Unauthorised. You are not admin, right ?' ], 403);
        }
        
        if ( !isset($bodyJson['groupname']) ) {
            return $response->withJson(  ['error' => 'Bad request. Missing groupname.' ], 400);
        }
        if (isset($bodyJson['groupname']) && ( strlen($bodyJson['groupname']) < 4 || self::is_name_unique($bodyJson['groupname'])) ) {
            return $response->withJson(  ['error' => 'Bad request. Group name length < 4, or duplicate group name.' ], 400);
        }
        $bodyJson['groupdescr'] = isset($bodyJson['groupdescr']) ? $bodyJson['groupdescr'] : 'Description of your group here.';
        self::$dbh->execute('INSERT INTO `group` ( `groupname`, `groupdescr`) VALUES ( :groupname, :groupdescr ) ;', 
            [ ':groupname' => $bodyJson['groupname'], ':groupdescr' => $bodyJson['groupdescr'], ]);
        $args['gid'] = self::$dbh->last_insert_id(); 
        return self::_GET($request, $response, $args);
    }
    
    public static function _GET(Request $request, Response $response, $args){
        // Route : '/group/gid/{gid:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if ($args['gid'] !== $context['gid']) {
            if (!self::auth_is_admin($context)) {
                return $response->withJson(  ['error' => 'Unauthorised. You are not admin, right ?' ], 403);
            }
        }
        $query = ' SELECT `gid`, `groupname`, `groupdescr` FROM `group` WHERE `gid`=:gid; ';
        return $response->withJson( self::$dbh->fetch_one($query, [ ':gid' => $args['gid'] ]), 200);
    }
    
    public static function _GET_all(Request $request, Response $response, $args){
        // Route : '/group/all/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_is_admin($context)) {
            return $response->withJson(  ['error' => 'Unauthorised. You are not admin, right ?' ], 403);
        }
        $query = ' SELECT `gid`, `groupname` FROM `group` ; ';
        return $response->withJson( self::$dbh->fetch_all($query, []), 200);
    }
       
    public static function _PUT(Request $request, Response $response, $args){
        // Route : '/group/gid/{gid:[0-9]+}/'
        $context = $request->getAttribute('context');
        $bodyJson = $request->getAttribute('bodyJson');
        
        if (!self::auth_is_admin($context)) {
            return $response->withJson(  ['error' => 'Unauthorised. Must be admin.' ], 403);
        }
        if (!self::auth_is_superuser($args, $context)) {
            return $response->withJson(  ['error' => 'Unauthorised. Must be superuser of your group.' ], 403);
        }
        
        if (!isset($bodyJson['groupname']) AND !isset($bodyJson['groupdescr'])) {
            return $response->withJson(  ['error' => 'Bad request. Missing group name or descr.' ], 400);
        }
        if (isset($bodyJson['groupname']) AND ( strlen($bodyJson['groupname']) < 4 OR self::is_name_unique($bodyJson['groupname'])) ) {
            return $response->withJson(  ['error' => 'Bad request. Group name already used, or name length < 4.' ], 400);
        }
        
        if (isset($bodyJson['groupname'])) {
            $query = " UPDATE `group` SET `groupname`=:groupname WHERE `gid`=:gid ; ";
            self::$dbh->execute($query, [ ':gid' => $args['gid'], ':groupname' => $bodyJson['groupname'] ]);
        } 
        if (isset($bodyJson['groupdescr'])) {
            $query = " UPDATE `group` SET `groupdescr`=:groupdescr WHERE `gid`=:gid ; ";
            self::$dbh->execute($query, [ ':gid' => $args['gid'], ':groupdescr' => $bodyJson['groupdescr'] ]);
        }
        return self::_GET($request, $response, $args);
    }
    
    public static function _DELETE (Request $request, Response $response, $args) {
        // Route : '/group/gid/{gid:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_is_admin($context) OR $context['gid'] === $args['gid']) {
            return $response->withJson(  ['error' => 'Unauthorised. You are not admin, or... you try to delete your own group.' ], 403);
        }
        self::$dbh->execute( ' DELETE FROM `group` WHERE `gid`=:gid ; ', [ ':gid' => $args['gid'] ]);
        return $response->withJson(  ['statut' => 'Ok, group deleted (I hope you did the right thing...).' ], 200);
    }

} // END class Group