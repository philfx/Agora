  #####################################################

    sub select_thread_list { my ($obj,$section,$where) = @_;
      $where = "AND $where" if ($where);
      my $query = qq{
        SELECT   num,thrnum,parent,title,erased,author,name,cdate
        FROM     threads
        WHERE    section=$section $where
        ORDER BY thrnum,parent,num
      };
      print "<PRE> QUERY : $query</PRE>" if $DEBUG;
      return $obj->{"db"}->getall($query);
    }

    sub select_thread_list_byNode { my ($obj,$section,$where) = @_;
      return [] if !$where;
      my $query = '';

      # 1/ select all $where and group by thread
      $query = qq{ SELECT thrnum FROM threads WHERE section=$section AND $where GROUP BY thrnum };
      #trace($query);
      my $threads_list_ref = $obj->{"db"}->getall($query);

      # 2/ build the WHERE condition
      my @threads_list = ();
      for my $i (@$threads_list_ref) {
        push @threads_list, $i->[0];
      }
      return [] if $#threads_list == -1;
      my $threads_cond = 'thrnum IN ('.join(',',@threads_list).')';

      # 3/ build the select query
      $query = qq{
        SELECT   num,thrnum,parent,title,erased,author,name,cdate
        FROM     threads
        WHERE    section=$section AND $threads_cond
        ORDER BY thrnum,parent,num
      };
      #trace($query);

      # 4/ return the result
      return $obj->{"db"}->getall($query);
    }

    sub get_unread { my ($vorig,$vstart,$nnode) = @_;

      # case 1 : nnode is '1', $v->to_Enum() return an empty string...
      if ($nnode == 1) {
        return 'num = 0';
      }

      # case 2 : to_Enum() is ok.
      my $v = $vorig->Clone();
      $v->Resize($nnode-$vstart);
      my $enum  = $v->to_Enum();

      #trace("ENUM there are already read (0 -> $vstart -> $nnode) : [".$enum."]");
      # TODO verify if (vstart != 0)...
      return '' if $enum eq "0-".($nnode-$vstart);

      my @list = split(',',$enum);
      my $res = '';

      my $first      = shift @list || '0';
      my $prev       = '';
      if ($first =~ /^\d+$/ || $first =~ /^$/) {
        $prev  = '0';
      }
      else {
        $first =~ /^(\d+)\-(\d+)$/;
        if ($1) {
          if ($1 == 1) {
            $res .= "num = $vstart OR "; # STRING
          }
          else {
            if ($prev == 2) {
              $res .= "num = ".($prev+1)." OR ";
            }
            else {
              $res .= "num > $vstart AND num < ".($1+$vstart)." OR "; # STRING
            }
          }
        }
        $prev  =  $2 if $2;
      }
      for my $i (@list) {
        next if $i =~ /^\d+$/;
        $i =~ /^(\d+)\-(\d+)$/;
        my ($to,$next) = ($1,$2) if $1 && $2;
        if ($prev == $to+2) {
          $res .= "num = ".($prev+$vstart+1)." OR ";
        }
        else {
          $res .= "num > ".($prev+$vstart)." AND num < ".($to+$vstart)." OR "; # STRING
        }
        $prev = $next;
      }
      if ($prev == $nnode - 1) {
        $res =~ s/ OR $//;
      }
      else {
        if ($prev+$vstart == $nnode-2) {
          $res .= "num = ".($prev+$vstart+1); # STRING
        }
        else {
          $res .= "num > ".($prev+$vstart)." AND num < $nnode"; # STRING
        }
      }
      return $res;
    }

    sub get_thread_list_onew { my ($obj,$vector,$vstart,$nnode,$section,$order,$trnum) = @_;
      my $list = [];
      # SECOND CASE : view only unread threads
      my $where = get_unread($vector,$vstart,$nnode);
      # MODIFIED 19991001
      # we can't do this (maybe there is 'next page' or 'previous', 
      # and we must know this fact...
      #if ($order eq 'n') {
      #  $where .= ' AND t2.thrnum >= '.$trnum;
      #}
      #else {
      #  $where .= ' AND t2.thrnum <= '.$trnum;
      #}
      $list     = select_thread_list_byNode($obj,$section,$where);

      if (0) {
        print "<PRE> get_thread_list_onew: list get from mysql :\n\nthrnum\tparent\tnum\ttitle\tauthor\n";
        for my $i (@$list) {
          print qq{$$i[1]\t$$i[2]\t$$i[0]\t$$i[3]\t$$i[5]\n};
        }
        print "</PRE>\n";
      }
      return ($list);
    }

    sub mark_read_if_deleted { my ($n,$is_read,$rebuild,$vs,$v) = @_; # return ()
      if ($$n[4] && !$$is_read) { # if ($$n[4] eq 'y'...
        $$is_read = 1;
        $$rebuild = 'y';
        check_vector($vs,$$n[0],$v);
        vector_On($$n[0],$$vs,$v);
      }
    }

    sub make_tree { # my (...) = @_; # return (\@tree);
      # mhh... a comment here would be greet...
      my ($list,$start,$end,$parent,$vstart,$vector,$is_read,$rebuild_status,$nbanswer) = @_;
      my @res   = ();
      print qq{<PRE>} if $DEBUG;
      for my $i ($start..$end) {
        if ($list->[$i][2] == $parent) {
          my $this_read = vector_test($list->[$i][0],$$vstart,$vector);
          mark_read_if_deleted($list->[$i],\$this_read,$rebuild_status,$vstart,$vector);
          $$is_read = 0 if !$this_read;
          my @node = (@{$list->[$i]},
                      make_tree($list,$start,$end,$list->[$i][0],
                                $vstart,$vector,$is_read,$rebuild_status,$nbanswer),
                      $this_read);
          push @res,\@node and $$nbanswer++ if (!$list->[$i][4] || $#{$node[8]} > -1);
          #print qq{PUSH_NODES: thread($node[1]) num($node[0],$node[2]) erased($node[4]) '$node[3]'} if $DEBUG;
          #push @res,\@node and $$nbanswer++ if ($list->[$i][4] ne 'y' || $#{$node[8]} > -1);
        }
      }
      print qq{</PRE>} if $DEBUG;
      return \@res;
    }

    ###################################################
    # Sort the selected threads
    # Cleanup need here !
    ###################################################

    sub sort_list { my ($list,$obj,$sec,$unum,$type,$order,$trnum,$viewed,$nnode,$nthreads,$vstart,$vector) = @_;
      my ($lst_prev,$lst_next) = (0,0);

      #print qq{<PRE>LIST_SIZE (from DB) : ($#$list)</PRE>} if $DEBUG;

      my @tree  = ();
      my $rebuild_status = 'n';
      if ($#$list == 0) { # only one node
        my @son = ();
        my $bit_test = vector_test($$list[0][0],$vstart,$vector);
        mark_read_if_deleted($$list[0],\$bit_test,\$rebuild_status,\$vstart,$vector);
        # 19991117 my @firstnode = (@{$$list[0]},\@son,vector_test(0,$vstart,$vector),0);
        my @firstnode = (@{$$list[0]},\@son,$bit_test,0);
        @tree = (\@firstnode) if ($type eq 'a' || !$bit_test);
        if ($type eq 't') {
          $lst_next = 0;
          $lst_prev = 0;
        }
        else {
          $lst_prev = $firstnode[1] - 1;
          $lst_next = $firstnode[1] + 1;
          $lst_prev = 0 if $lst_prev < 0;
          $lst_next = 0 if $lst_next >= $nthreads;
        }
        return (\@tree,$lst_prev,$lst_next);
      }

      my $lsize   = $#$list; 
      my $counter = 0;
      my $tobe_continued = 0;
      my ($start,$end) = ($order eq 'n') ? (0,0) : ($lsize,$lsize);
      while (($order eq 'n' && $end < $lsize) || ($order eq 'p' && $end > -1)) {
        if ($order eq 'n') {
          while (defined($$list[$end+1]) && $$list[$start][1] == $$list[$end+1][1]) {
            $end++;
          }
        }
        else {
          while ($start > 0 && $$list[$start-1][1] == $$list[$end][1]) {
            $start--;
          }
        }
        my $bit_test = vector_test($$list[$start][0],$vstart,$vector);
        # if deleted but marked as unread...
        mark_read_if_deleted($$list[$start],\$bit_test,\$rebuild_status,\$vstart,$vector);
        my $is_read  = $bit_test;
        my $nbanswer = 0;
        my @node = (@{$$list[$start]},
                    make_tree($list,$start,$end,$$list[$start][0],
                              \$vstart,$vector,\$is_read,\$rebuild_status,\$nbanswer),
                    $bit_test,$nbanswer);
        if (($type eq 'a' || !$is_read) && (!$$list[$start][4] || $#{$node[8]} > -1)) {
          $counter += $nbanswer + 1;
          #$tobe_continued = 1, last if $counter > $viewed && $#tree != -1;
          if ($counter > $viewed && $#tree != -1) {
            $tobe_continued = 1;
            $lst_prev = $tree[0]->[1] if $order eq 'n';
            last;
          }
          push @tree, \@node;
        }
        if ($order eq 'n') {
          $lst_next = $node[1];
          $start = $end + 1;
        }
        else {
          $lst_prev = $node[1];
          $end = $start - 1;
        }
      }
      #print "<PRE> order $order lst_prev $lst_prev lst_next $lst_next</PRE>";
      if ($order eq 'n') {
        if ($type eq 'a') {
          $lst_prev = $tree[0]->[1] - 1 if $#tree > -1; # first thread number - 1
          $lst_prev = 0 if $trnum == 1;
        }
        $lst_next++; # until now, was the last, must be ++ to become the first of next page
        $lst_next = 0 if $type eq 't' && !$tobe_continued;
      }
      else {
        @tree = reverse(@tree); 
        $lst_prev--; # until now, was the last, must be ++ to become the first of next page
        $lst_prev = 0 if $type eq 't' && !$tobe_continued;
        $lst_next = $tree[$#tree]->[1] + 1 if $#tree > -1 && $type eq 'a'; # last thread number + 1
      }
      #print "<PRE> order $order lst_prev $lst_prev lst_next $lst_next</PRE>";
      $lst_prev = 0 if $lst_prev <= 0;
      $lst_next = 0 if $lst_next >= $nthreads;
      save_vector($obj,$unum,$sec,$vstart,$vector) if $rebuild_status eq 'y';

      return (\@tree,$lst_prev,$lst_next);
    }

  sub get_nodes      { my ($obj,$sec,$unum,$type,$order,$trnum,$viewed,$nnode,$nthreads) = @_; 
    # $sec : section
    # $unum is user-number
    # $type is [t|a|n] (see Discuss.pm)
    # $direction,$trnum,$viewed
    #    'p' or 'n' for $direction (previous or next)
    #    $trnum is the first *thread* to draw (and *not* the the node !!)
    #    $viewed is the max number of nodes to display
    # $nnode    is the max num of nodes
    # $nthreads is the max num of threads

    # PART I : get the list from database
    #
    # this part is the most important one, and *this* should
    # be optimized. Especially true for "SECOND CASE" (see bellow).

    # PART II : sort the list and, if needed, rebuild it.
    #
    # Rebuild include :
    # - mark the deleted nodes as read
    # - remove the already read threads if needed
    # - find values for $next_node and $prev_node for pagination

    $type = 'a' if !$type;
    if (1 == 0) {
      print qq{<PRE>GET_NODES: trnum $trnum order $order </PRE>};
      print qq{<PRE>type: $type </PRE>};
    }

    my ($vstart,$vector) = new_vector($obj,$unum,$sec);

    my ($list) = [];
    my ($tree,$lst_prev,$lst_next) = ([],0,0);
    if ($type eq 'a') {
      # return ALL threads
      $list = get_thread_list_all($obj,$sec,$order,$trnum,$viewed,$nnode,$nthreads);

      if ($DEBUG) {
        print "<PRE>";
        for my $i (@$list) {
          print "LIST_NODES: thread: thread($i->[1]) num($i->[0],$i->[2]) erased($i->[4] '$i->[3]')\n";
        }
        print "</PRE>";
      }

      ($tree,$lst_prev,$lst_next) = sort_list($list,$obj,$sec,$unum,
                                              $type,$order,$trnum,$viewed,
                                              $nnode,$nthreads,
                                              $vstart,$vector);
    }
    elsif ($type eq 'n') {
      # return NEW message only - NOT threaded
      print "NOT implemented yet.<P>";
    }
    else {
      # return new messages with full threads
      $list = get_thread_list_onew($obj,$vector,$vstart,$nnode,$sec,$order,$trnum);

      if ($#$list > $viewed) {
        # extraire la partie significative de la liste
        # et retourner le 'prev' si 'n' et le 'next' si 'p'
        # et mettre le resultat dans $list

          # si le resultat est petit ou null, retourner une partie
          # de la liste, si cette partie existe - et meme eventuellement
          # inverser 'n' et 'p'.

        my ($n_prev,$n_next) = (-1,-1);
        my @l2 = ();
        if ($order eq 'p') {
          for my $i (@$list) {
            if ($i->[1] > $trnum) {
              $n_next = $i->[1];
              $n_next = 0 if $n_next >= $nthreads;
              last;
            }
            push @l2,$i;
          }
          if ($#l2 > $#$list && $#l2 < $viewed) {
            $order  = 'n';
            $n_prev = 0;
            $n_next = -1;
            @l2 = @$list;
          }
        }
        else {
          for my $i (@$list) {
            if ($i->[1] < $trnum) {
              $n_prev = $i->[1];
              next;
            }
            push @l2,$i;
          }
          if ($#l2 > $#$list && $#l2 < $viewed) {
            $order  = 'p';
            $n_prev = -1;
            $n_next = 0;
            @l2 = @$list;
          }
        }
        ($tree,$lst_prev,$lst_next) = sort_list(\@l2,$obj,$sec,$unum,
                                                $type,$order,$trnum,$viewed,
                                                $nnode,$nthreads,
                                                $vstart,$vector);
        $lst_prev = $n_prev if $n_prev != -1;
        $lst_next = $n_next if $n_next != -1;
      }
      else {
        ($tree,$lst_prev,$lst_next) = sort_list($list,$obj,$sec,$unum,
                                                $type,$order,$trnum,$viewed,
                                                $nnode,$nthreads,
                                                $vstart,$vector);
      }

    }

    if (0) {
      print "<PRE>";
      for my $i (@$list) {
        print "GET_NODES: thread: thread($i->[1]) num($i->[0],$i->[2]) erased($i->[4] '$i->[3]')\n";
      }
      print "</PRE>";
    }

    return ($tree,$lst_prev,$lst_next);
  }

  sub get_thread { my ($obj,$sec,$thread,$time,$first,$last) = @_; # return @(\@(num));
    my $query = '';
    # MODIFIED 19990830
    if (defined($first) && defined($last)) {
      $query = qq{
        SELECT   num
        FROM     threads
        WHERE    section=$sec AND thrnum >= $first AND thrnum <= $last AND cdate < $time
      };
    }
    else {
      $query = qq{
        SELECT   num
        FROM     threads
        WHERE    section=$sec AND thrnum=$thread AND cdate < $time
      };
    }
    my $list = $obj->{"db"}->getall($query);
    return ($list);
  }
