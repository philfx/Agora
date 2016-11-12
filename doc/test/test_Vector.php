<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require '../../app/Controller/_NodeVector.php';

function test_base() { // return result to output with header and <pre>

    echo "\n\n---> Test base : create, on()/off()/off_list()/off_all(), count().\n";
    $v = new NodeVector(['s' => 10]);
    echo $v->dump();
    echo "count(5) : {$v->count(5)}\n";
    
    echo "off(1)\n";
    $v->off(1);
    echo $v->dump();
    
    echo "read 0:", $v->read(0),".\n";
    echo "read 1:", $v->read(1),".\n";
    echo "read 2:", $v->read(2),".\n";
    echo "read 20:", $v->read(20),".\n";
    echo "count(5) : {$v->count(5)}\n";
    
    $v->off_list([0,3,7,8,9]);
    echo "off all [0,3,7,8,9], read 2:", $v->read(2),".\n";
    echo $v->dump(); echo "\n";
    
    $v->on(9); 
    $v->off(13);
    echo "on 9, off 13, read 11:", $v->read(11),".\n";
    echo $v->dump(); echo "\n";
           
    $v->off_list([33,35,2]);
    echo "off_list(33,35,2) and read(35,2):", $v->read(35), ",",$v->read(2), ".\n";
    echo $v->dump();
    
    echo "on(33), off(39)\n";
    $v->on(33); 
    $v->off(39);
    echo $v->dump();
            
    echo "off all 30. (non-sense, should be >= c, nothing done\n";
    $v->off_all(30);
    echo $v->dump();

    echo "off all 36.\n";
    $v->off_all(36);
    echo $v->dump();
       
    echo "count(9): {$v->count(9)}.\n";
    echo "count(38): {$v->count(38)}.\n";
    echo "count(50): {$v->count(50)}.\n";
}

function test_count() { // return result to output with header and <pre>

    echo "\n\n---> Test count().\n";
    $v = new NodeVector(['s' => 10]);
    echo $v->dump();
    echo "count(5) : {$v->count(5)}\n";
    
    echo "off(1)\n";
    $v->off(1);
    echo $v->dump();
     
    echo "count(5) : {$v->count(5)}\n";
    
    $v->off_list([0,2,7,8,9]);
    echo "off all [0,2,7,8,9], read 2:", $v->read(2),".\n";
    echo $v->dump();
    
    echo "count(5) : {$v->count(5)}\n";
    
    $v->on(9); $v->off(11);
    echo "on 9, off 11, read 11:", $v->read(11),".\n";
    echo $v->dump();
           
    $v->off_list([33,35,2]);
    echo "off_list(33,35,2) and read(35,2):", $v->read(35), ",",$v->read(2), ".\n";
    echo $v->dump();
          
    echo "count(9): {$v->count(9)}.\n";
    echo "count(38): {$v->count(38)}.\n";
    echo "count(50): {$v->count(50)}.\n";
}

function test_convert() {
    
    echo "\n\n---> Case 1 : to bigger (1: floating, 2: start, 3: start and low cursor)\n";
    
    echo "Case 1.1\n";
    $v = new NodeVector(['s' => 5]);
    echo "off [0,4,7,9]\n";
    $v->off_list([0,4,7,9]);
    echo $v->dump();
    $v->convert(15); 
    echo $v->dump();
        
    echo "Case 1.2\n";
    $v = new NodeVector(['s' => 5]);
    echo "off [0,4]\n";
    $v->off_list([0,4]);
    echo $v->dump();
    $v->convert(15); 
    echo $v->dump();
    
    echo "Case 1.3\n";
    $v = new NodeVector(['s' => 5]);
    echo "off [0,2]\n";
    $v->off_list([0,2]);
    echo $v->dump();
    $v->convert(15); 
    echo $v->dump();
    
    
    echo "\n\n---> Case 2 : to lower (1: floating, 2: start and low cursor, 3: start, cursor lower than new one)\n";

    echo "Case 2.1\n";
    $v = new NodeVector(['s' => 10]);
    echo "off [11,12]\n";
    $v->off_list([10,12]);
    echo $v->dump();
    $v->convert(5); 
    echo $v->dump();

    echo "Case 2.2\n";
    $v = new NodeVector(['s' => 10]);
    echo "off [8]\n";
    $v->off_list([8]);
    echo $v->dump();
    $v->convert(5); 
    echo $v->dump();
    
    echo "Case 2.3\n";
    $v = new NodeVector(['s' => 10]);
    echo "off [3]\n";
    $v->off_list([3]);
    echo $v->dump();
    $v->convert(5); 
    echo $v->dump();
        
}

function test_import_old_agora() {
    // from a "fresh" section, start=0, maxsize=4096
    $old1 = '00000000000000000000000001CF';
    // from an "old" section, start=498970, maxsize=4096
    $old2 = '00005DC0010FFF';
    $v1 = NodeVector::import_old_agora($old1, 0, 40);  // start=0, maxsize=4096
    echo $v1->dump();
    var_dump($v1->export());

    $v2 = NodeVector::import_old_agora($old2, 498970, 40);  // start=498970, maxsize=4096
    echo $v2->dump();
    var_dump($v2->export());
}

function test_on_list() { // return result to output with header and <pre>

    echo "\n\n---> Test on_list \n";
    $v = new NodeVector(['s' => 10]);

    $v->off_list([0,2,7,8]);
    echo "v->off_list([0,2,7,8]); \n";
    echo $v->dump();
    echo "get_on_list(10) : ".$v->get_on_list(10)." !\n";

    $v->off_list([18,11,14,15,16,10]);
    echo "v->off_list10,11,14,15,16,18]); \n";
    echo $v->dump();
    echo "get_on_list(25) : ".$v->get_on_list(25)." !\n";
    
}

echo "\n\n\n\n\n\n\n\n\n\n";

//test_base();              // OK ! 2014-10-25
//test_count();             // OK ! 2016-11-10 (dont forget to update mem_buffer...)
//test_convert();           // OK ! 2014-10-26
//test_import_old_agora();  // OK ! 2014-10-26
test_on_list();           // NOK
