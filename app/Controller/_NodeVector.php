<?php

/**
 * Description of Vector
 *
 * @author pf
 */

/*
 * Short API doc and remarqs
 *  new() -> create a freh vector, size is 0
 *  new($cursor) -> create a freh vector, all are "on" between 0 and $cursor
 *  new($cursor, $saved) -> reincanate a previously saved vector
 *  self::mem_size => defaut vector size (i.e. size of the "read/unread memory"
 * 
 * Note for the future :
 * To extend the vector size, use convert(). It work and has been tester, for both :
 * - increasing size (doesn't work with penis)
 * - reducing size
 * To change vector storage, you *must* use binary storage. Wich will comes with PHP 5.6.1
 * For this, use convert() and edit __construct() and save() to use binary instead of HEX (base 16 strings)
 * 
 */

interface iVector {
    public function count($pos);       // count "on" ? (after $p, they are on)
    public function read($pos);        // read value of position
    public function sql_get_on_list($pos, $table_row);     // get a list of all "on" (unread) postion, in sql usable format
    public function on($pos);          // mark pos as "on" (here : "new", or "unread")
    public function off($pos);         // mark pos as "off" (here : "read")
    public function off_list($list);   // off(23,54,88)
    public function off_all($pos);     // mark all as "off"
    public function export();          // return string for future reincarnations
    public function convert($vector);  // convert a $vector_size vector to another $vector_size
}

class NodeVector implements iVector {
    
    // access with NodeVector::vector_size (no $ - it's static)
    // is NOT included in CONSTANT. 
    // It's *wrong* to just change this without converting saved vectors first
    public static $mem_size   = 4096; // DEFAUT for all vector. If you change this, convert() the db first...
    public static $mem_buffer = 512;  // used when (moveRight(), move futher to avoid moving at each off()
//    public static $mem_size   = 10; // DEFAUT for all vector. If you change this, convert() the db first...
//    public static $mem_buffer = 5;  // used when (moveRight(), move futher to avoid moving at each off()

    // Note using PHP GMP
    //      Do NOT use >>, << or ~. It works only for numbers < PHP_INT_MAX (i.e. 32 or 64 bits)
    //          So i choose to manipulate strings...
    //          It would be better (more elegant) to divide / 2 to move to right
    //      Attention ! Bit order in GMP is not 012345... but ...543210 (of course)
    
    public static function db_get($dbh, $uid, $sid) {
        $query = "
            SELECT `cursor`, `bitlist`
            FROM `user_status`
            WHERE `uid`=:uid AND `sid`=:sid
        ";
        $values = [ ':uid' => $uid, ':sid' => $sid ];
        $res = $dbh->fetch_one($query, $values);
        return new NodeVector([ 'v' => $res['bitlist'], 'c' => $res['cursor'], 
                                'dbh' => $dbh, 'uid' => $uid, 'sid' => $sid ]);
    }
    
    public function db_set() {
        if ($this->has_changed) {
            $export = $this->export();
            $query = "
                UPDATE `user_status`
                SET `cursor`=:cursor, `bitlist`=:bitlist
                WHERE `uid`=:uid AND `sid`=:sid
            ";
            $values = [ ':uid' => $this->uid, ':sid' => $this->sid, 
                        ':cursor' => $export['c'], ':bitlist' => $export['v'] ];
            $this->dbh->execute($query, $values);
        }
    }
    
    public function __construct($vector = []) {
        // new() -> create a freh vector, size is 0
        // new($cursor) -> create a freh vector, all are "on" between 0 and $cursor
        // new($exported) -> reincanate a previously exported vector
        
        // non-defaut value used by test suite
        $this->s = isset($vector['s']) ? $vector['s']: self::$mem_size; 
        
        // for "automatic" (only if vector has changed) db storage ($v->db_set())
        $this->dbh = isset($vector['dbh']) ? $vector['dbh']: null;
        $this->uid = isset($vector['uid']) ? $vector['uid']: null;
        $this->sid = isset($vector['sid']) ? $vector['sid']: null;
        $this->has_changed = false;
        
        if (isset($vector['v'])) {
            // reuse an exported vector
            $this->c = $vector['c'];
            $this->v = gmp_init($vector['v'], 16); // export is done in base 16
        } else {
            $this->c = (isset($vector['c'])) ? $vector['c'] : 0;
            $this->v = gmp_sub(gmp_pow(2, $this->s), 1); // 2^x-1 => all 'on'
        }
    }
    
