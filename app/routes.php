<?php
// Routes

// Middleware set $context (user, token). Retrieve with $request->getAttribute('context');

// Token *must* be passed with every request (but /session->POST)
// It can be passed as : HTTP_REQUEST, Cookie, args, or bodyJson
// Read Middleware.php for more infos !
 
/* get stats ? (add a route which return a CSV or Excel file with stat, to be used in an Pivot Table)
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

// Thread (and pages) management

$app->get('/thread/sid/{sid:[0-9]+}/tid/{tid:[0-9]+}/', '\Thread:_GET_one');
$app->get('/thread/sid/{sid:[0-9]+}/{what:showall|showunread}/{pagesize:[0-9]+}/{where:after|before}/[tid/{tid:[0-9]+}/]', '\Thread:_GET_page'); //  ? 

// {where:showafter|showbefore}/{pagesize:[0-9]+}/{what:all|unread)/[tid/{tid:[0-9]+}/]

$app->put('/thread/sid/{sid:[0-9]+}/tid/{tid:[0-9]+}/time/{time:[0-9]+}/',
        '\Thread:_PUT_one'); // mark read
$app->put('/thread/sid/{sid:[0-9]+}/fromtid/{fromtid:[0-9]+}/totid/{totid:[0-9]+}/time/{time:[0-9]+}/',
        '\Thread:_PUT_page'); // mark read

$app->put('/thread/sid/{sid:[0-9]+}/lastnode/{lastnode:[0-9]+}/', '\Thread:_PUT_all'); // mark all as read before lastnode


// Session management

$app->post('/session/', '\Session:_POST');
$app->get('/session/',        '\Session:_GET');
$app->get('/session/device/', '\Session:_GET_device');
$app->delete('/session/',                            '\Session:_DELETE_token'); // current token
$app->delete('/session/token/{token:[A-Za-z0-9]+}/', '\Session:_DELETE_token'); // other token
$app->delete('/session/uid/{uid:[0-9]+}/',           '\Session:_DELETE_uid');   // all token for a given user

// Group management 
// - user and superuser can "get current group"
// - superuser can "put group" (edit its own group).
// - admin is the only one who can get_all, create, or delete group
// - role "admin" allow to create/edit/delete groups, add a user in a group, but NOT do other operation on other groups (or only because API flaw)
// - role "admin" is also superuser of his own group

$app->post('/group/', '\Group:_POST'); // {"groupname":"testgroup","groupdescr":"xxx"}
$app->get('/group/gid/{gid:[0-9]+}/', '\Group:_GET');
$app->get('/group/all/',              '\Group:_GET_all'); 
$app->put('/group/gid/{gid:[0-9]+}/', '\Group:_PUT');
$app->delete('/group/gid/{gid:[0-9]+}/Wow-wow-wow-iamreallysuretodeleteawholegroupeandmillionsmessages/', '\Group:_DELETE'); // TODO /Iknowwhatimdoing

// User management
// ('gid' is use here because "admin" should be able to create the first user of a new group, and review/manage users of a given group)

$app->post('/user/gid/{gid:[0-9]+}/', '\User:_POST');  // { name, login, sign, passwd, role }
$app->get('/user/uid/{uid:[0-9]+}/',     '\User:_GET_one');
$app->get('/user/gid/{gid:[0-9]+}/all/', '\User:_GET_all');
$app->put('/user/uid/{uid:[0-9]+}/',    '\User:_PUT');     
$app->delete('/user/uid/{uid:[0-9]+}/Iknowwhatimdoing/', '\User:_DELETE');

// Section management

$app->post('/section/', '\Section:_POST'); // {"sectiontitle":"my_section","sectionbody":"Oh! ôh."}
$app->get('/section/sid/{sid:[0-9]+}/', '\Section:_GET_one');
$app->get('/section/subscribed/',       '\Section:_GET_subscribed');
$app->get('/section/unread/',           '\Section:_GET_subscribe_with_unread_msg');
$app->get('/section/all/',              '\Section:_GET_all');
$app->put('/section/sid/{sid:[0-9]+}/',                                               '\Section:_PUT_edit'); // {"sectionname":"my_section","sectiondescr":"Oh! ôh."}
$app->put('/section/sid/{sid:[0-9]+}/subscribe/{who:noone|everyone}/',                '\Section:_PUT_subscribe_all');
$app->put('/section/sid/{sid:[0-9]+}/freesubscribe/{what:on|off}/',                   '\Section:_PUT_freesubscribe');
$app->put('/section/sid/{sid:[0-9]+}/uid/{uid:[0-9]+}/{what:subscribe|unsubscribe}/', '\Section:_PUT_subscribe'); 
$app->delete('/section/sid/{sid:[0-9]+}/ImSUREIwantdeleteafullsection/', '\Section:_DELETE'); 

// Node management

$app->post('/node/sid/{sid:[0-9]+}/',              '\Node:_POST');
$app->post('/node/sid/{sid:[0-9]+}/pid/{pid:[0-9]+}/', '\Node:_POST');
$app->get('/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/',  '\Node:_GET'); // and mark it as read ;)
$app->get('/node/flags/',                   '\Node:_GET_flags'); // param : ?filter=hhghg
$app->get('/node/flags/sid/{sid:[0-9]+}/',  '\Node:_GET_flags'); // param : ?filter=hhghg
$app->put('/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/mark/{what:read|unread}/', '\Node:_PUT_mark'); 
$app->put('/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/flag/{what:set|delete}/',  '\Node:_PUT_flags'); 
$app->delete('/node/sid/{sid:[0-9]+}/nid/{nid:[0-9]+}/', '\Node:_DELETE');



// Search management - a separate func because of search engine capability,
// it can be override by a real search engine (mysql is not specialised with)

// params ? {sid_list} & date_from & date_to & {author/titel/body} & [pattern AND "pa te rn" AND NOT patern OR xxx]
$app->get('/search/help/',  '\Search:_GET_help'); // just return a json with text inside
$app->get('/search/node/',  '\Search:_GET_node');
// $app->get('/search/flag/',  '\Search:_GET_flag'); NOT IMPLEMENTED because it could be done on client-side


// Migrate for agora1. TODO REMOVE THIS in prod ;) please. And remove UNBUFFER in Database.php
$app->get('/_migrate/stage0', '\_Migrate:_GET_stage_0');
$app->get('/_migrate/stage1', '\_Migrate:_GET_stage_1');
$app->get('/_migrate/stage2', '\_Migrate:_GET_stage_2');
$app->get('/_migrate/stage3', '\_Migrate:_GET_stage_3');
$app->get('/_migrate/stage4', '\_Migrate:_GET_stage_4');
$app->get('/_migrate/stage5', '\_Migrate:_GET_stage_5');

// END of Routes._migrate/stage0