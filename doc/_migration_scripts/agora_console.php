<?php

    /* 
     * To change this license header, choose License Headers in Project Properties.
     * To change this template file, choose Tools | Templates
     * and open the template in the editor.
     */

    if (php_sapi_name() !== "cli") {
        die("ALERT : agora_console started in non-commnand-line mode.");
    }

    // Doc about this command line parser : https://github.com/nategood/commando
    require_once 'vendor/autoload.php';
    $args = new Commando\Command();
    
    $args->option() // migrate, init, destroy
        ->require()
        ->describedAs('A command to execute : init, destroy, migrate - WARNING - NOT IMPLEMENTED yet.')
        ->must(function($command) {
                $commands = array('init', 'destroy', 'migrate');
                return in_array($command, $commands);
            });

    $args->option('c')
        ->aka('config')
        ->require()
        ->describedAs('PHP agora config file (full path is better)')
        ->must(function($file) {
            // TODO return file settings.php exists and ca be read
            return false;
        });
    
    $args->option('d')
        ->aka('debug')
        ->aka('cap')
        ->describedAs('Being verbose and give a lot of output')
        ->boolean();
            
    // test command
    // - init : created the database, add an admin user, add a group "zero"
    // - destroy : destroy the db
    // - migrate : migrate from an old version of agora

    // TODO implement agora_console 

    if ($options['execute'] == 'migrate') {

    } elseif ($options['execute'] == 'init') {

    } elseif ($options['execute'] == 'destroy') {

    }


// END of script agora_console.php