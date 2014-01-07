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

print "EppDomCreate02 - test Create domain to tld epp server with subordinate hosts\n";

require_once('./lib/parseIniFile.php');
require_once('./lib/ireg.php');
require_once('./lib/ireg_obj.php');
require_once('./lib/ireg_lib.php');

// Parse argument
$args = getopt('c:h:p:st:');
foreach (array('c', 'h', 'p', 't') as $opt) {
	if (!array_key_exists($opt, $args)) {
		die("usage: pdtConnTest -c config_file -h epphost -p eppport [-s] -t timeout\n");
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
		'epphost' => $args['h'],
		'eppport' => $args['p'],
		'epptimeout' => $args['t']
	)
);
if (array_key_exists('s', $args)) {
	$init[$registry]['eppssl'] = 'true';
} else {
	$init[$registry]['eppssl'] = 'false';
}

$registry	= "tld";
$phase		= "EppDomCreate02";

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

$exCode=-1;

$domain = $config[$phase][$phase.'Name'];
$period = strtolower($config[$phase][$phase.'Period']);
$pno = $config[$phase][$phase.'PeriodValue'];
$ns01 = $config[$phase][$phase.'Ns01'];
$ns01ipv4 = $config[$phase][$phase.'Ns01Ipv4'];
$ns01ipv6 = $config[$phase][$phase.'Ns01Ipv6'];
$ns02 = $config[$phase][$phase.'Ns02'];
$ns02ipv4 = $config[$phase][$phase.'Ns02Ipv4'];
$ns02ipv6 = $config[$phase][$phase.'Ns02Ipv6'];
if (isset($config['EppConnTest']['EppNsHostUri'] ) ) {
	$hostObj = true;
} else {
	$hostObj = false;
}
// To handle create we user pdt EppDomCreate01's nameserver to create the domain so that we can then update it.
$oldns01 = $config["EppDomCreate01"]['EppDomCreate01Ns01'];
$oldns02 = $config["EppDomCreate01"]['EppDomCreate01Ns02'];

if ($hostObj == true) {
	// Create Ns01
	// Set phase to get the extensions right
	$con->setSubPhase("Ns01");

	$hostCre = new ireg_host;
	$hostCre->name = $ns01;
	$hostCre->insIpV4($ns01ipv4);
	$hostCre->insIpV6($ns01ipv6);
	if ($con->hostCreate($hostCre)) {
		if ($con->getResCode() != "1000") {
			print "Host Create (".$ns01.") FAILED - ResultCOde != 1000 (".$con->getResCode().")\n";
			$con->disconnect();
			exit (1);
		} else {
			print "Host Create (".$ns01.") OK - ResultCode = ".$con->getResCode()."\n";
			$exCode=0;
		}
	} else {
		$reason=$con->getErrorReason();
		if (isset($reason) && $reason != '') {
		  $reason=" (".$reason.")";
		} else {
		  $reason="";
		}
		print "Host create FAILED - ".$con->getResCode()." - ".$con->getResMsg().$reason."\n";
		$con->disconnect();
		exit (1);
	}

	// Create Ns02
	// Set phase to get the extensions right
	$con->setSubPhase("Ns02");

	$hostCre = new ireg_host;
	$hostCre->name = $ns02;
	$hostCre->insIpV4($ns02ipv4);
	$hostCre->insIpV6($ns02ipv6);
	if ($con->hostCreate($hostCre)) {
		if ($con->getResCode() != "1000") {
			print "Host Create (".$ns02.") FAILED - ResultCOde != 1000 (".$con->getResCode().")\n";
			$con->disconnect();
			exit (1);
		} else {
			print "Host Create (".$ns02.") OK - ResultCode = ".$con->getResCode()."\n";
			$exCode=0;
		}
	} else {
		$reason=$con->getErrorReason();
		if (isset($reason) && $reason != '') {
		  $reason=" (".$reason.")";
		} else {
		  $reason="";
		}
		print "Host create FAILED - ".$con->getResCode()." - ".$con->getResMsg().$reason."\n";
		$con->disconnect();
		exit (1);
	}
}

// And Update the domain
// Set phase to get the extensions right
$con->setSubPhase("Upd");

$domUpd = new ireg_domain;
$domUpd->name = $domain;
if ($hostObj == true) {
	$domUpd->addNs(array('name'=>$ns01));
	$domUpd->addNs(array('name'=>$ns02));
} else {
	$domUpd->addNs(array('name'=>$ns01, 'addr_v4'=>array($ns01ipv4), 'addr_v6'=>array($ns01ipv6)));
	$domUpd->addNs(array('name'=>$ns02, 'addr_v4'=>array($ns02ipv4), 'addr_v6'=>array($ns02ipv6)));
}

if ($con->domUpdate($domUpd)) {
	if (($con->getResCode() != "1000") && ($con->getResCode() != "1001")) {
		print "Domain Update (".$domain.") FAILED - ResultCode != 1000 or 1001 (".$con->getResCode().")\n";
		$con->disconnect();
		exit (1);
	} else {
		print "Domain Update (".$domain.") OK - ResultCode = ".$con->getResCode()."\n";
		$exCode=0;
	}
} else {
	$reason=$con->getErrorReason();
	if (isset($reason) && $reason != '') {
	  $reason=" (".$reason.")";
	} else {
	  $reason="";
	}
	print "Domain Update FAILED - ".$con->getResCode()." - ".$con->getResMsg().$reason."\n";
	$con->disconnect();
	exit (1);
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