    public function export() { // used only by _Migrate. Cuuld became private after migration.
        return [ 
            'c' => $this->c, // cursor
            's' => $this->s, // size
            'v' => NodeVector::toString($this->v, ($this->s / 4), 16) // in HEX, binary storage size is : s / 4
        ];
    }
        
    public function dump() { // for debugging/testing my lib
        $max = 50;
        $output = '--- ';
        $count = 0;
        for ($i = 0; $i < $max; $i++) {
            $output .= ($i == $this->c) ? '*' : $count;
            $count++;
            $count = ($count == 10) ? 0 : $count;
        }
        $output .=  " cursor {$this->c}. (size : {$this->s}) (mem_size:".self::$mem_size.", mem_buffer:".self::$mem_buffer.")\n";
        $output .= '--- ';
        for ($i = 0; $i < $max; $i++) {
            $output .= $this->read($i) ? '1' : '0';
        }
        // can't echo "xxx {sta::tic() ...";
        $output .= " count : {$this->count($max)}. (dump : "
            .NodeVector::toString($this->v,$this->s,2).")\n";
        return $output;
    }
    
    private static function toString($v, $s, $base) {
        // fu*k, gmp_varstr skip the beginning of a string (of course...)
        // so exporting "000010" will give "10". 
        // sprintf format : %, fill with 0, size to display, string
        return sprintf("%0{$s}s", gmp_strval($v, $base) );
    }
        
    private static function moveRight($v, $s, $exceed) {
        return gmp_init( 
                substr_replace( 
                        NodeVector::toString( gmp_sub(gmp_pow(2, $s), 1), $s, 2), 
                        substr(NodeVector::toString($v, $s, 2), 
                            $exceed, 
                            $s - $exceed 
                        ), 0, $s - $exceed
                    ) ,2
                );
    }
        
    public function convert($s2) { // usage : $v = new(...); $v->convert(new_size); $export = $v->export()
        // used in only two case (so, NOT used in dayly business)
        // 1. in the future, to increase cursor size (with gmp_export() and binary storage, but php 5.6.1 or above !)
        // 2. to help converting from old agora (see in _Migrate call)
        if ($s2 > $this->s) {
            // add '1+' at the end of the vector and change s and c
            $this->v = gmp_init( 
                substr_replace( 
                        NodeVector::toString( gmp_sub(gmp_pow(2, $s2), 1), $s2, 2), 
                        NodeVector::toString($this->v, $this->s, 2),
                        0, 
                        $this->s
                    ),
                2
                );
            $this->c = $this->c + $s2 - $this->s;
        } elseif ($s2 < $this->s) {
            // new size is lower than the old one
            // case 2.1 : floating
            // case 2.2 : start and low cursor (but greater than the new size)
            // case 2.3 : start, cursor lower than the new size
            if ($this->c >= $this->s) {
                $this->v = gmp_init( 
                        substr(NodeVector::toString($this->v, $this->s, 2), $this->s - $s2, $s2 ) 
                    , 2 );
            } elseif ($this->c >= $s2) {
                $this->v = gmp_init( 
                        substr(NodeVector::toString($this->v, $this->s, 2), $this->c - $s2, $s2 ) 
                    , 2 );
            } else {
                echo "cases 2.3 : start, cursor lower than the new size\n";
                $this->v = gmp_init( 
                        substr(NodeVector::toString($this->v, $this->s, 2), 0, $s2 ) 
                    , 2 );
            }
        }
        $this->s = $s2;
    }
    
