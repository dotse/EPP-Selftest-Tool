<?php
# Copyright (c) 2009 YASK AB
# All rights reserved.
#
# This library is free software; you can redistribute it and/or modify
# it under the terms of the GNU Lesser General Public License as
# published by the Free Software Foundation; either version 2.1 of the
# License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful, but
# WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307
# USA.
#
# Written by Jan Saell <jan@irial.com>

// Library routines for ireg


  //--------------------
  // Function to log epp
  //--------------------
  function ireg_epp_log($log) {
    global $con;
    if (!is_callable(array($con, 'logFile'))) {
      return;
    }
    $con->logFile(" ---------------------------------------------------------------\n");
    if ($log->object != '') {
      $con->logFile("Contact: ".$log->object." Cmd: ".$log->command." Status: ".$log->status."\n");
    } else {
      $con->logFile("Cmd: ".$log->command." Status: ".$log->status."\n");
    }
    $con->logFile(" clTrid: ".$log->clTrid." svTrid: ".$log->svTrid."\n");
    $con->logFile(" ------------\n");
    $con->logFile(" Send: ".$log->sentXml."\n");
    $con->logFile(" ------------\n");
    $con->logFile(" Answer: ".$log->receivedXml."\n");
    $con->logFile(" ------------\n");
  }    

  //------------------------------
  // Function to turn on debugging
  //------------------------------
  function ireg_epp_log_start($registry, $con) {

    $EppDebug=true;
    $EppLoginDebug=true;

    if ($EppDebug) {
      $con->epp_log('ireg_epp_log');
      if ($EppLoginDebug) {
        $con->epp_loginlog('ireg_epp_log');
      }
    }
  }    

  //------------------------------
  // Function to create a password
  //------------------------------
  function iGenPwd($length = 9, $available_sets = 'luds')
  {
    $sets = array();
    if(strpos($available_sets, 'l') !== false)
        $sets[] = 'abcdefghjkmnpqrstuvwxyz';
    if(strpos($available_sets, 'u') !== false)
        $sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    if(strpos($available_sets, 'd') !== false)
        $sets[] = '23456789';
    if(strpos($available_sets, 's') !== false)
        $sets[] = '!@#$%&*?';

    $all = '';
    $password = '';
    foreach($sets as $set)
    {
        $password .= $set[array_rand(str_split($set))];
        $all .= $set;
    }

    $all = str_split($all);
    for($i = 0; $i < $length - count($sets); $i++)
        $password .= $all[array_rand($all)];

    $password = str_shuffle($password);

    return $password;

  }
?>
