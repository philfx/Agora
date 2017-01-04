<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// EMPTY, just a template. Has to be done later. Want to test 
// - Excel (CSV) exports with REST
// - (no static class with Slim)
// - use of graphical tool in javascript for data representation
// - make a cleaner auth system for agora

/* TODO get stats ? (add a route which return a CSV or Excel file with stat, to be used in an Pivot Table)
 * 
 * user post over the years : user, group by count nb_post_month (count post + deleted?)
 * user post by day/hour : user, group by count nb_post hour (count post + deleted?)
 * 
 * params : 
 *      everybody or by user
 *      overs the time or by hours/day of the week
 *      count deleted (show how much people delete)
 *      by section over the time
 * 
 * SELECT   CONCAT(HOUR(created), ':00-', HOUR(created)+1, ':00') AS Hours
 *   ,      COUNT(*) AS `usage`
 * FROM     history
 * WHERE    created BETWEEN '2012-02-07' AND NOW()
 * GROUP BY HOUR(created)
 * 
 * SELECT [activity_dt], count(*)
 * FROM table1
 * GROUP BY hour( activity_dt ) , day( activity_dt )
 * 
 * add a rest func with auth to admin only, and a php page wich return Excel --> http://phpexcel.codeplex.com/
 * then use Pivot Table inside Excel for graphs
 * (phpoffice on github, also)
 * 
 */
        
class Statistics {
  
    private static $dbh;
    private static $logger;

    public static function _init(Container $c) {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }
    
    private static function auth_can_access_statistics($args, $context) {
        // if section is self_subcr or user is subscribed, ok
        // Note : unsubscribed users cannot access section's content
        if (in_array($args['sid'], $context['subscr'])) {
            return true;
        } else {
            return false;
        }
    }
    
    private static function db_get_thread($sid, $tid) {
        $query = "
            -- Thread::_GET_page : get one thread
            SELECT `tid`, `nid`, `pid`, `uid`, `state`, `title`, `realname`, `cdate`
            FROM `node` 
            WHERE sid=:sid AND tid=:tid
            ORDER BY `pid`;
        ";
        $values = [ ':sid' => $sid, ':tid' => $tid ];
        $res = self::$dbh->fetch_all($query, $values);
        return $res;
    }

    public static function _GET(Request $request, Response $response, $args) {
        // Route : '/thread/sid/{sid:[0-9]+}/tid/{tid:[0-9]+}/{what:all|unread}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        $list = self::db_get_thread($args['sid'], $args['tid']);
        if (count($list) === 0) {
            return $response->withJson( [ 'error' => 'No such thread (sid:'.$args['sid'].', tid:'.$args['tid'].')' ] , 404);
        }
        
        return $response->withJson( [ 'error' => 'Not implemented yet (called class::method is Statistics::_GET().' ] , 404);
        
        return $response->withJson( $tree , 200);
    } // END public static function _GET


} // END class Statistics