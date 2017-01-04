<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Note : Some function of Class::Session are on the critical path. Each request need auth before lauching.
//        Try to avoid complexity and db request.

class Thread {
  
    private static $dbh;
    private static $logger;

    public static function _init(Container $c) {
        self::$dbh      = $c['dbh']; 
        self::$logger   = $c['logger'];
    }
    
    private static function auth_can_access_section($args, $context) {
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
    
    private static function db_get_threads_range($sid, $start_tid, $end_tid) {
        $query = "
            -- Thread::_GET_page : get thread in range
            SELECT `tid`, `nid`, `pid`, `uid`, `state`, `title`, `realname`, `cdate`
            FROM `node` 
            WHERE sid=:sid AND tid BETWEEN :start_tid AND :end_tid 
            ORDER BY `tid`, `pid` ;
        ";
        $values = [ ':sid' => $sid, ':start_tid' => $start_tid, ':end_tid' => $end_tid ];
        $res = self::$dbh->fetch_all($query, $values);
   
        $threads_table = [];
        foreach ($res as $node) {
            $threads_table[$node['tid']][$node['nid']] = $node;
        }
        // self::$logger->info("Tread::db_get_threads_range : ".print_r($threads_table, true));
        
        return $threads_table;
    }
    
    private static function db_get_threads_list($sid, $list) {
        $query = "
            -- Thread::_GET_page : get thread in range
            SELECT `tid`, `nid`, `pid`, `uid`, `state`, `title`, `realname`, `cdate`
            FROM `node` 
            WHERE sid=:sid AND tid BETWEEN :start_tid AND :end_tid 
            ORDER BY `tid`, `pid` ;
        ";
        $values = [ ':sid' => $sid, ':start_tid' => $start_tid, ':end_tid' => $end_tid ];
        $res = self::$dbh->fetch_all($query, $values);
   
        $threads_table = [];
        foreach ($res as $node) {
            $threads_table[$node['tid']][$node['nid']] = $node;
        }
        // self::$logger->info("Tread::db_get_threads_range : ".print_r($threads_table, true));
        
        return $threads_table;
    }
    
    // remove leafs marked as removed, recursivly
    private static function flatten_and_skip_removed_nodes(&$tree,&$flatten_order) {
        // if all nodes in a branch are removed, uset it
        foreach($tree as $nid => &$node) {
            // create flatten list (need a list with the node in the correct order
            if ($node['state'] !== 'r') {
                array_push($flatten_order, $node['nid']);
            }
            
            // traverses childs
            if (isset($node['childs'])) {
                self::flatten_and_skip_removed_nodes($node['childs'], $flatten_order );
                if (empty($node['childs'])) {
                    unset($node['childs']);
                    if ($node['state'] === 'r') {
                        unset($tree[$nid]);
                    }
                }
            } elseif ($node['state'] === 'r') {
                unset($tree[$nid]);
            }
        }
    }
    
    // set previous and next thread, and unset removed branch with removed nodes
    private static function tree_build_links(&$flat, $flatten_order) {
        $flatten_unread = [];
        for ($i = 0; $i < count($flatten_order); $i++) {
            // set previous
            if ($i > 0) {
                $flat[ $flatten_order[$i] ]['previous'] = $flatten_order[$i-1];
            }
            
            // set next
            if ( $i < count($flatten_order) -1 ) {
                $flat[ $flatten_order[$i] ]['next'] = $flatten_order[$i+1];
            }
            
            // prepare flatten for setting "next unread" 
            if ($i === 0) { // first thread has always a link to the next unread message
                array_push($flatten_unread, $flatten_order[0] );
            } elseif ($flat[ $flatten_order[$i] ]['status'] === 'unread') {
                array_push($flatten_unread, $flatten_order[$i] );
            }
        }
        // add count replies and unread
        $flat[ $flatten_order[0] ]['thread_replies'] = count($flatten_order) - 1 ;
        $flat[ $flatten_order[0] ]['thread_unread']  = count($flatten_unread);
        $flat[ $flatten_order[0] ]['time_read']      = time();
        
        // setting "next unread"
        for ($i = 0; $i < count($flatten_unread); $i++) {
            if ( $i < count($flatten_unread) -1 ) {
                $flat[ $flatten_unread[$i] ]['next_unread'] = $flatten_unread[$i+1];
            }
        }
    }

    private static function make_tree($list, $v) {
//        self::$logger->info("Thread->make_tree. \nThread : ".print_r($list,true));

        // step #1 : 
        //  remake the indexing with nid instead of if 1,2,3...
        //  mark removed node as "read"
        $flat = [];
        foreach ($list as $idx => &$node) {
            //self::$logger->info("Thread->make_tree. \nThread : ".print_r($node,true));
            // mark as read if deleted and not already read
            if ($node['state'] === 'r' AND $v->read($node['nid']) === true) {
                //self::$logger->info("Thread->make_tree. \n DELETED : removed and unread");
                $v->off($node['nid']);
                $node['status'] = 'read';
            } else { // add read/unread status to the node
                $node['status'] = $v->read($node['nid']) === true ? 'unread' : 'read';
            }
            
            // construct new struct (by references)
            $flat[$node['nid']] = &$node;
        }
        //self::$logger->info("Thread->make_tree. \FLAT : ".print_r($flat,true));
        
        // step #2 : add childs to each nodes (by references)
        $tree = [];
        $root_node = -1;
        foreach ($flat as $idx => &$node) {
            //self::$logger->info("Thread->make_tree. \n NODE_PID : ".print_r($node['pid'],true));
            if ($node['pid'] === '-1') {
                // self::$logger->info("Thread->make_tree. \n NODE_PID_root : ".print_r($node,true));
                $tree      = [ $node['nid'] => &$node ];
                $root_node = $node['nid'];
            } else {
                if ( !isset($flat[$node['pid']]['childs']) ) {
                    $flat[$node['pid']]['childs'] = [];
                }
                $flat[$node['pid']]['childs'][$node['nid']] = &$node;
            }
        }
        // self::$logger->info("Thread->make_tree. \n TREE : ".print_r($tree,true));
        $flatten_order =  [];
        self::flatten_and_skip_removed_nodes($tree, $flatten_order);
        self::tree_build_links($flat, $flatten_order);

        return $tree;
    }

    public static function _GET_one(Request $request, Response $response, $args) {
        // Route : '/thread/sid/{sid:[0-9]+}/tid/{tid:[0-9]+}/{what:all|unread}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        $list = self::db_get_thread($args['sid'], $args['tid']);
        if (count($list) === 0) {
            return $response->withJson( [ 'error' => 'No such thread (sid:'.$args['sid'].', tid:'.$args['tid'].')' ] , 404);
        }
        
        $v = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        $tree = self::make_tree($list, $v);
        $v->db_set(); // save vector if has been changed (removed nodes are automagically marked as read)
        
        return $response->withJson( $tree , 200);
    } // END public static function _GET_one

    public static function _GET_page(Request $request, Response $response, $args) {
        // Route : '/thread/sid/{sid:[0-9]+}/{what:showall|showunread}/{pagesize:[0-9]+}/{where:after|before}/[tid/{tid:[0-9]+}/]'
        $context = $request->getAttribute('context');
        
        $median_thread_weight = 3;  // Median is 5, 10% 1, 20% 2. So, 3 must be ok is most cases
        $min_nbthreads_got    = 10; // In case of removed thread, get at least 10 threads to avoid empty pages
        if ($args['pagesize'] < 5) {
            $args['pagesize'] = 5; // set a minimum; it's quite stupid to have a pagesize of 1...
        }
        
        self::$logger->info("Args : ".print_r($args, true));
        
        // Check if authorized
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // Get section infos (needed below : nthread, nnode)
        $section = self::$dbh->fetch_one("
            -- Thread::_GET_page : get infos about one section
            SELECT `nthread`, `nnode` FROM `section` WHERE sid=:sid ;
        ", [ ':sid' => $args['sid'] ]);
        
        // must be set later for output result (page info and pages navigation)
        $page_previous_tid = null; // start of previous page
        $page_next_tid     = null; // start of next page
        $page_start_tid    = null; // begin of current page
        $page_end_tid      = null; // end   of current page
        $page_nb_threads   = 0;
        $page_nb_node      = 0;
        $page              = [];
        $tree              = [];

        $start_tid = 0;
        $end_tid = $section{'nthread'};
            
        $v = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        
        if ($args['where'] === 'before') { // show things before a position
            if (isset($args['tid']) && $args['tid'] !== $section{'nthread'}) {
                $end_tid       = $args['tid'];
                $page_end_tid  = $args['tid'];
                $page_next_tid = $args['tid'] + 1; // else $page_end_tid = null, no change
            } else {
                $page_end_tid  = intval($section{'nthread'});
                $page_next_tid = null;
            }
        } else { // $args['what'] === 'after'

            if (isset($args['tid']) && $args['tid'] !== 0) {
                $start_tid          = $args['tid'];
                $page_start_tid     = $args['tid'];
                $page_previous_tid  = $args['tid'] - 1;
            } else {
                $page_start_tid     = 0;
                $page_previous_tid  = null;
            }     
        }
            
        if ($args['what'] === 'showall') { // show full page, read or unread status does not matter
            // Define $start_thread and $end_thread for sql query
            if ($args['where'] === 'before') { // show things before a position
                $start_tid = $end_tid - 
                        intval( $args['pagesize'] / $median_thread_weight < $min_nbthreads_got 
                                ? $min_nbthreads_got 
                                : $args['pagesize'] / $median_thread_weight );
                if ($start_tid < 0) {
                    $start_tid = 0;
                }
            } else { // $args['what'] === 'after'  
                $end_tid = $start_tid + 
                        intval( $args['pagesize'] / $median_thread_weight < $min_nbthreads_got 
                                ? $min_nbthreads_got 
                                : $args['pagesize'] / $median_thread_weight );
                if ($end_tid > $section['nthread']) {
                    $end_tid = $section['nthread'];
                }
            }
            
//            self::$logger->info("Tread::_GET_page : positions : ".
//                print_r("\nstart_tid $start_tid\nend_tid $end_tid\n"
//                    ."page_end_tid $page_end_tid\npage_next_tid $page_next_tid\n"
//                    ."page_start_tid $page_start_tid\npage_previous_tid $page_previous_tid\n"
//                    ."section{'nthread'} ".$section{'nthread'}."\n", true));
            
            $table_page = self::db_get_threads_range($args['sid'], $start_tid, $end_tid);
            //self::$logger->info("Tread::_GET_page : ".print_r($table_page, true));
        } 
        else { // $args['what'] === 'showunread' (show only threads with unread messages)
            
            // get node list (in sql compliant formt) that are unread 
            // CRITICAL PATH; the most time/CPU expensive part of this software
            
            $unread_node_list = $v->sql_get_on_list($section['nnode'], 'node.nid');
            self::$logger->info("Tread::_GET_page : unread_node_list : ".print_r($unread_node_list, true));

            // just for profiling : get all vector (uid/sid), for all users and all section
            /*    $allv= self::$dbh->fetch_all("SELECT user_status.`uid`,user_status.`sid`, section.nnode FROM `user_status` , section WHERE section.sid = user_status.sid ; ");
                $vlist = "";
                foreach ($allv as $suv) {
                    $v = NodeVector::db_get(self::$dbh, $suv['uid'], $suv['sid']);
                    $vlist .= "--- sid : ".$suv['sid']." --- ".$v->sql_get_on_list($suv['nnode'], 'thrnum').".\n";
                }
                self::$logger->info("Tread::_GET_page : ALL unread : \n$vlist\n");
            */

            // $unread_node_list is empty -> there is no new message -> RETURN
            if ($unread_node_list == '') {
                return $response->withJson( 
                    [   'tree' => [],
                        'page_time_read' => time(),
                        'page_nb_threads' => 0,
                        'page_nb_node' => 0
                    ] , 200);
            }

            // select all threads that contain this messages
            $unreads_tid_list = self::$dbh->fetch_list("
                    -- Thread::_GET_page : get thread wich contain unread messages
                    SELECT `tid` FROM `node` 
                    WHERE $unread_node_list  
                        AND sid=:sid 
                    GROUP BY `tid`
                    ORDER BY `tid`
                ", [':sid' => $args['sid']] );
            $unreads_tid = implode(', ', $unreads_tid_list);
//            self::$logger->info("Tread::_GET_page : $unreads_tid  \n");
            
            // get this threads, limite by page size ("tid IN (2,5,7,11, ...)")
            $unreads_threads = self::$dbh->fetch_all("
                    -- Thread::_GET_page : get thread in range
                    SELECT `tid`, `nid`, `pid`, `uid`, `state`, `title`, `realname`, `cdate`
                    FROM `node` 
                    WHERE 
                        tid IN ($unreads_tid) 
                        AND sid=:sid 
                    ORDER BY `tid`, `pid` ;
                ", [':sid' => $args['sid']] );
            $table_page = [];
            foreach ($unreads_threads as $node) {
                $table_page[$node['tid']][$node['nid']] = $node;
            }
//            self::$logger->info("Tread::_GET_page : \n".print_r($unreads_threads,true));
            
//            return $response->withJson( [ 'error' => 'in progress...().' ] , 404);
        }
        
        $parents_list = array_keys($table_page);
        if ($args['where'] === 'before') {
           $parents_list = array_reverse($parents_list); // if "before tid", need to construct response in reverse order (last first)
        }

        self::$logger->info("Tread::_GET_page : parents_list : ".print_r($parents_list, true));

        foreach($parents_list as $tid) { // break if page size limit is reached
//            self::$logger->info("Tread::_GET_page : foreach(parents_list as tid) : ".print_r($tid, true));

            $thread = self::make_tree($table_page[$tid], $v);
            $thread_value = reset($thread);
            //self::$logger->info("Tread::_GET_page : thread : ".print_r($thread_value, true));

            if (($page_nb_node + 1 + 1 + $thread_value['thread_replies']) >= $args['pagesize'] && $page_nb_node > 0) {
                // page is full and return result is not empty. No need for more. Stop.
                break;
            }
            $page_nb_node    += 1 + $thread_value['thread_replies'];
            $page_nb_threads += 1;

            if ($args['where'] === 'before') {
                array_unshift($tree, $thread);
                $page_start_tid    = $tid;
                $page_previous_tid = $tid -1;
                if ($page_previous_tid <= 0) {
                    $page_previous_tid = null;
                }
            } else { // $args['what'] === 'after'
                array_push($tree, $thread);
                $page_end_tid  = $tid;
                $page_next_tid = $tid + 1;
                if ($page_next_tid >= $section{'nthread'}) {
                    $page_next_tid = null;
                }
            }
        }
        
        // TODO TEST si un thread au mileu de tout ça est complètement effacé ?
        // TODO TEST si tous les threads requis sont effacés ?
        // TODO TEST si un message par thread, et taille pagesize non atteinte ?
        
        $v->db_set(); // save vector if has been changed (removed nodes are automagically marked as read)
        
        $page['tree']             = $tree;
        $page['page_time_read']   = time();
        $page['page_nb_threads']  = $page_nb_threads;
        $page['page_nb_node']     = $page_nb_node;
        $page['section_nb_nodes'] = $section['nnode'];
        if (!is_null($page_previous_tid)) {
            $page['page_previous_tid'] = $page_previous_tid;
        }
        if (!is_null($page_next_tid)) {
            $page['page_next_tid'] = $page_next_tid;
        }
        if (!is_null($page_start_tid)) {
            $page['page_start_tid'] = $page_start_tid;
        }
        if (!is_null($page_end_tid)) {
            $page['page_end_tid'] = $page_end_tid;
        }     
        
        // if error in returning empty page : check the return above
        return $response->withJson( $page , 200);
    } // END public static function _GET_page
    
    
    public static function _PUT_one (Request $request, Response $response, $args) { // mark as "read" before $param[time]
        // Route : '/thread/sid/{sid:[0-9]+}/tid/{tid:[0-9]+}/time/{time:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // get the thread with node time < args(time), vector, off_list(), return ok (does NOT return the page !)
        $target_nodes = self::$dbh->fetch_list("
            -- Thread::_PUT_one : mark one thread as read
            SELECT `nid` FROM `node` WHERE tid=:tid AND sid=:sid AND cdate <= FROM_UNIXTIME(:time)
        ", [':sid' => $args['sid'], ':tid' => $args['tid'], ':time' => $args['time']] );
        
        $v = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        self::$logger->info("Tread::_PUT_one : mark as read : ".print_r($target_nodes, true));
        
        $v->off_list($target_nodes);
        $v->db_set();

        return $response->withJson( ['message' => "OK"] , 200);
    } // END public static function _PUT_one

    
    public static function _PUT_page (Request $request, Response $response, $args) { // mark as "read" before $param[time]
        // Route : '/thread/sid/{sid:[0-9]+}/fromtid/{fromtid:[0-9]+}/totid/{totid:[0-9]+}/time/{time:[0-9]+}/'
        $context = $request->getAttribute('context');

        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // get all thread in page with node time < args(time), vector, off_list(), return ok (does NOT return the page !)
        $target_nodes = self::$dbh->fetch_list("
            -- Thread::_PUT_page : mark page as read
            SELECT `nid` FROM `node` WHERE tid >= :fromtid AND tid <= :totid AND sid=:sid AND cdate <= FROM_UNIXTIME(:time)
        ", [':sid' => $args['sid'], ':totid' => $args['totid'], ':fromtid' => $args['fromtid'], ':time' => $args['time']] );
        
        $v = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
        self::$logger->info("Tread::_PUT_page : mark as read : ".print_r($target_nodes, true));

        $v->off_list($target_nodes);
        $v->db_set();

        return $response->withJson( ['message' => "OK"] , 200);
    } // END public static function _PUT_page
    
    
    public static function _PUT_all (Request $request, Response $response, $args) { // mark as "read" before $param[time]
        // Route : '/thread/sid/{sid:[0-9]+}/lastnode/{lastnode:[0-9]+}/'
        $context = $request->getAttribute('context');
        
        if (!self::auth_can_access_section($args, $context)) {
            return $response->withJson( [ 'error' => 'No Auth to this section. You are probably not subscribed to.' ] , 403);
        }
        
        // get nnode, get vector, off_all(), return ok (does NOT return the page !)

        $v = NodeVector::db_get(self::$dbh, $context['uid'], $args['sid']);
                
        $v->off_all($args['lastnode']);
        $v->db_set();
        
        return $response->withJson( ['message' => "OK"] , 200);
    } // END public static function _PUT_all
    
} // END class Thread