<?php

use Psr\Log\LoggerInterface;

class Database {

    // count every db requests 
    protected $logger;
    protected $nb_requests;   // count db requests in a user query
    protected $time_start;    // init time counter. Not the right place to do this but...
    protected $dbh;           // database handler
    public    $dbh_is_connected = true;
  
    public function __construct( $dsn, $user, $passwd, LoggerInterface $logger) {

        $this->logger = $logger;
        $this->nb_requests = 0; // nb user db request = 0
        $this->time_start  = microtime(true); // init time counter
        
        try {
            $this->dbh = new PDO($dsn, $user, $passwd);
        } catch (\PDOException $p) {
            $this->logger->critical("DATABASE : No connection ! ($dsn)");
            $this->dbh_is_connected = false;
            return;
        }
        
        // $this->dbh = new PDO($dsn, $user, $passwd);
        $this->logger->debug("Database connected ($dsn)");

        // the following is the same as : 
        //   SET character_set_client = charset_name;
        //   SET character_set_results = charset_name;
        //   SET character_set_connection = charset_name;
        $this->dbh->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, "SET NAMES utf8mb4"); // in POSTGRES : SET NAMES 'UTF8';
        
        // ONLY for Migration, TODO remove this after migration
        //$this->dbh->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        // If not set, PDO is not really verbose
        $this->dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

    }
    public function _finally_log_query($uid, $method, $path) { // __destroy does nothing :p
        
        $duration = sprintf("%d", (microtime(true) - $this->time_start) * 1000000);
        $query = "
            -- log rest query
            INSERT INTO `log_rest`(`uid`, `method`, `path`, `nbqueries`, `duration`)
            VALUES (:uid, :method, :path, :nbq, :dur) ;
        ";
        $values = [ ':uid' => $uid, ':method' => $method, ':path' => substr($path,0,250), 
            ':nbq' => $this->nb_requests, ':dur' => $duration ];
        $this->execute($query, $values);
        
        $this->logger->info("Database, used ".$this->nb_requests." time before destruction (duration : ".number_format($duration)." ms)");
    }

    public function last_insert_id() {
        return $this->dbh->lastInsertId();
    }
    
    public function fetch_one($query, $values = []) { // prepare, then execute
        $stmt = $this->execute($query, $values);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function fetch_all($query, $values = []) { // prepare, then execute
        $stmt = $this->execute($query, $values);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function fetch_list($query, $values = []) { // prepare, then execute
        $stmt = $this->execute($query, $values);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function execute($query, $values = []) { // prepare, then execute
        // $query  = "SELECT a,b FROM t WHERE a=:vara and b=:varb
        // $values = [ ':vars' => 'aaa', ':varb' => 'bbb' ];
        $this->nb_requests++; 
        $this->logger->debug("Database::execute - Doing Query : \n$query" );
        
        $stmt = $this->dbh->prepare($query);
        $stmt->execute($values);
        return $stmt;
    }
    
} // END Class Database
