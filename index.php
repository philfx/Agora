<?php
if (PHP_SAPI == 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    if (is_file(__DIR__ . $_SERVER['REQUEST_URI'])) {
        return false;
    }
}

// Slim, Monolog
require __DIR__ . '/vendor/autoload.php';

// Internal tools (migration, database handling and logging, token, vector)
require 'app/Controller/Database.php';
require 'app/Controller/_Migrate.php'; // migrate from old db schema
require 'app/Controller/_NodeVector.php';

// API handlers
require 'app/Controller/Session.php';
require 'app/Controller/_SessionToken.php';
require 'app/Controller/User.php';
require 'app/Controller/Section.php';  
require 'app/Controller/Group.php';
require 'app/Controller/Node.php';
require 'app/Controller/Thread.php';
require 'app/Controller/Search.php';
    
// Instantiate the app
$settings = require __DIR__ . '/app/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
require __DIR__ . '/app/dependencies.php';

// Register middleware
require __DIR__ . '/app/middleware.php';

// Register routes
require __DIR__ . '/app/routes.php';

// Run app
$app->run();

// End. Bye bye.