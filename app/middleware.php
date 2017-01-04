<?php

// Agora Middleware
// 1. check request type
// 2. (if not a Session->_POST : verify token and get the user info ("$context")
// 3. route (if no token error)
// 4. log request in db

$app->add(function ($request, $response, $next) {   
    
    // Exception for MIGRATION. TODO remove this in prod
    if ( preg_match('/migrate/', $request->getUri()->getPath()) ) {
        $this->logger->debug("MIGRATION BEGIN");
        return $next($request, $response);
    }
    
    // test here if body is JSON and UTF8, else return an error
    // Slim (and PSR-7) doesn not parse json if HEADER is not specified
    if ($request->getContentType() !== 'application/json;charset=UTF-8') {
        $this->logger->error("Content-type must be 'application/json;charset=UTF-8'. ".
                "It is '".$request->getContentType()."'. Exit.");
        $response = $response->withJson(
                ['error'   => "Content-type is not 'application/json;charset=UTF-8'.".
                    "It is '".$request->getContentType()."'. Speak correctly. Exiting.",
                 'request' => $request->getMethod().' -> '.$request->getUri() ], 
                400);
        return $response;
        // Stopped here. Don't log/process invalid queries.
    }
    
    /*
     * $token : the agora auth token. Could be received :
     *    from      HTTP Header ('Agora-Token' in Postman, which will be received as HTTP_AGORA_TOKEN by Apache)
     *    else from cookies, as "Cookie Agora-Token=xxxxxx"
     *    else from parameter (query), as "...?token =xxx&..."
     *    else from body (json/POST), as { token = xxx, ...}
     * 
     * $context : `uid`, `gid`, `username`, `email`, `realname`, `role`, `viewmax`, `language`
     * 
     * Note : Don't know why HEADER sent as "agora-token" is received as "HTTP_AGORA_TOKEN" be apache.
     * This could help : http://stackoverflow.com/questions/29338848/http-headers-not-showing-sent-with-postman
     * 
     */

    $headers = $request->getHeaders();
    $tokenByCookie =$request->getCookieParams('Agora-Token'); // just one way to get cookie
    $queryParams = $request->getQueryParams();
    $bodyJson = $request->getParsedBody();
    
    $dbh = $this->get('dbh');
    if (!$dbh->dbh_is_connected) {
        $response = $response->withJson( ['error' => 'Database error : cannot connect to the database.' ], 500);
        return $response;   
    }
    
    $context = [];
        
    if ($request->getMethod() !== 'POST' || $request->getUri()->getPath() !== 'session/') {
        // Not a login query, so check auth (authen and author), then get the context
        
        // just log header grep'ed with for /agora/i
        //$header_grep = preg_grep( "/agora/i", array_keys( $request->getHeaders()) );
        //$header_vals = []; foreach ( $header_grep as $key ) { $header_vals[$key] = $request->getHeaders()[$key][0]; }
        //$this->logger->debug('MIDDLEWARE : check token - HEADER : '.print_r($header_vals, true));
        //$this->logger->debug('MIDDLEWARE : check token - HEADER : '.print_r($request->getHeaders(), true));
        
        // search for auth token
        if (isset($headers['HTTP_AGORA_TOKEN'])) { 
            // is token set in HTTP_HEADER ?
            $context['token'] = $headers['HTTP_AGORA_TOKEN'][0];
            $this->logger->info("Token received from HTTP_HEADER ('".$context['token']."')");
        } elseif (isset($tokenByCookie["Agora-Token"])) { 
            // is token sent as cookie ?
            $context['token'] = $tokenByCookie["Agora-Token"];
            $this->logger->info("Token received from COOKIE ('".$context['token']."')");
        } elseif (isset($queryParams['token'])) {
            // is token set in the params ?
            $context['token'] = $queryParams['token'];
            $this->logger->info("Token received from parameters ('".$context['token']."')");
        } elseif (isset($bodyJson['token'])) {
            // is token set in the body ?
            $context['token'] = $bodyJson['token'];
            $this->logger->info("Token received from body/JSON ('".$context['token']."')");
        } else {
            // No token found !
            $response = $response->withJson(
                ['error'   => 'No token found. Neither in HTTP_HEADER, nor in COOKIE, nor in params, not in body.',
                 'request' => $request->getMethod().' -> '.$request->getUri() ], 
                401);
            $this->logger->warning('MIDDLEWARE : request sent without token. Returned error and stopped.');
            return $response;
            // Stopped here. Don't log the query, it's invalid...
        }
        
        // Check token and get the user's context, throw Session::_get_context
        $context = Session::_get_context($context['token']);
        if (!isset($context['uid'])) {
            $this->logger->info("Invalid token from ".$request->getMethod().' -> '.$request->getUri());
            $dbh->_finally_log_query(0, $request->getMethod(), $request->getUri());
            return $response->withJson(
                ['error'   => 'Not a valid token. Maybe it has expired ? Get a new one with POST -> session/',
                 'request' => $request->getMethod().' -> '.$request->getUri() ], 
                401);
        }
    } else { // !== 'POST' or !== 'session/', it's a login query, no need to get token and context
        // Nothing to do in Middleware, Session::_POST will do the job
        $this->logger->debug('MIDDLEWARE : it\'s a login query, Session::_POST will do the job.');
    }
    
    // Weeeeeeeeee !! We get inside the app ! Going to the router now.
    
    // send to the router : $context, bodyJson, $queryParams
    // use them in router with $foo = $request->getAttribute('foo');
    $request = $request->withAttribute('context', $context)
            ->withAttribute('bodyJson', $bodyJson)
            ->withAttribute('$queryParams', $queryParams);
//    $this->logger->debug("Entering ROUTER. Params are :\n".
//            "\$context = ".print_r($context, true).
//            "\$bodyJson = ".print_r($bodyJson, true).
//            "\$queryParams = ".print_r($queryParams, true));
    
    // route. Do the real job.
    $response = $next($request, $response);
    
    
    // Check if the response should render a 404
    // TODO something wrong here. Read the Slim doc and test more...
    if ($response->getBody()->getSize() === 0) {
        $this->logger->error("ERROR, empty result for request ".$request->getMethod().' -> '.$request->getUri());
        $response = $response->withJson(
            ['error'   => 'Result is empty. No error found, however. I admit I don\'t understand why this appends. It shouldn\'t.',
             'request' => $request->getMethod().' -> '.$request->getUri() ], 
            404);
    }
    
    // TODO if response code is not 200, add request information to the response
    // Need help. I can't add a parameter to the bodyJson response ?
//    if ($response->getStatusCode() !== 200) {
//        $this->logger->error("TODO error code is not 200, add information to response ".$request->getMethod().' -> '.$request->getUri());
//        $body = $response->getBody()->getContents();
//        $this->logger->error("TODO error code is not 200, body ".print_r($body,true)." !");
//        // ... get actual values from json
//        // ... add values for input request, args, params, body
//        // ... return with statut
////        $data = [];
////        $data['rest_Method'] = "jjj"; // $request->getMethod();
////        $data['rest_Uri']    = "kkk"; // $request->getUri();
////        $response2 = $response->withJson($data);
//    }
    
    // after init, routing, doing and all stuff, log the request and duration in database
    $context['uid'] = (isset($context['uid'])) ? $context['uid'] : 0;
    $dbh->_finally_log_query($context['uid'], $request->getMethod(), $request->getUri());
    
    return $response;
}); // END of Agora Middleware

// Middleware to get IP addresses, aka $ipAddress = $request->getAttribute('ip_address');
// Note : Middleware is last in, first used, so put it here and not before AgoraMiddleware
$app->add(new RKA\Middleware\IpAddress(true)); // doc : http://www.slimframework.com/docs/cookbook/ip-address.html


// END of Middleware.