    // function import_old_agora could be **removed** after agora1 migration !
    public static function import_old_agora($old_string, $old_start, $nnode) {
        
//        $old_string='afdf05432'; $old_start=0; $nnode = 10;
//        echo "String, hex : ",strlen($old_string),", $old_string\n";
        
        $v_gmp = gmp_init($old_string, 16);
        $v_len = strlen(gmp_strval($v_gmp,2));
//        echo "String, bin : ",$v_len,", ",gmp_strval($v_gmp,2),"\n";
        
//        echo "String, bin : ",$v_len,", ",gmp_strval($v_gmp,2),"\n";
        // invert values. For the old system, 0 is unread, for the new, 0 is read (off)
        $v_xor = gmp_xor($v_gmp, gmp_sub(gmp_pow(2, $v_len), 1));
 
//        echo "String, xor : ",$v_len,", ",sprintf("%0{$v_len}s", gmp_strval($v_xor,2)),"\n";
        
        $v_str = strrev(sprintf("%0{$v_len}s", gmp_strval($v_xor,2)));
//        echo "String, rev : ",$v_len,", ",$v_str,"\n";
        
        // create a new vector object
        $v_hex = sprintf("%0".($v_len/4)."s", gmp_strval(gmp_init($v_str, 2),16));
//        echo "String, hex : ",$v_len/4,", ",$v_hex,"\n";
        $v_final = new NodeVector(['v' => $v_hex, 'c' => ($old_start + $v_len), 's' => $v_len ]);
//        echo "FINAL (s,c,v) : ({$v_final->s},{$v_final->c}, ", sprintf("%0{$v_final->s}s", gmp_strval($v_final->v, 2) ),")\n";

        $v_final->convert(self::$mem_size);
//        echo "CONVT v (s,c) : ({$v_final->s},{$v_final->c}, ", sprintf("%0{$v_final->s}s", gmp_strval($v_final->v, 2) ),")\n";

        return $v_final;
    }

    public function count($pos) {
        if ($pos < $this->c - $this->s ) {
            //echo "count si p<c-s, => 0 \n";
            return 0;
        } elseif ($pos < $this->s ) {
            //echo "count si p<s => count([0,p]) \n";
            return gmp_popcount(
                    gmp_init(
                        substr(NodeVector::toString($this->v,$this->s,2),0,$pos)
                    ,2));
        } elseif ($pos >= $this->c) {
            //echo "count si p>c, => count(v) + p-c \n";
            if ($this->c > $this->s) {
                //echo "count si p>c, => count(v) + p-c ==> c>s \n";
                return gmp_popcount($this->v) + ($pos - $this->c);
            } else {
                //echo "count si p>c, => count(v) + p-c ==> c<=s \n";
                return gmp_popcount($this->v) + ($pos - $this->s);
            }
        } else {
            //echo "count count(v[0,p+s-c]) \n";
            return gmp_popcount(
                        gmp_init(
                            substr(NodeVector::toString($this->v,$this->s,2),0,$pos- $this->c + $this->s)
                        ,2));
        }
    }
        
    public function read($pos) {
        if ($pos >= $this->c){
            // outside upper range => always "on"
            return true;
        } elseif ($pos < ($this->c - $this->s) ) {
            // below ($size-$cursor) => always "off" (too old, older than $size)
            return false;
        } elseif ($this->c <= $this->s) {
            // $cursor is still smaller than vector size
            return gmp_testbit($this->v, ($this->s - $pos -1) );
        } elseif ($this->c > $this->s) { 
            // last case, every cases are covered
            return gmp_testbit($this->v, ($this->c - $pos) -1 );
        }
    }
    
    public function sql_get_on_list($pos, $table_row) {
        // parse vector and
        // if "alone" on (or in pair), put it in a set : "IN (x, y, ...)"
        // if "group" on, put it in a list : "BETWEEN x AND y OR BETWEEN ..."
        
        
        $from = 0;
        if ($this->c > $this->s) { 
            $from = $this->c - $this->s;
        }
        
//        echo "\n pos = $pos, from = $from !\n";
        $list_alone = [];
        $list_range = [];
        $cursor = null;
        $cursor_last = null;
        for ($i = $from ; $i <= $pos; $i++) {
            $b = $this->read($i);
//            echo "$i: $b ... ";
            if ($b == true && $i !== $pos) {
                if (is_null($cursor)) {
                    $cursor = $i;
                    $cursor_last = $i;
//                    echo "true, cursor null (c=$cursor, l=$cursor_last)\n";
                } else {                    
                    $cursor_last = $i;
//                    echo "true, (c=$cursor, l=$cursor_last)\n";
                }
            } elseif (!is_null($cursor)) { // $b == false
                if ( $cursor === $cursor_last ) { // previous is alone
//                    echo "false, c=last, ALONE (c=$cursor, l=$cursor_last) \n";
                    array_push($list_alone, $cursor);
                    $cursor = null;
                } elseif ( $cursor === $cursor_last - 1 ) { // 2 previous only
//                    echo "false, c=last-1, ALONE-pair cursor (c=$cursor, l=$cursor_last) \n";
                    array_push($list_alone, $cursor);
                    array_push($list_alone, $cursor_last);
                    $cursor = null;
                } else { // more than 2 elements in "true" list
//                    echo "false, range, RANGE (c=$cursor, l=$cursor_last) \n";
                    array_push($list_range, "$cursor AND $cursor_last");
                    $cursor = null;
                } // else, ignore and continue
            } else { // $b == false and no cursor, just ignore
//                echo "false, do nothing (c=$cursor, l=$cursor_last)\n"; 
            }
        }
        
        $sql_alone = "";
        if (count($list_alone) > 0) {
            $sql_alone = "$table_row IN (".implode(',',$list_alone).")";
        }
        $sql_range = "";
        if (count($list_range) > 0) {
            $sql_range = " ( $table_row BETWEEN ".implode(' OR $table_row BETWEEN ',$list_range)." )";
        }
        
        $sql = "";
        if ($sql_alone !== "" && $sql_range !== "") {
            $sql = $sql_alone." OR ".$sql_range;
        } else {
            $sql = $sql_alone.$sql_range;
        }
        
        return $sql;
    }
    
