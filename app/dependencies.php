<?php
// DIC configuration

$container = $app->getContainer();

// monolog - Logger
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    // show a unique query id, usefull for multiple users debug
    //$logger->pushProcessor(new Monolog\Processor\UidProcessor()); 
    // Send log files to a... file (also possible : Chrome Console, email for criticals, etc.)
    $log_handler = new Monolog\Handler\StreamHandler($settings['path'], $settings['level']); 
    // allow multiple lines (third true)
    $log_handler->setFormatter(new Monolog\Formatter\LineFormatter(null, null, true, true) );
    $logger->pushHandler($log_handler);
    $logger->debug("---------------------- BEGIN (logger init ok, add spaces for adding spaces)\n\n\n\n\n");
    return $logger;
};

// Override the default Not Found Handler
$container['notFoundHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        return $c['response']->withJson([
            'error' => 'Route not found.',
            'info' => 'Be aware that .../user and .../user/ are different. Ending slash are mandatory by PSR-7 standard. '.
                'Also, query /uid/abc and /uid/123 are not the same and one doen\'t match the route. Read the doc.',
            'request' => $c['request']->getMethod().' -> '.$c['request']->getUri()
        ], 400);
    };
};

$container['notAllowedHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        return $c['response']->withJson([
            'error' => 'Incorrect handler. Route found but with incorrect handling (POST, GET, PUT, DELETE).',
            'info' => 'Check routing table for more informations.',
            'request' => $c['request']->getMethod().' -> '.$c['request']->getUri()
        ], 400);
    };
};

// Override the default Error Handler
//$container['errorHandler'] = function ($c) {
//    return function ($request, $response, $exception) use ($c) {
//        return $c['response']->withJson([
//            'error' => 'Something get wrong. Probably the dev\'s fault.',
//            'request' => $c['request']->getMethod().' -> '.$c['request']->getUri(),
//            'detail' => ['error' => $exception->getMessage(), 'code' => $exception->getCode()]
//        ], 500);
//    };
//};

// Initialize Agora Controller(s) (log, db handler)
$container['dbh'] = function ($c) {
    $settings = $c->get('settings')['database'];
    return new Database($settings['dsn'], $settings['username'], $settings['password'], $c->get('logger'));
};

// Init static class (Agora Controller)
// Sorry for not using objects, I'm stuck in 1990 wuth ADA modules :(
/* If want to make a proper job with object and autoload...
 * https://akrabat.com/accessing-services-in-slim-3/
 * http://juliangut.com/blog/slim-controller
 * http://www.slimframework.com/docs/tutorial/first-app.html
 */

Session::_init($container);
Section::_init($container);
Group::_init($container);
User::_init($container);
Node::_init($container);
Thread::_init($container);
Search::_init($container);

_Migrate::_init($container); // TODO REMOVE THIS in prod     


// END Depedencies.