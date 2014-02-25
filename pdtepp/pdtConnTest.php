#!/usr/bin/php
<?php
# Copyright (c) 2013 .SE (The Internet Infrastructure Foundation).
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions
# are met:
# 1. Redistributions of source code must retain the above copyright
# notice, this list of conditions and the following disclaimer.
# 2. Redistributions in binary form must reproduce the above copyright
# notice, this list of conditions and the following disclaimer in the
# documentation and/or other materials provided with the distribution.
#
# THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR
# IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
# ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
# DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
# DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
# GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
# INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER
# IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR
# OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN
# IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
#
# Written by Jan Saell <jan@irial.com>

// IREG .SE PDT backend logic class

print "test connct (via hello) to tld epp server\n";

require_once('./lib/parseIniFile.php');
require_once('./lib/ireg.php');
require_once('./lib/ireg_obj.php');
require_once('./lib/ireg_lib.php');

// Parse argument
$args = getopt('c:h:p:st:6');
foreach (array('c', 't') as $opt) {
	if (!array_key_exists($opt, $args)) {
		die("usage: pdtConnTest -c config_file -h epphost -p eppport [-6] [-s] -t timeout\n");
	}
}

$CONF_FILE = $args['c'];
if (file_exists($CONF_FILE)) {
	$config = parseIniFile($CONF_FILE, true);
} else {
	die ("Cant open ini file '".$CONF_FILE."'\n");
}

$registry = "tld";

$init = array(
	$registry => array(
		'epptimeout' => $args['t']
	)
);
if (array_key_exists('h', $args)) {
        $init[$registry]['epphost'] = $args['h'];
} elseif (array_key_exists('6', $args)) {
	$init[$registry]['epphost'] = $config['EppConnTest']['EppServerIPv6'];
} else {
	$init[$registry]['epphost'] = $config['EppConnTest']['EppServerIPv4'];
}
if (array_key_exists('p', $args)) {
        $init[$registry]['eppport'] = $args['p'];
} elseif (array_key_exists('6', $args)) {
	$init[$registry]['eppport'] = $config['EppConnTest']['EppServerIPv6Port'];
} else {
	$init[$registry]['eppport'] = $config['EppConnTest']['EppServerIPv4Port'];
}
if (array_key_exists('s', $args)) {
	$init[$registry]['eppssl'] = 'true';
	if (isset($config['EppConnTest']['EppSNIServerName'])) {
		$init[$registry]['eppsniname'] = $config['EppConnTest']['EppSNIServerName'];
	}
} else {
	$init[$registry]['eppssl'] = 'false';
}

$registry	= "tld";
$phase		= "EppConnTest";

if (!isset($init[$registry])) {
	print $argv[0]." missing registry '".$registry."'\n";
	exit (2);
}
$EPP_USER	= $config['EppConnTest']['EppLoginId'];
$EPP_PWD	= $config['EppConnTest']['EppLoginPwd'];
$EPP_CERT_PATH	= $config['EppConnTest']['EppClientKeyPairPem'];
$EPP_CERT_PWD	= $config['EppConnTest']['EppClientKeyPairPwd'];

$con = new ireg($config, $phase, $EPP_USER, $EPP_PWD, $EPP_CERT_PATH, $EPP_CERT_PWD, $init);
ireg_epp_log_start($registry, $con);
//$con->debugOn("/tmp/js.txt");


$con->connect();
if ($con->isConnected() != true) {
	$reason=$con->getErrorReason();
	if (isset($reason) && $reason != '') {
		$reason=" (".$reason.")";
	} else {
		$reason="";
	}
	print "Connect FAILED - ".$con->getResCode()." - ".$con->getResMsg().$reason."\n";
	$con->disconnect();
	exit (1);
} else {
	if ($con->getResCode() != "1000") {
		print "Connect FAILED - ResultCode != 1000 (".$con->getResCode().")\n";
		$exCode=1;
	} else {
		print "Login OK - ResultCode = ".$con->getResCode()."\n";
		$exCode=0;
	}
}

// And disconnect
$con->disconnect();
if ($con->getResCode() != "1500") {
	print "Disconnect FAILED - ResultCOde != 1500 (".$con->getResCode().")\n";
	$exCode=1;
} else {
	print "Logout OK - ResultCode = ".$con->getResCode()."\n";
	$exCode=0;
}

exit($exCode);
?>