    public function on($pos) {
        // has not been really tested...
        // >= c is in the future and then already on
        // < c-s is already in the past, then off forever
        if ($pos < $this->c && $pos >= ($this->c - $this->s) ) { 
            gmp_setbit($this->v, ($this->c - $pos - 1) , true );
            $this->has_changed = true;
        }
        if ($pos < ($this->c - $this->s) ) {
            return false; // too late, out of offset, I cannot set it back to on
        } else { // NOT tested. Out of offset in the future, so always 'on'
            return true;
        }
    }

    public function off($pos) {
        // four cases, depending $pos :
        // 1. below ($size-$cursor) => always "off"
        // 2. still inside vector_size, no need to move the vector but c changes
        // 3. in vector range, no need to move the vector
        // 4. outside upper range => move vector to right, and adapt $cursor
        if ($pos < ($this->c - $this->s) or $pos < 0 ) {
            // below ($size-$cursor) => always "off"
            return; // below offset, no changed.
        } elseif ($pos < $this->s && $this->c <= $this->s){
            // still inside vector_size, no need to move the vector
            if ($pos >= $this->c) {
                $this->c = $pos + 1;
            }
            gmp_setbit($this->v, ($this->s - $pos -1) , false );
            $this->has_changed = true;
        } elseif ($pos < $this->c){
            // in vector range, no need to move the vector
            gmp_setbit($this->v, ($this->c - $pos - 1) , false );
            $this->has_changed = true;
        } else { // p>s && p>=c
            // outside upper range => move vector to right, and adapt $cursor
            $exceed = $pos + 1 - $this->c + self::$mem_buffer;
            if ($exceed > $this->s) {
                $this->v = gmp_sub(gmp_pow(2, $this->s), 1);
            } else {
                $this->v = NodeVector::moveRight($this->v,$this->s,$exceed);
            }
            // c=p+1
            $this->c = $pos + 1 + self::$mem_buffer;
            gmp_setbit($this->v, self::$mem_buffer , false );
            $this->has_changed = true;
        }
        return; // vector changed
    }
    
    public function off_list($values = []) {
        // only the max value need to move the index, so do it first (and not for each element...)
        arsort($values); 
        foreach($values as $pos) {
            $this->off($pos);
        }
    }
        
    public function off_all($pos) {
        // it's NOT $pos, but a number of node to mark as read : off_all(2) means : off(pos_0,pos_1)
        if ($pos <= $this->c - $this->s) { // already off... (non-sense, should never be called)
            return;
        }
        if ($pos < $this->c) { // so, not all the vector but only the beginning
            // what ? $this->c = $pos;
            // sorry a mathematical solution would be more elegant, but this is short enought
            for ($i = $this->c - $pos; $i < $this->s; $i++) {
                gmp_setbit($this->v, $i , false );
            }
            $this->has_changed = true;
        } else {
            $this->v = gmp_init(0,2);
            $this->c = $pos;
            $this->has_changed = true;
        }
    }   
    
} // END class NodeVector implements iVector

