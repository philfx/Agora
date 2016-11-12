<?php

use Slim\Container as Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Note : Some function of Class::Session are on the critical path. Each request need auth before lauching.
//        Try to avoid complexity and db request.

class Session {

    private static $settings;
    private static $logger;
    private static $SessionToken;

    public static function _init(Container $c) {
        self::$settings = $c->get('settings')['agora'];
        self::$logger   = $c['logger'];
        self::$SessionToken = new SessionToken($c['dbh'], self::$settings, $c['logger']);
    }
   
    private static function auth_can_delete_token($uid, $context) { // used in session_DELETE
        self::$logger->debug("Session->__construct");
    
        // user $context['uid'] want to act on $uid
        if ($uid === $context['uid']) {
            return true; // it's for myself. I can.
        } elseif ($context['role'] === 'a') {
            return true; // administrator can all. Everywhere. He's GOD.
        } else {
            // superuser. Get sid and gid of user "$uid" and check if in same group as the superuser
            $target_user = self::$SessionToken->db_get_user_by_id($uid);
            if ($context['role'] === 's' and $target_user['gid'] == $context['gid']) {
                return true; // I am superuser of this group. I can.
            } else {
                self::$logger->alert("Session->_DELETE_uid - user ".$context['uid']." try to remove token of user" .$uid.".");
                return false;
            }
        }

    } // END public function Session->auth_can_delete_token

    public static function get_subscriptions_from_uid($uid) {
        return self::$SessionToken->get_subscriptions_from_uid($uid);
    }
       
    private static function get_user_info_to_return($user_db) {
        // we don't nedd/want return info like password, pwd_salt, etc.
        $data = [];
        $data['uid']            = $user_db['uid'];
        $data['gid']            = $user_db['gid'];
        $data['username']       = $user_db['username'];
        $data['realname']       = $user_db['realname'];
        $data['email']          = $user_db['email'];
        $data['role']           = $user_db['role'];
        $data['viewmax']        = $user_db['viewmax'];
        $data['language']       = $user_db['language'];
        $data['last_post_hash'] = $user_db['last_post_hash'];
        return $data;
    }
    
    public static function _get_context ($token) { // is_logged_in
        //self::$logger->info("Session->_get_context(\$token). Token is '$token'");
        
        // search token in db, get the user params
        // test if user exists AND if token still valid - return error if not
        $res = self::$SessionToken->get_user_by_token($token); // $res is the context, generally $user
        if (!isset($res['uid'])) {
            return null; // No valid token. Maybe it timed'out
        }
        self::$SessionToken->update_token_exp($token, $res['token_exp']);
        $context = self::get_user_info_to_return($res);
        $context['token'] = $token;
        
        $context['subscr'] = self::get_subscriptions_from_uid($res['uid']);
        
        // return user's param as session's "context"
        return $context;
    } // END public function _GET
  
    public static function _GET (Request $request, Response $response, $args) { // is_logged_in
        self::$logger->debug("Session->_GET()");
        // Context already loaded. Just return the result.
        $context = $request->getAttribute('context');       
        return $response->withJson( $context , 200);
    } // END public function _GET
    
    public static function _GET_device (Request $request, Response $response, $args) { // get device list
        self::$logger->debug("Session->_GET_device()");

        $context = $request->getAttribute('context');
        $device = self::$SessionToken->get_user_devices($context['uid']);

        return $response->withJson( $device , 200);
    } // END public function _GET
    
    public static function _POST (Request $request, Response $response, $args) { // LOGIN
        self::$logger->debug("Session->_POST()");
        $bodyJson = $request->getAttribute('bodyJson');
                
        //if (array_key_exists('username', $bodyJson) && array_key_exists('passwd', $bodyJson)) {
        if (isset($bodyJson['username']) && isset($bodyJson['passwd'])) {
            $res = self::$SessionToken->db_get_user_by_username( $bodyJson['username'] );
            self::$logger->debug("Session->_POST --> passwd(".$bodyJson['passwd']."), salt(".$res['passwd_salt']
                    ."), sha1(".sha1($bodyJson['passwd'].$res['passwd_salt'])."), db_passwd(".$res['passwd'].")");
            if (isset($res) and sha1($bodyJson['passwd'].$res['passwd_salt']) === $res['passwd'] ) {
                self::$logger->info("Session->_POST Login sucessfull for ".$bodyJson['username']);
                $device = isset($bodyJson['device']) ? 
                        $bodyJson['device'] : 
                    'Unknown device/location (IP: '.$request->getAttribute('ip_address').', date '.date("Y-m-d H:i:s").')';
                $token_exp = time();
                if (isset($bodyJson['timeout'])) {
                    $token_exp += ctype_digit($bodyJson['timeout']) ? $bodyJson['timeout'] : self::$settings['defaut_timeout'];
                } else {
                    $token_exp += self::$settings['defaut_timeout'];
                }
                $token = self::$SessionToken->generate_token($res['username'], $res['passwd']);
                self::$SessionToken->set_new_token($res['uid'], $token, $token_exp, $device);
                $context = self::get_user_info_to_return($res);

                $context['subscr'] = Session::get_subscriptions_from_uid($res['uid']);
                
                // pass the context to the request
                $context['token']  = $token;
                $request = $request->withAttribute('context', $context);
                        
                // cleanup timedout token each time someone logged in successfully
                self::$SessionToken->clean_old_token();
                
                self::$SessionToken->update_user_login_time($context['uid']);
                
                $response = self::_GET($request, $response, $args);
            } else {
                $response = $response->withJson(
                    ['error'   => 'Wrong username or password. Memory.',
                     'request' => $request->getMethod().' -> '.$request->getUri() ], 
                    401);
            }
        } else {
            $response = $response->withJson(
                ['error'   => 'Miss a parameter. Should be sent in BODY/JSON and be username, passwd, timeout, device.',
                 'request' => $request->getMethod().' -> '.$request->getUri() ], 
            400);
        }
        return $response;
    } // END public function _POST

    public static function _DELETE_token (Request $request, Response $response, $args) { // LOGOUT
        // just remove the current token (most case)
        // already "session_GET", so it's ok to remove this token without more checking...
        self::$logger->info("Session->session_DELETE_token (current or other token). ARGS are ".print_r($args,true));
        
        $context = $request->getAttribute('context');
        if (isset($args['token'])) {
            // remove a token other than the current one
            // get uid of the target token. Will be requested a second time in auth_..., but... not really important ;)
            $targetuser = self::$SessionToken->get_user_by_token($args['token']);
            $args['uid'] = $targetuser['uid'];
            if (self::auth_can_delete_token($args['uid'], $context)) {
                self::$SessionToken->delete_token($args['token']);
            } else {
                return $response->withJson(['error' => 'You are not authorized to do this, of course'], 401);
            }
        } else {
            self::$SessionToken->delete_token($context['token']);
        }
        
        return $response->withJson(['result' => 'Bye'], 200);
    } // END public function _DELETE
    
    public static function _DELETE_uid (Request $request, Response $response, $args) { // LOGOUT
        self::$logger->info("Session->session_DELETE_uid (args : ".print_r($args,true).")");
        
        $context = $request->getAttribute('context');
        if (self::auth_can_delete_token($args['uid'], $context)) {
            self::$SessionToken->delete_uid($args['uid']);
        } else {
            return $response->withJson(['error' => 'You are not authorized to do this, of course'], 401);
        }

        return $response->withJson(['result' => 'Bye, number '.$args['uid']], 200);
    } // END public function _DELETE
    
} // END class Session