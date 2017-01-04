<?php

use Psr\Log\LoggerInterface;

/**
 * Description of Session
 *
 * @author FroidevauxPh
 */

// This class is NEVER called throw REST service, but only by class Session
class SessionToken {
    
    private $dbh;
    private $settings;
    private $logger;
    
    public function __construct($dbh, $settings, LoggerInterface $logger)
    {
        $this->dbh      = $dbh;
        $this->settings = $settings;
        $this->logger   = $logger;
    }
    
    public function generate_token($username, $passwd) {
        // add enthropy with microtime() and rand()
        return substr(sha1($username.$passwd.microtime(true).rand().$this->settings['magic']), 0, $this->settings['token_size']);
    }
                
    public function set_new_token($uid, $token, $token_exp, $device) {
        $last_login = time();
        $query = '
            -- set a new token
            INSERT INTO `user_token` (`uid`, `token`, `token_exp`, `device`) 
            VALUES (:uid, :token, FROM_UNIXTIME(:token_exp), :device); 
            UPDATE `user` SET `last_login`=FROM_UNIXTIME(:last_login) WHERE uid=:uid;
        ';
        $sqlargs = [ ':uid' => $uid, ':token' => $token, 
            ':token_exp' => (int)$token_exp, ':last_login' => (int)$last_login, ':device' => $device ];
        $this->dbh->execute($query, $sqlargs);
    }
    
    public function get_subscriptions_from_uid($uid) {
        // used in class User, to initialise the context ($context['subscr'])
        $query = '
            -- get sections where the user is subcribed
            SELECT user_subscr.sid
            FROM user_subscr INNER JOIN user ON user.uid=user_subscr.uid
            WHERE user.uid=:uid;
        ';
        return $this->dbh->fetch_list($query, [ ':uid' => $uid ]);
    }
    
    public function update_user_login_time($uid) {
        // update user with last_login info
        $query = "
            UPDATE `user` SET `last_login`= now() WHERE uid=:uid; 
        ";
        $this->dbh->execute($query, [ ':uid' => $uid ]);
    }
    
    public function get_user_by_token($token) {
        $query = '
            -- get the user infos (by token)
            SELECT u.`uid`, u.`gid`, u.`username`, u.`email`, u.`realname`, u.`role`,
                u.`passwd`, u.`passwd_salt`, u.`last_post_hash`, u.`viewmax`, u.`language`, 
                t.token_exp
            FROM `user` AS u, `user_token` AS t 
            WHERE t.token=:token AND t.uid = u.uid 
                AND t.token_exp > now();
        ';
        return $this->dbh->fetch_one($query, [ ':token' => $token ]);
    }
    
    public function db_get_user_by_id($uid) { // just to test gid - not too much infos
        $query = '
            -- get user by id
            SELECT `uid`, `gid` FROM `user` WHERE uid=:uid ;
        ';
        return $this->dbh->fetch_one($query, [ ':uid' => $uid ]);
    }

    
    public function db_get_user_by_username($username) {
        $query = '
            -- get user by username
            SELECT `uid`, `gid`, `username`, `email`, `realname`, `role`, 
                `passwd`, `passwd_salt`, `last_post_hash`, `viewmax`, `language` 
            FROM `user` 
            WHERE username=:username ;
        ';
        $res = $this->dbh->fetch_one($query, [ ':username' => $username ]);
        return $res;
    }
    
    public function get_user_devices($uid) {
        $query = '
            -- get user devices
            SELECT `uid`, `token`, `token_exp`, `device` FROM `user_token` WHERE uid=:uid ;
        ';
         return $this->dbh->fetch_all($query, [ ':uid' => $uid ]);
    }
    
    public function update_token_exp($token, $token_exp) {
        // user still activ - add time to token
        $max_timeout = $this->settings['defaut_timeout'];
        if (strtotime($token_exp) < (time() + ($max_timeout / 2))) {
            // var_dump("TOKEN, TOKEN_EXP", $token, $token_exp, strtotime($token_exp), time(), $max_timeout);
            $query = "
                -- update token expiration date (due to activity)
                UPDATE `user_token` 
                SET `token_exp`=  DATE_ADD( now(), INTERVAL $max_timeout SECOND )
                WHERE `token`=:token 
                AND token_exp < DATE_ADD( now(), INTERVAL $max_timeout SECOND );
            ";
            $this->dbh->execute($query, [ ':token' => $token ]);
        }
    }
    
    public function delete_token($token) { 
        $query = '
            -- delete token
            DELETE FROM `user_token` WHERE token=:token ;
        ';
        $this->dbh->execute($query, [':token' => $token ]);
    }
       
    public function delete_uid($uid) { // need $context['token'] or $context['uid']
        // security check must have been done before calling internal functions !
        $query = '
            -- delete all token for a user
            DELETE FROM `user_token` WHERE uid=:uid ;
        ';
        $this->dbh->execute($query, [':uid' => $uid ]);
    }
    
    public function clean_old_token() {
        // remove all exprired tokens, for all user; do this sometimes (in Session, at each new login)
        $query = '
            -- delete expired token (for everybody)
            DELETE FROM `user_token` WHERE token_exp < NOW() ;
        ';
        $this->dbh->execute($query, []);
    }
       
} // END class SessionToken