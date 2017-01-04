<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class Search {
    /*
     * SEARCH : 
     * FULLTEXT indexes are different from all of the above, and their behaviour
     * differs significantly between database systems. FULLTEXT indexes are only
     * useful for full text searches done with the MATCH() / AGAINST() clause,
     * unlike the above three - which are typically implemented internally using
     * b-trees (allowing for selecting, sorting or ranges starting from left 
     * most column) or hash tables (allowing for selection starting from left 
     * most column).
     * 
     */
    
    private static $dbh;
    private static $logger;
    
    private static $search_limit = 50;                 // defaut min numbers of search result viewed
    private static $defaut_time_searched = 3600*24*10*10; // search 10 days days in the past per default


    public static function _init(Container $c) {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }
    
    private static function get_authorised_section($context) {
        $query = "
            SELECT s.`sid` 
            FROM `section` AS s, `user_subscr` AS usu
            WHERE s.`gid`=:gid AND (( usu.sid=s.sid AND usu.uid=:uid ) OR s.self_sub='y' )
            GROUP BY s.sid ;
        ";
        $qparams[':gid'] = $context['gid'];
        $qparams[':uid'] = $context['uid'];
        $res = self::$dbh->fetch_list($query, $qparams);
        return $res;
    }
    
    private static function allow_search_because_is_authorised($sid_list, $context) {
        // just check if user is subscribed to the section. Cannot search if not subscribed
        self::$logger->debug("Search->allow_search_is_subscribed --> args:".print_r($sid_list,true));
        self::$logger->debug("Search->allow_search_is_subscribed --> context:".print_r($context,true));

        $section_available = self::get_authorised_section($context);
//        self::$logger->debug("Search->allow_search_is_subscribed --> section_available:".print_r($section_available,true));

        $sid_array = explode(',',$sid_list);
        foreach ($sid_array as $sid) {
            if (!ctype_digit($sid)) {
                return false;
            }
        }
        $subscr_match = array_intersect($section_available, $sid_array);
        
//        self::$logger->debug("Search->allow_search_is_subscribed --> 1:".print_r($sid_array,true));
//        self::$logger->debug("Search->allow_search_is_subscribed --> 2:".print_r($subscr_match,true));
//        self::$logger->debug("Search->allow_search_is_subscribed --> 3:".print_r(array_diff($sid_array, $subscr_match),true));
        
        if (count(array_diff($sid_array, $subscr_match)) === 0) {
            return true;
        }
        return false;
    }
    
    
    public static function _GET_help (Request $request, Response $response, $args) {
        // Route : '/search/help/'

        return $response->withJson( [ 'help' => "

    [For users] Boolean search examples :

    lorem ipsum
        Match either lorem, ipsum, or both
    +lorem ipsum
        Match lorem, and ipsum is optionnal, if ipsum too is found, the row should score higher
    +lorem +ipsum
        Match both lorem and ipsum
    +lorem -ipsum
        Match lorem but not ipsum
    +lorem dolor -ipsum
        Match lorem but not ipsum, dolor is optionnal and the row should score higher 
    +lorem ~ipsum
        Match lorem, but mark down as less relevant rows that contain ipsum
    +lorem*
        Match lorem, loremly, loremty, lorem ipsum, etc
    \"lorem ipsum\"
        Match the exact term \"nice ipsum\"
    +lorem +(ipsum dolor)
        Match either \"lorem ipsum\" or \"lorem dolor\"
    (+lorem +ipsum) (\"lorem ipsum\")
        Match both lorem and ipsum. If sequential, in exactly that order, it’ll score higher than if both words are scattered around the text
    +lorem +(>ipsum <dolor)
        Match either \"lorem ipsum\" or \"lorem dolor\", with rows matching \"lorem ipsum\" being considered more relevant
    >lorem <ips
        Match either lorem or anything starting with ips. Matches with lorem should score higher than matches with words starting with ips

    [For devs, not for users] Args list
      ? sid_list = 1,2,3,70,... (no default - mandatory)
      & search_pattern (min 4 chars, and/or search_author) (default) ''
      & search_type   = boolean | (default) natural
      & date_from     = unixtime in second (default) now -
      & date_to       = unixtime in second (default) now - ".self::$defaut_time_searched."
      & limit_start   = (default) 0
      & limit_end     = (default) ".self::$search_limit."

    search_author  is done by LIKE %search_author%
    search_pattern is done by MATCH(realname, titel, body), with search_type : boolean or natural (default : natural)
    
        " ] , 200);

    } // END public static function _GET_help
    
    
    public static function _GET_node (Request $request, Response $response, $args) {
        // Route : '/search/'
        $context = $request->getAttribute('context');
        $params  = $request->getParams();
        self::$logger->debug("Search->_GET --> params:".print_r($params,true));
        
        
        if (!isset($params['search_author']) AND !isset($params['search_pattern'])) {
            return $response->withJson([ 'error' => "Param \'search_author\' and/or \'search_pattern\' must be set" ] , 400);
        }

        if (      (isset($params['search_pattern']) AND strlen($params['search_pattern']) < 4) 
               OR (isset($params['search_author'])  AND strlen($params['search_author'])  < 4 ) ) {
            return $response->withJson([ 'error' => " Params \'search_author\' and \'search_pattern\' must be have at least 4 chars." ] , 400);
        }

        $params['search_type'] = isset($params['search_type']) ? $params['search_type'] : 'natural';
        if ($params['search_type'] !== 'boolean' AND $params['search_type'] !== 'natural') {
            return $response->withJson( [ 'error' => 'Parameter \'search_type\' must be \'boolean\' or \'natural\'.' ] , 400);
        }

        $params['date_from']   = isset($params['date_from'])   ? $params['date_from']   : time() - self::$defaut_time_searched;
        $params['date_to']     = isset($params['date_to'])     ? $params['date_to']     : time();
        $params['limit_start'] = isset($params['limit_start']) ? $params['limit_start'] : 0;
        $params['limit_end']   = isset($params['limit_end'])   ? $params['limit_end']   : self::$search_limit;
        
        if (!ctype_digit(strval($params['limit_start'])) OR !ctype_digit(strval ($params['limit_end']))) {
            self::$logger->debug("Search->_GET --> limit_start/limit_end : '".$params['limit_start']."' , '".$params['limit_end']."'...");
            return $response->withJson( [ 'error' => 'Parameter \'limit_start\' and \'limit_end\' must be integers only. I saw you.' ] , 400);
        }
        
        if (!isset($params['sid_list'])) {
            return $response->withJson( [ 'error' => 'Parameter \'sid_list\' required and not found.' ] , 400);
        } else {
            // Check if sid_list is only numbers and ',' - sql injection possible here
            // Note : no alert, just remove unwanted chars
            preg_replace("/[^0-9,]/", "", $params['sid_list']);
        }
        
        // Auth : check if user is subscribed to {sid_list}
        if (!self::allow_search_because_is_authorised($params['sid_list'], $context)) {
            return $response->withJson( 
                [ 'error' => 'You must be subscribed to sections, or section must be open for subscription, or sid_list=1,2,n malformed.' ] 
                , 403);
        }
        
        // Build sql search pattern

        // lesson : https://www.mullie.eu/mysql-as-a-search-engine/

        //  sql IN() pattern can't be acheived with PDO, so... But params are checked before, it's ok here
        
        // (MATCH (name) AGAINST ('black' IN BOOLEAN MODE) * 3) + (MATCH (keywords) AGAINST ('black' IN BOOLEAN MODE)*2) as relevance
        // ORDER BY (relevance_1 * 3) + (relevance_2 * 2) + relevance_3 DESC
        
        //  From mysql manual: For natural-language full-text searches, it is a requirement that the 
        //   columns named in the MATCH() function be the same columns included in some FULLTEXT index 
        //   in your table. [...] If you wanted to search the title or body separately, you would need 
        //   to create separate FULLTEXT indexes for each column.
   
        $match_type = ($params['search_type'] === 'boolean') ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
        
        //         // '".$params['search_pattern']."'
         
        $query = "
            SELECT n.`sid`, n.`nid`, n.`tid`, n.`pid`, n.`title`, n.`cdate`, n.`realname`,
                s.`sectionname`,
                MATCH (n.`realname`, n.`title`, n.`body`) AGAINST (:search_pattern $match_type) AS relevance 
            FROM   `node` AS n, `section` AS s
            WHERE   s.`gid`=:gid
                AND n.`state` = 'p'
                AND n.`sid` = s.`sid`
                AND n.`sid` IN (".$params['sid_list'].")
                AND n.`cdate` BETWEEN FROM_UNIXTIME(:date_from) AND FROM_UNIXTIME(:date_to)
                AND MATCH (n.`realname`, n.`title`, n.`body`) AGAINST (:search_pattern $match_type)
            ORDER BY relevance DESC
            LIMIT ".$params['limit_start']." , ".$params['limit_end']." ;
        ";
        $qparams = [];
        $qparams[':gid']            = $context['gid'];
        $qparams[':search_pattern'] = $params['search_pattern'];
        $qparams[':date_from']      = $params['date_from'];
        $qparams[':date_to']        = $params['date_to'];

        self::$logger->debug("Search::_GET: \n -----> params are : \n".print_r($qparams,true));

        // Compute result

        $res = self::$dbh->fetch_all($query, $qparams);
        return $response->withJson(  $res  , 200);
        
    } // END public static function _GET_node

    
} // END class Search