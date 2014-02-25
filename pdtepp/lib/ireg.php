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

// IREG .SE PDT backend logic class

require_once('lib/parseIniFile.php');
$CONF="pdt.ini";

require_once('lib/Client.php');
require_once('lib/ireg_obj.php');
require_once('lib/ireg_epp_log.php');

//---------------------------------------------------------------------------
// Defines
//
define( 'XMLNS_EPP',             'urn:ietf:params:xml:ns:epp-1.0' );
define( 'XSCHEMA_EPP',           'urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd' );
define( 'XMLNS_XSCHEMA',         'http://www.w3.org/2001/XMLSchema-instance' );

//---------------------------------------------------------------------------
// IREG .SE PDT backend logic:
class ireg {

	private $log_callback;
	private $log_logincallback;
	private $connected;
        private $msgq;
        private $epp_user;
        private $epp_pwd;
	private $host;
	private $port;
	private $timeout;
	private $ssl;
	private $sniname;
	private $epp;
	private $resCode;
	private $msg;
	private $svTRID;
	private $clTRID;
	private $debug;
	private $dfh;
	private $error_reason;
	private $local_cert;
	private $local_cert_path;
	private $local_cert_pwd;
	private $config;
	private $tstPhase;
	private $subPhase;
	private $hostObj;

        public function __construct($conf, $phase, $user, $pwd, $cert_path='', $cert_pwd='', $init) {
		$this->config = $conf;
		$this->tstPhase = $phase;
		$this->subPhase = "";
		if (isset($this->config['EppConnTest']['EppNsHostUri'] ) ) {
			$this->hostObj = true;
		} else {
			$this->hostObj = false;
		}
		$this->log_callback = NULL;
		$this->log_logincallback = NULL;
		$this->dfh = NULL;
		$this->connected = false;
		$this->msgq = -1;
		$this->epp_user = $user;
		$this->epp_pwd = $pwd;
		$this->resCode = 0;
		$this->msg = 'Not Connected';
		$this->debug = false;
		$this->svTRID = '';
		$this->clTRID = '';
		$this->error_reason = '';
		$this->local_cert = false;
		$this->local_cert_path = NULL;
		$this->local_cert_pwd = NULL;
		if ($cert_path != '') {
			$this->local_cert	= true;
			$this->local_cert_path	= $cert_path;
			$this->local_cert_pwd	= $cert_pwd;
		}
                $this->initialize($init);
        }

	public function __destruct() {
		if ($this->debug) fwrite($this->dfh, "*in destructor()\n");
		if ($this->connected) {
			$this->disconnect();
		}
		if ($this->dfh != NULL) {
			fclose($this->dfh);
			$this->dfh = NULL;
		}
	}

        private function initialize($init) {
		$this->host	= $init['tld']['epphost'];
		$this->port	= $init['tld']['eppport'];
		if (isset($init['tld']['eppsniname'])) {
			$this->sniname	= $init['tld']['eppsniname'];
		}
		$this->timeout	= $init['tld']['epptimeout'];
		if ($init['tld']['eppssl'] == 'true') {
			$this->ssl	= true;
		} else {
			$this->ssl	= false;
		}
		//if ($init['se']['epp_local_cert'] == 'true') {
		//	$this->local_cert	= true;
		//	$this->local_cert_path	= $init['se']['epp_cert_path'];
		//	$this->local_cert_pwd	= $init['se']['epp_cert_pwd'];
		//}
	}

	public function epp_log($callback) {
		if ($this->debug) fwrite($this->dfh, "*in epp_log()\n");
		if (is_callable($callback)) {
			if ($this->debug) fwrite($this->dfh, "* epp_log - log_callback to ".$callback."\n");
			$this->log_callback = $callback;
		}
		return;
	}

	public function epp_loginlog($callback) {
		if ($this->debug) fwrite($this->dfh, "*in epp_loginlog()\n");
		if (is_callable($callback)) {
			if ($this->debug) fwrite($this->dfh, "* epp_loginlog - log_callback to ".$callback."\n");
			$this->log_logincallback = $callback;
		}
		return;
	}

	public function isConnected() {
		return $this->connected;
	}

	public function logFile($string) {
		fwrite(STDERR, $string);
	}

	public function debugFile($fname) {
		$this->dfh = fopen($fname, 'a') or die("can't open debug file");
		return;
	}

	public function debugOn($fname) {
		$this->dfh = fopen($fname, 'a') or die("can't open debug file");
		$this->debug = true;
		return;
	}

	public function debugOff() {
		$this->debug = false;
		return;
	}

	public function getMsgq() {
		if ($this->debug) fwrite($this->dfh, "*in getMsgq()\n");
		if ( ! $this->connected ) {
			$this->connect();
		}
		return $this->msgq;
	}

	public function getResCode() {
		return $this->resCode;
	}

	public function getResMsg() {
		return $this->msg;
	}

	public function getErrorReason() {
		return $this->error_reason;
	}

	public function getResSvTRID() {
		return $this->svTRID;
	}

	public function getResClTRID() {
		return $this->clTRID;
	}

	public function setSubPhase($subPhase) {
		$this->subPhase = $subPhase;
	}

	//- Connect to server -------------------------------------------------------
	public function connect() {
		if ($this->debug) fwrite($this->dfh, "*in connect()\n");
		if ($this->connected ) {
			return;
		}
		$this->epp = new Net_EPP_Client;

		if ($this->local_cert) {
			if ($this->debug) fwrite($this->dfh, "--- connect localcert(".$this->local_cert_path.")\n");
			$this->epp->local_cert($this->local_cert_path, $this->local_cert_pwd);
		}
		$greeting = $this->epp->connect($this->host, $this->port, $this->timeout, $this->ssl);
		if (PEAR::isError($greeting)) {
			$this->resCode = -1;
			$this->msg = "PEAR ERROR: ".$greeting->getMessage();
			return false;
		}
		if ($this->debug) fwrite($this->dfh,"------------\ngreeting: ".$greeting."\n");
		$xpath = $this->eppResultParse( $greeting );
		$this->epp_loglogincallback('greeting', "", $greeting);
		$svID = $xpath->query( '/epp:epp/epp:greeting/epp:svID/text()' )->item(0)->nodeValue;
		if ($this->debug) fwrite($this->dfh, " connect to server ".$svID."\n");

		//- Create the login xml ----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);

		// Build the command
		$cmd = $this->eppCreateLogin($root, $doc, $this->epp_user, $this->epp_pwd);
		$this->eppCreateCltrid($cmd, $doc);
		$login =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$login."\n");
		$answer = $this->epp->request($login);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_loglogincallback('login', $login, $answer);
		if ($this->resCode == 1000) {
			$this->connected = true;
		}
	}

	private function parseResult($answer) {
		if ($this->debug) fwrite($this->dfh, "*in parseResult()\n");
		$xpath = $this->eppResultParse( $answer );
		$this->resCode = $xpath->query( '/epp:epp/epp:response/epp:result/@code' )->item(0)->nodeValue;
		$this->msg = $xpath->query( '/epp:epp/epp:response/epp:result/epp:msg/text()' )->item(0)->nodeValue;
		$fname=$xpath->evaluate('name(/epp:epp/epp:response/epp:trID/epp:svTRID)');
		if ($fname == 'svTRID' ) {
			$this->svTRID = $xpath->query( '/epp:epp/epp:response/epp:trID/epp:svTRID/text()' )->item(0)->nodeValue;
		} else {
			$this->svTRID = '';
		}
		if ($this->resCode < 2000) {
			$fname=$xpath->evaluate('name(/epp:epp/epp:response/epp:msgQ)');
			if (($fname == 'epp:msgQ') || ($fname == 'msgQ') ) {
				$this->msgq = (int)$xpath->query( '/epp:epp/epp:response/epp:msgQ/@count' )->item(0)->nodeValue;
			} else {
				$this->msgq = 0;
			}
			$this->error_reason = '';
		} else {
			$fname=$xpath->evaluate('name(/epp:epp/epp:response/epp:msgQ)');
			if (($fname == 'epp:msgQ') || ($fname == 'msgQ')) {
				$this->msgq = (int)$xpath->query( '/epp:epp/epp:response/epp:msgQ/@count' )->item(0)->nodeValue;
			} else {
				$this->msgq = 0;
			}
			$fname=$xpath->evaluate('name(/epp:epp/epp:response/epp:result/epp:extValue/epp:reason)');
			if ($fname == 'reason') {
				//$this->error_reason = $xpath->evaluate('name(/epp:epp/epp:response/epp:result/epp:extValue/epp:reason[text()])');
				//$this->error_reason = $xpath->query( '/epp:epp/epp:response/epp:result/epp:extValue/epp:reason/text()' )->item(0)->nodeValue;
				$reason = $xpath->query( '/epp:epp/epp:response/epp:result/epp:extValue/epp:reason/text()' );
				if (is_object($reason->item(0))) {
					$this->error_reason = $reason->item(0)->nodeValue;
				}
			} else {
				$this->error_reason = '';
			}
		}
		return $xpath;
	}

	public function disconnect() {
		if ($this->debug) fwrite($this->dfh, "*in disconnect()\n");
		if (!$this->connected ) {
			return;
		}
		//- Create the logout xml ---------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);

		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$cmd->appendChild( $doc->createElement( 'logout' ) );
		$this->eppCreateCltrid($cmd, $doc);

		$logout =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$logout."\n");
		$answer = $this->epp->request($logout);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");

		//- Check responses ---------------------------------------------------------
		// We dont realy care about the result, but call parse so we get the msg
		$this->parseResult($answer);
		$this->epp_loglogincallback('logout', $logout, $answer);

		//- Dissconnct --------------------------------------------------------------
		$this->epp->disconnect();
		$this->connected = false;
	}

	//---------------------------------------------------------------------------
	public function hello_nologin() {
		if ($this->debug) fwrite($this->dfh, "*in hello()\n");
		$this->epp = new Net_EPP_Client;

		if ($this->local_cert) {
			if ($this->debug) fwrite($this->dfh, "--- connect localcert(".$this->local_cert_path.")\n");
			$this->epp->local_cert($this->local_cert_path, $this->local_cert_pwd);
		}
		$greeting = $this->epp->connect($this->host, $this->port, $this->timeout, $this->ssl);
		if (PEAR::isError($greeting)) {
			$this->resCode = -1;
			$this->msg = "PEAR ERROR: ".$greeting->getMessage();
			return false;
		}
		if ($this->debug) fwrite($this->dfh,"------------\ngreeting: ".$greeting."\n");
		$xpath = $this->eppResultParse( $greeting );
		$svID = $xpath->query( '/epp:epp/epp:greeting/epp:svID/text()' )->item(0)->nodeValue;
		if ($this->debug) fwrite($this->dfh, " connect to server ".$svID."\n");

		//- Create the helo xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the hello command
		$cmd   = $root->appendChild( $doc->createElement( 'hello' ) );
		//$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if (PEAR::isError($answer)) {
			$this->resCode = -1;
			$this->msg = "PEAR ERROR: ".$answer->getMessage();
			return false;
		}
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$this->epp_loglogincallback('hello', $info, $answer);
		return "0";

		//- Dissconnct --------------------------------------------------------------
		$this->epp->disconnect();
		$this->connected = false;
	}

	//---------------------------------------------------------------------------
	public function hello() {
		if ($this->debug) fwrite($this->dfh, "*in hello()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the helo xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the hello command
		$cmd   = $root->appendChild( $doc->createElement( 'hello' ) );
		//$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$this->epp_loglogincallback('hello', $info, $answer);
		return "0";
	}

	//---------------------------------------------------------------------------
	public function domCheck($domName) {
		if ($this->debug) fwrite($this->dfh, "*in domCheck()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$dom  = $chk->appendChild( $doc->createElement( 'domain:check' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domName ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', $domName, $info, $answer);
		if ($this->resCode == 1000) {
			$avail = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:chkData/dom:cd/dom:name/@avail' )->item(0)->nodeValue;
			return $avail;
		}
		return "0";
	}

	//---------------------------------------------------------------------------
	public function domCheckMulti($domArray) {
		if ($this->debug) fwrite($this->dfh, "*in domCheckMulti()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$dom  = $chk->appendChild( $doc->createElement( 'domain:check' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		for ($i=0; $i<count($domArray); $i++) {
			$dom->appendChild( $doc->createElement( 'domain:name', $domArray[$i] ) );
		}
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', implode(", ", $domArray), $info, $answer);
		$avail = array();
		if ($this->resCode == 1000) {
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/dom:chkData/dom:cd)' );
			for ($i=1; $i<=$no; $i++) {
				$name = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:chkData/dom:cd['.$i.']/dom:name/text()' )->item(0)->nodeValue;
				$avail[$name] = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:chkData/dom:cd['.$i.'.]/dom:name/@avail' )->item(0)->nodeValue;
			}
		}
		return $avail;
	}

	//---------------------------------------------------------------------------
	public function domInfo($domName) {
		if ($this->debug) fwrite($this->dfh, "*in domInfo()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return NULL;
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'info' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:info' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$nam = $dom->appendChild( $doc->createElement( 'domain:name', $domName ) );
		$nam->appendChild( $this->xmlCreateAttribute( $doc, 'hosts', 'all' ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('info', $domName, $info, $answer);
		$domRet = new ireg_domain;
		if ($this->resCode == 1000) {
			$domRet->name = $domName;
			$domRet->roid = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:roid/text()' )->item(0)->nodeValue;
			$domRet->clID = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:clID/text()' )->item(0)->nodeValue;
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/dom:infData/dom:status/@s)' );
			$first=true;
			for ($i=0; $i<$no; $i++) {
				$status = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:status/@s' )->item($i)->nodeValue;
				if ($first) {
					$domRet->status = $status;
					$first = false;
				} else {
					$domRet->status .= ", ".$status;
				}
			}
			$domRet->crID = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:crID/text()' )->item(0)->nodeValue;
			$domRet->crDate = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:crDate/text()' )->item(0)->nodeValue;
			$domRet->exDate = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:exDate/text()' )->item(0)->nodeValue;
			$tr=$xpath->evaluate( 'name(/epp:epp/epp:response/epp:resData/dom:infData/dom:trDate)' );
			if (!empty($tr)) {
				$domRet->trDate = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:trDate/text()' )->item(0)->nodeValue;
			}
			$upd=$xpath->evaluate( 'name(/epp:epp/epp:response/epp:resData/dom:infData/dom:upID)' );
			if (!empty($upd)) {
				$domRet->upID = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:upID/text()' )->item(0)->nodeValue;
				$domRet->upDate = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:upDate/text()' )->item(0)->nodeValue;
			}
			$domRet->insCont('owner', $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:registrant/text()' )->item(0)->nodeValue);
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/dom:infData/dom:contact)' );
			for ($i=1; $i<=$no; $i++) {
				$id = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:contact['.$i.']/text()' )->item(0)->nodeValue;
				$typ = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:contact['.$i.']/@type' )->item(0)->nodeValue;
				$domRet->insCont($typ, $id);
			}
			# OBS !!!! Not fixed for Host Attributes
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/dom:infData/dom:ns/dom:hostObj)' );
			for ($i=1; $i<=$no; $i++) {
				$ns = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:ns/dom:hostObj['.$i.']/text()' )->item(0)->nodeValue;
				$domRet->insNs($ns);
			}
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/dom:infData/dom:host)' );
			for ($i=1; $i<=$no; $i++) {
				$ns = $xpath->query( '/epp:epp/epp:response/epp:resData/dom:infData/dom:host['.$i.']/text()' )->item(0)->nodeValue;
				$domRet->insHost($ns);
			}
//			$domRet->deactDate = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:extension/iis:infData/iis:deactDate' );
//			$domRet->delDate = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:extension/iis:infData/iis:delDate' );
//			$no = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:extension/iis:infData/iis:clientDelete' );
//			if ($no == 1) {
//				$domRet->cliDel = true;
//			} else {
//				$domRet->cliDel = false;
//			}
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:extension/sec:infData/sec:dsData)' );
			for ($i=1; $i<=$no; $i++) {
				$keyTag = $xpath->query( '/epp:epp/epp:response/epp:extension/sec:infData/sec:dsData['.$i.']/sec:keyTag/text()' )->item(0)->nodeValue;
				$alg = $xpath->query( '/epp:epp/epp:response/epp:extension/sec:infData/sec:dsData['.$i.']/sec:alg/text()' )->item(0)->nodeValue;
				$dType = $xpath->query( '/epp:epp/epp:response/epp:extension/sec:infData/sec:dsData['.$i.']/sec:digestType/text()' )->item(0)->nodeValue;
				$digest = $xpath->query( '/epp:epp/epp:response/epp:extension/sec:infData/sec:dsData['.$i.']/sec:digest/text()' )->item(0)->nodeValue;
				$domRet->insSecDs(array('keyTag'=>$keyTag, 'alg'=>$alg, 'dType'=>$dType, 'digest'=>$digest));
			}
			return $domRet;
		}
		return NULL;
	}

	//---------------------------------------------------------------------------
	public function domCreate($domCre, $period = 1, $unit = 'y') {
		if ($this->debug) fwrite($this->dfh, "*in domCreate()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Verify parameters --------------------------------------------------------
		if ($unit != '') {
			if (($unit != 'y') && ($unit != 'm')) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'unit has to be \'y\' or \'m\'';
				return false;
			}
			if (($unit == 'y') && (($period < 1) || ($period > 10))) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'for unit \'y\' persiod has to be between 1 and 10';
				return false;
			}
			if (($unit == 'm') && (($period < 12) || ($period > 120))) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'for unit \'y\' persiod has to be between 12 and 120';
				return false;
			}
		}
		//- Create the create xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'create' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:create' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domCre->name ) );
		if ($unit != '') {
			$period = $dom->appendChild( $doc->createElement( 'domain:period', $period ) );
			$period->appendChild( $this->xmlCreateAttribute( $doc, 'unit', $unit ) );
		}
		//NS
		if (count($domCre->ns) > 0) {
			$ns = $dom->appendChild( $doc->createElement( 'domain:ns') );
			for ($i=0; $i<count($domCre->ns); $i++) {
				if ($this->hostObj) {
					$ns->appendChild( $doc->createElement( 'domain:hostObj', $domCre->ns[$i]['name'] ) );
				} else {
					$ha = $ns->appendChild( $doc->createElement( 'domain:hostAttr') );
					$ha->appendChild( $doc->createElement( 'domain:hostName', $domCre->ns[$i]['name'] ) );
					for ($j=0; $j<count($domCre->ns[$i]['addr_v4']); $j++) {
						$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $domCre->ns[$i]['addr_v4'][$j] ) );
						$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
					}
					for ($j=0; $j<count($domCre->ns[$i]['addr_v6']); $j++) {
						$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $domCre->ns[$i]['addr_v6'][$j] ) );
						$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
					}
				}
			}
		}
		$dom->appendChild( $doc->createElement( 'domain:registrant', $domCre->contOwn[0] ) );
		for ($i=0; $i<count($domCre->contAdm); $i++) {
			$contact = $dom->appendChild( $doc->createElement( 'domain:contact', $domCre->contAdm[$i] ) );
			$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'admin' ) );
		}
		for ($i=0; $i<count($domCre->contBill); $i++) {
			$contact = $dom->appendChild( $doc->createElement( 'domain:contact', $domCre->contBill[$i] ) );
			$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'billing' ) );
		}
		for ($i=0; $i<count($domCre->contTech); $i++) {
			$contact = $dom->appendChild( $doc->createElement( 'domain:contact', $domCre->contTech[$i] ) );
			$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'tech' ) );
		}
		$auth = $dom->appendChild( $doc->createElement( 'domain:authInfo') );
		$auth->appendChild( $doc->createElement( 'domain:pw', $this->xmlAmpQuote($domCre->authPw) ) );
		// SecDNS
		//$ext = $cmd->appendChild( $doc->createElement( 'extension') );
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		if ((count($domCre->secDs) > 0) or (count($domCre->secKd) > 0)) {
			$hasExt = true;
			$sec  = $ext->appendChild( $doc->createElement( 'secDNS:create') );
			$sec->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:secDNS', $this->config['EppConnTest']['EppExtSecDnsUri']));
			$sec->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppExtSecDnsSl']));
			if (($domCre->secType == "D") || ($domCre->secType == "DK")) {
				for ($i=0; $i<count($domCre->secDs); $i++) {
					$ds  = $sec->appendChild( $doc->createElement( 'secDNS:dsData'));
					$ds->appendChild( $doc->createElement( 'secDNS:keyTag', $domCre->secDs[$i]['keyTag']) );
					$ds->appendChild( $doc->createElement( 'secDNS:alg', $domCre->secDs[$i]['alg']) );
					$ds->appendChild( $doc->createElement( 'secDNS:digestType', $domCre->secDs[$i]['dType']) );
					$ds->appendChild( $doc->createElement( 'secDNS:digest', $domCre->secDs[$i]['digest']) );
					if ($domCre->secType == "DK") {
						$dsks  = $ds->appendChild( $doc->createElement( 'secDNS:keyData'));
						$dsks->appendChild( $doc->createElement( 'secDNS:flags', $domCre->secDsKd[$i]['flags']) );
						$dsks->appendChild( $doc->createElement( 'secDNS:protocol', $domCre->secDsKd[$i]['protocol']) );
						$dsks->appendChild( $doc->createElement( 'secDNS:alg', $domCre->secDsKd[$i]['alg']) );
						$dsks->appendChild( $doc->createElement( 'secDNS:pubKey', $domCre->secDsKd[$i]['pubKey']) );
					}
				}
			}
			if ($domCre->secType == "K") {
				for ($i=0; $i<count($domCre->secKd); $i++) {
					$ks  = $sec->appendChild( $doc->createElement( 'secDNS:keyData'));
					$ks->appendChild( $doc->createElement( 'secDNS:flags', $domCre->secKd[$i]['flags']) );
					$ks->appendChild( $doc->createElement( 'secDNS:protocol', $domCre->secKd[$i]['protocol']) );
					$ks->appendChild( $doc->createElement( 'secDNS:alg', $domCre->secKd[$i]['alg']) );
					$ks->appendChild( $doc->createElement( 'secDNS:pubKey', $domCre->secKd[$i]['pubKey']) );
				}
			}
		}
		// Add extensions values
		$addext = $this->eppAddExtension('create', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$create =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$create."\n");
		$answer = $this->epp->request($create);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('create', $domCre->name, $create, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function domDelete($domain) {
		if ($this->debug) fwrite($this->dfh, "*in conDelete()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the delete xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'delete' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'domain:delete' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$con->appendChild( $doc->createElement( 'domain:name', $domain ) );
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('delete', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$delete =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$delete."\n");
		$answer = $this->epp->request($delete);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('delete', $domain, $delete, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function domUpdate($domUpd) {
		if ($this->debug) fwrite($this->dfh, "in domUpdate\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the update xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd  = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'update' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:update' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domUpd->name ) );
		if ($domUpd->hasChg()) {
			$chg = $dom->appendChild( $doc->createElement( 'domain:chg' ) );
			if (isset($domUpd->chgRegistrant)) {
				$chg->appendChild( $doc->createElement( 'domain:registrant', $domUpd->chgRegistrant ) );
			}
			if (isset($domUpd->authPw)) {
				$auth = $chg->appendChild( $doc->createElement( 'domain:authInfo') );
				$auth->appendChild( $doc->createElement( 'domain:pw', $this->xmlAmpQuote($domUpd->authPw) ) );
			}
		}
		if ($domUpd->hasAdd()) {
			$add = $dom->appendChild( $doc->createElement( 'domain:add' ) );
			$ns = $domUpd->getAdd('ns');
			if (count($ns) > 0) {
				$domns = $add->appendChild( $doc->createElement( 'domain:ns' ) );
				for ($i=0; $i<count($ns); $i++) {
					if ($this->hostObj) {
						$domns->appendChild( $doc->createElement( 'domain:hostObj', $ns[$i]['name'] ) );
					} else {
						$ha = $domns->appendChild( $doc->createElement( 'domain:hostAttr') );
						$ha->appendChild( $doc->createElement( 'domain:hostName', $ns[$i]['name'] ) );
						for ($j=0; $j<count($ns[$i]['addr_v4']); $j++) {
							$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $ns[$i]['addr_v4'][$j] ) );
							$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
						}
						for ($j=0; $j<count($ns[$i]['addr_v6']); $j++) {
							$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $ns[$i]['addr_v6'][$j] ) );
							$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
						}
					}
				}
			}
			$cont = $domUpd->getAdd('contAdm');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $add->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'admin' ) );
			}
			$cont = $domUpd->getAdd('contBill');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $add->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'billing' ) );
			}
			$cont = $domUpd->getAdd('contTech');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $add->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'tech' ) );
			}
			$cont = $domUpd->getAdd('status');
			for ($i=0; $i<count($cont); $i++) {
				$stat = $add->appendChild( $doc->createElement( 'domain:status' ) );
				$stat->appendChild( $this->xmlCreateAttribute( $doc, 's', $cont[$i] ) );
			}
			// ClientHold
		}
		if ($domUpd->hasRem()) {
			$rem = $dom->appendChild( $doc->createElement( 'domain:rem' ) );
			$ns = $domUpd->getRem('ns');
			if (count($ns) > 0) {
				$domns = $rem->appendChild( $doc->createElement( 'domain:ns' ) );
				for ($i=0; $i<count($ns); $i++) {
					if ($this->hostObj) {
						$domns->appendChild( $doc->createElement( 'domain:hostObj', $ns[$i]['name'] ) );
					} else {
						$ha = $domns->appendChild( $doc->createElement( 'domain:hostAttr') );
						$ha->appendChild( $doc->createElement( 'domain:hostName', $ns[$i]['name'] ) );
						for ($j=0; $j<count($ns[$i]['addr_v4']); $j++) {
							$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $ns[$i]['addr_v4'][$j] ) );
							$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
						}
						for ($j=0; $j<count($ns[$i]['addr_v6']); $j++) {
							$haa = $ha->appendChild( $doc->createElement( 'domain:hostAddr', $ns[$i]['addr_v6'][$j] ) );
							$haa->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
						}
					}
				}
			}
			$cont = $domUpd->getRem('contAdm');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $rem->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'admin' ) );
			}
			$cont = $domUpd->getRem('contBill');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $rem->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'billing' ) );
			}
			$cont = $domUpd->getRem('contTech');
			for ($i=0; $i<count($cont); $i++) {
				$contact = $rem->appendChild( $doc->createElement( 'domain:contact', $cont[$i] ) );
				$contact->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'tech' ) );
			}
			$cont = $domUpd->getRem('status');
			for ($i=0; $i<count($cont); $i++) {
				$stat = $rem->appendChild( $doc->createElement( 'domain:status' ) );
				$stat->appendChild( $this->xmlCreateAttribute( $doc, 's', $cont[$i] ) );
			}
			// ClientHold
		}
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// SecDNS
		if ($domUpd->hasSecAdd() || $domUpd->hasSecRem() || $domUpd->hasSecRemAll()) {
			$hasExt = true;
			$sec  = $ext->appendChild( $doc->createElement( 'secDNS:update') );
			$sec->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:secDNS', $this->config['EppConnTest']['EppExtSecDnsUri']));
			$sec->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppExtSecDnsSl']));
			if ($domUpd->hasSecAdd()) {
				$add = $sec->appendChild( $doc->createElement( 'secDNS:add' ) );
				if (($domUpd->secType == "D") || ($domUpd->secType == "DK")) {
					for ($i=0; $i<count($domUpd->addSecDs); $i++) {
						$ds  = $add->appendChild( $doc->createElement( 'secDNS:dsData'));
						$ds->appendChild( $doc->createElement( 'secDNS:keyTag', $domUpd->addSecDs[$i]['keyTag']) );
						$ds->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->addSecDs[$i]['alg']) );
						$ds->appendChild( $doc->createElement( 'secDNS:digestType', $domUpd->addSecDs[$i]['dType']) );
						$ds->appendChild( $doc->createElement( 'secDNS:digest', $domUpd->addSecDs[$i]['digest']) );
						if ($domUpd->secType == "DK") {
							$dsks  = $ds->appendChild( $doc->createElement( 'secDNS:keyData'));
							$dsks->appendChild( $doc->createElement( 'secDNS:flags', $domUpd->addSecDsKd[$i]['flags']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:protocol', $domUpd->addSecDsKd[$i]['protocol']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->addSecDsKd[$i]['alg']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:pubKey', $domUpd->addSecDsKd[$i]['pubKey']) );
						}
					}
				}
				if ($domUpd->secType == "K") {
					for ($i=0; $i<count($domUpd->addSecKd); $i++) {
						$ks  = $add->appendChild( $doc->createElement( 'secDNS:keyData'));
						$ks->appendChild( $doc->createElement( 'secDNS:flags', $domUpd->addSecKd[$i]['flags']) );
						$ks->appendChild( $doc->createElement( 'secDNS:protocol', $domUpd->addSecKd[$i]['protocol']) );
						$ks->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->addSecKd[$i]['alg']) );
						$ks->appendChild( $doc->createElement( 'secDNS:pubKey', $domUpd->addSecKd[$i]['pubKey']) );
					}
				}
			}
			if ($domUpd->hasSecRem()) {
				$rem = $sec->appendChild( $doc->createElement( 'secDNS:rem' ) );
				if (($domUpd->secType == "D") || ($domUpd->secType == "DK")) {
					for ($i=0; $i<count($domUpd->remSecDs); $i++) {
						$ds  = $rem->appendChild( $doc->createElement( 'secDNS:dsData'));
						$ds->appendChild( $doc->createElement( 'secDNS:keyTag', $domUpd->remSecDs[$i]['keyTag']) );
						$ds->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->remSecDs[$i]['alg']) );
						$ds->appendChild( $doc->createElement( 'secDNS:digestType', $domUpd->remSecDs[$i]['dType']) );
						$ds->appendChild( $doc->createElement( 'secDNS:digest', $domUpd->remSecDs[$i]['digest']) );
						if ($domCre->secType == "DK") {
							$dsks  = $ds->appendChild( $doc->createElement( 'secDNS:keyData'));
							$dsks->appendChild( $doc->createElement( 'secDNS:flags', $domUpd->remSecDsKd[$i]['flags']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:protocol', $domUpd->remSecDsKd[$i]['protocol']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->remSecDsKd[$i]['alg']) );
							$dsks->appendChild( $doc->createElement( 'secDNS:pubKey', $domUpd->remSecDsKd[$i]['pubKey']) );
						}
					}
				}
				if ($domUpd->secType == "K") {
					for ($i=0; $i<count($domUpd->remSecKd); $i++) {
						$ks  = $rem->appendChild( $doc->createElement( 'secDNS:keyData'));
						$ks->appendChild( $doc->createElement( 'secDNS:flags', $domUpd->remSecKd[$i]['flags']) );
						$ks->appendChild( $doc->createElement( 'secDNS:protocol', $domUpd->remSecKd[$i]['protocol']) );
						$ks->appendChild( $doc->createElement( 'secDNS:alg', $domUpd->remSecKd[$i]['alg']) );
						$ks->appendChild( $doc->createElement( 'secDNS:pubKey', $domUpd->remSecKd[$i]['pubKey']) );
					}
				}
			}
			if ($domUpd->hasSecRemAll()) {
				$rem = $sec->appendChild( $doc->createElement( 'secDNS:rem' ) );
				$rem->appendChild( $doc->createElement( 'secDNS:all', 'true') );
			}
		}
		// Add extensions values
		$addext = $this->eppAddExtension('update', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$update =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$update."\n");
		$answer = $this->epp->request($update);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('update', $domUpd->name, $update, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function domTransferReq($domName, $authPw, $authRoid = "", $period = 0, $unit = 'y') {
		if ($this->debug) fwrite($this->dfh, "*in domTransferReq()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the transfer xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd  = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'transfer' ) );
		$chk->appendChild( $this->xmlCreateAttribute( $doc, 'op', 'request' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:transfer' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domName ) );
		if ($period != 0) {
			$period = $dom->appendChild( $doc->createElement( 'domain:period', $period ) );
			$period->appendChild( $this->xmlCreateAttribute( $doc, 'unit', $unit ) );
		}
		$auth = $dom->appendChild( $doc->createElement( 'domain:authInfo' ) );
		$pwd = $auth->appendChild( $doc->createElement( 'domain:pw', $this->xmlAmpQuote($authPw) ) );
		if ($authRoid != "") {
			$pwd->appendChild( $this->xmlCreateAttribute( $doc, 'roid', $authRoid ) );
		}
		//$auth->appendChild( $doc->createCDATASection( $authPw ) );
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('transfer', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$transfer =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$transfer."\n");
		$answer = $this->epp->request($transfer);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('transfer', $domName, $transfer, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function domTransferAcc($domName, $authPw, $authRoid = "", $period = 0, $unit = 'y') {
		if ($this->debug) fwrite($this->dfh, "*in domTransferAcc()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the transfer xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd  = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'transfer' ) );
		$chk->appendChild( $this->xmlCreateAttribute( $doc, 'op', 'approve' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:transfer' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domName ) );
		if ($period != 0) {
			$period = $dom->appendChild( $doc->createElement( 'domain:period', $period ) );
			$period->appendChild( $this->xmlCreateAttribute( $doc, 'unit', $unit ) );
		}
		$auth = $dom->appendChild( $doc->createElement( 'domain:authInfo' ) );
		$pwd = $auth->appendChild( $doc->createElement( 'domain:pw', $this->xmlAmpQuote($authPw) ) );
		if ($authRoid != "") {
			$pwd->appendChild( $this->xmlCreateAttribute( $doc, 'roid', $authRoid ) );
		}
		//$auth->appendChild( $doc->createCDATASection( $authPw ) );
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('transfer', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$transfer =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$transfer."\n");
		$answer = $this->epp->request($transfer);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('transfer', $domName, $transfer, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function domRenew($domName, $expDate, $period = 1, $unit = 'y') {
		if ($this->debug) fwrite($this->dfh, "*in domRenew()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Verify parameters --------------------------------------------------------
		if ($unit != '') {
			if (($unit != 'y') && ($unit != 'm')) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'unit has to be \'y\' or \'m\'';
				return false;
			}
			if (($unit == 'y') && (($period < 1) || ($period > 10))) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'for unit \'y\' persiod has to be between 1 and 10';
				return false;
			}
			if (($unit == 'm') && (($period < 12) || ($period > 120))) {
				$this->resCode = -1;
				$this->msg = 'Parameter error';
				$this->error_reason = 'for unit \'m\' persiod has to be between 12 and 120';
				return false;
			}
		}
		//- Create the renew xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd  = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'renew' ) );
		$cmd->appendChild( $chk );
		$dom = $chk->appendChild( $doc->createElement( 'domain:renew' ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:domain', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$dom->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsDomainSl'] ) );
		$dom->appendChild( $doc->createElement( 'domain:name', $domName ) );
		$dom->appendChild( $doc->createElement( 'domain:curExpDate', $expDate ) );
		if ($unit != '') {
			$period = $dom->appendChild( $doc->createElement( 'domain:period', $period ) );
			$period->appendChild( $this->xmlCreateAttribute( $doc, 'unit', $unit ) );
		}
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('renew', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$renew =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$renew."\n");
		$answer = $this->epp->request($renew);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('renew', $domName, $renew, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function conCheck($contactId) {
		if ($this->debug) fwrite($this->dfh, "*in conCheck()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:check' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		$con->appendChild( $doc->createElement( 'contact:id', $contactId ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', $contactId, $info, $answer);
		if ($this->resCode == 1000) {
			$avail = $xpath->query( '/epp:epp/epp:response/epp:resData/con:chkData/con:cd/con:id/@avail' )->item(0)->nodeValue;
			return $avail;
		}
		return "0";
	}

	//---------------------------------------------------------------------------
	public function conCheckMulti($contactArray) {
		if ($this->debug) fwrite($this->dfh, "*in conCheckMulti()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:check' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		for ($i=0; $i<count($contactArray); $i++) {
			$con->appendChild( $doc->createElement( 'contact:id', $contactArray[$i] ) );
		}
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', implode(", ", $contactArray), $info, $answer);
		$avail = array();
		if ($this->resCode == 1000) {
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/con:chkData/con:cd)' );
			for ($i=1; $i<=$no; $i++) {
				$name = $xpath->query( '/epp:epp/epp:response/epp:resData/con:chkData/con:cd['.$i.']/con:id/text()' )->item(0)->nodeValue;
				$avail[$name] = $xpath->query( '/epp:epp/epp:response/epp:resData/con:chkData/con:cd['.$i.'.]/con:id/@avail' )->item(0)->nodeValue;
			}
		}
		return $avail;
	}

	//---------------------------------------------------------------------------
	public function conInfo($contactId) {
		if ($this->debug) fwrite($this->dfh, "*in conInfo()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return NULL;
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'info' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:info' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		$con->appendChild( $doc->createElement( 'contact:id', $contactId ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('info', $contactId, $info, $answer);
		$conRet = new ireg_contact;
		if ($this->resCode == 1000) {
			$conRet->id = $contactId;
			$conRet->roid = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:roid/text()' )->item(0)->nodeValue;
			$conRet->clID = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:clID/text()' )->item(0)->nodeValue;
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/con:infData/con:status/@s)' );
			$first=true;
			for ($i=0; $i<$no; $i++) {
				$status = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:status/@s' )->item($i)->nodeValue;
				if ($first) {
					$conRet->status = $status;
					$first = false;
				} else {
					$conRet->status .= ", ".$status;
				}
			}
			$conRet->crID = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:crID/text()' )->item(0)->nodeValue;
			$conRet->crDate = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:crDate/text()' )->item(0)->nodeValue;
			$upd=$xpath->evaluate( 'name(/epp:epp/epp:response/epp:resData/con:infData/con:upID)' );
			if (!empty($upd)) {
				$conRet->upID = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:upID/text()' )->item(0)->nodeValue;
				$conRet->upDate = $xpath->query( '/epp:epp/epp:response/epp:resData/con:infData/con:upDate/text()' )->item(0)->nodeValue;
			}
			// Get the Postal Info
			$noPost = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo)' );
			for ($i=1; $i<=$noPost; $i++) {
				$type=$xpath->query('/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/@type')->item(0)->nodeValue;
				switch ($type) {
					case 'loc':
						$conRet->postalLocName = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:name');
						$conRet->postalLocOrg = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:org');
						$noAddr = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:street)' );
						$query = $xpath->query('/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:street/text()');
						if ($noAddr >= 1)
							$conRet->postalLocAddrStreet1 = $query->item(0)->nodeValue;
						if ($noAddr >= 2)
							$conRet->postalLocAddrStreet2 = $query->item(1)->nodeValue;
						if ($noAddr >= 3)
							$conRet->postalLocAddrStreet3 = $query->item(2)->nodeValue;
						$conRet->postalLocAddrCity = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:city');
						$conRet->postalLocAddrSp = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:sp');
						$conRet->postalLocAddrPc = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:pc');
						$conRet->postalLocAddrCc = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:cc');
						break;
					case 'int':
						$conRet->postalIntName = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:name');
						$conRet->postalIntOrg = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:org');
						$noAddr = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:street)' );
						$query = $xpath->query('/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:street/text()');
						if ($noAddr >= 1)
							$conRet->postalIntAddrStreet1 = $query->item(0)->nodeValue;
						if ($noAddr >= 2)
							$conRet->postalIntAddrStreet2 = $query->item(1)->nodeValue;
						if ($noAddr >= 3)
							$conRet->postalIntAddrStreet3 = $query->item(2)->nodeValue;
						$conRet->postalIntAddrCity = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:city');
						$conRet->postalIntAddrSp = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:sp');
						$conRet->postalIntAddrPc = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:pc');
						$conRet->postalIntAddrCc = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:postalInfo['.$i.']/con:addr/con:cc');
						break;
				}
			}
			$conRet->voice = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:voice' );
			$conRet->fax = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:fax' );
			$conRet->email = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:resData/con:infData/con:email' );
//			// IIS Ext
//			$conRet->iisOrgno = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:extension/iis:infData/iis:orgno' );
//			$conRet->iisVatno = $this->eppXmlGetField($xpath, '/epp:epp/epp:response/epp:extension/iis:infData/iis:vatno' );

			return $conRet;
		}
		return NULL;
	}

	//---------------------------------------------------------------------------
	public function conCreate($contactCre) {
		if ($this->debug) fwrite($this->dfh, "*in conCreate()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the create xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'create' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:create' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		$con->appendChild( $doc->createElement( 'contact:id', $contactCre->id ) );
		if ($contactCre->hasLoc()) {
			$postal  = $con->appendChild( $doc->createElement( 'contact:postalInfo') );
			$postal->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
			if (isset($contactCre->postalLocName)) {
				$postal->appendChild( $doc->createElement( 'contact:name', $this->xmlAmpQuote($contactCre->postalLocName) ) );
			}
			if (isset($contactCre->postalLocOrg)) {
				$postal->appendChild( $doc->createElement( 'contact:org', $this->xmlAmpQuote($contactCre->postalLocOrg) ) );
			}
			$addr  = $postal->appendChild( $doc->createElement( 'contact:addr') );
			if (isset($contactCre->postalLocAddrStreet1)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalLocAddrStreet1)));
			}
			if (isset($contactCre->postalLocAddrStreet2)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalLocAddrStreet2)));
			}
			if (isset($contactCre->postalLocAddrStreet3)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalLocAddrStreet3)));
			}
			$addr->appendChild( $doc->createElement( 'contact:city' , $this->xmlAmpQuote($contactCre->postalLocAddrCity)));
			if (isset($contactCre->postalLocAddrSp)) {
				$addr->appendChild( $doc->createElement( 'contact:sp' , $this->xmlAmpQuote($contactCre->postalLocAddrSp)));
			}
			$addr->appendChild( $doc->createElement( 'contact:pc' , $this->xmlAmpQuote($contactCre->postalLocAddrPc)));
			$addr->appendChild( $doc->createElement( 'contact:cc' ,$contactCre->postalLocAddrCc));
		}
		if ($contactCre->hasInt()) {
			$postal  = $con->appendChild( $doc->createElement( 'contact:postalInfo') );
			$postal->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
			if (isset($contactCre->postalIntName)) {
				$postal->appendChild( $doc->createElement( 'contact:name', $this->xmlAmpQuote($contactCre->postalIntName) ) );
			}
			if (isset($contactCre->postalIntOrg)) {
				$postal->appendChild( $doc->createElement( 'contact:org', $this->xmlAmpQuote($contactCre->postalIntOrg) ) );
			}
			$addr  = $postal->appendChild( $doc->createElement( 'contact:addr') );
			if (isset($contactCre->postalIntAddrStreet1)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalIntAddrStreet1)));
			}
			if (isset($contactCre->postalIntAddrStreet2)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalIntAddrStreet2)));
			}
			if (isset($contactCre->postalIntAddrStreet3)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactCre->postalIntAddrStreet3)));
			}
			$addr->appendChild( $doc->createElement( 'contact:city' , $this->xmlAmpQuote($contactCre->postalIntAddrCity)));
			if (isset($contactCre->postalIntAddrSp)) {
				$addr->appendChild( $doc->createElement( 'contact:sp' , $this->xmlAmpQuote($contactCre->postalIntAddrSp)));
			}
			$addr->appendChild( $doc->createElement( 'contact:pc' , $this->xmlAmpQuote($contactCre->postalIntAddrPc)));
			$addr->appendChild( $doc->createElement( 'contact:cc' ,$contactCre->postalIntAddrCc));
		}
		$con->appendChild( $doc->createElement( 'contact:voice', $contactCre->voice ) );
		$con->appendChild( $doc->createElement( 'contact:fax', $contactCre->fax ) );
		$con->appendChild( $doc->createElement( 'contact:email', $contactCre->email ) );
		$auth = $con->appendChild( $doc->createElement( 'contact:authInfo') );
		$auth->appendChild( $doc->createElement( 'contact:pw', $this->xmlAmpQuote($contactCre->auth) ) );
		// Disclosure
		if ($contactCre->hasDisclose()) {
			if ($contactCre->hasDiscloseFalse()) {
				$dis  = $con->appendChild( $doc->createElement( 'contact:disclose') );
				$dis->appendChild( $this->xmlCreateAttribute( $doc, 'flag', '0' ) );
				if ($contactCre->discloseIntName === false) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocName === false) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseIntOrg === false) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocOrg === false) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseIntAddr === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocAddr === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseVoice === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:voice') );
				}
				if ($contactCre->discloseFax === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:fax') );
				}
				if ($contactCre->discloseEmail === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:email') );
				}
			}
			if ($contactCre->hasDiscloseTrue()) {
				$dis  = $con->appendChild( $doc->createElement( 'contact:disclose') );
				$dis->appendChild( $this->xmlCreateAttribute( $doc, 'flag', '1' ) );
				if ($contactCre->discloseIntName == true) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocName == true) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseIntOrg == true) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocOrg == true) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseIntAddr == true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactCre->discloseLocAddr == true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactCre->discloseVoice == true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:voice') );
				}
				if ($contactCre->discloseFax == true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:fax') );
				}
				if ($contactCre->discloseEmail == true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:email') );
				}
			}
		}
		// Extension
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('create', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$create =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$create."\n");
		$answer = $this->epp->request($create);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('create', $contactCre->id, $create, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function conDelete($contactId) {
		if ($this->debug) fwrite($this->dfh, "*in conDelete()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the delete xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'delete' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:delete' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		$con->appendChild( $doc->createElement( 'contact:id', $contactId ) );
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('delete', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$delete =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$delete."\n");
		$answer = $this->epp->request($delete);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('delete', $contactId, $delete, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function conUpdate($contactUpd) {
		if ($this->debug) fwrite($this->dfh, "*in conUpdate()\n");
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Update the create xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Update the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'update' ) );
		$cmd->appendChild( $chk );
		$con  = $chk->appendChild( $doc->createElement( 'contact:update' ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:contact', $this->config['EppConnTest']['EppNsContactUri'] ) );
		$con->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsContactSl'] ) );
		$con->appendChild( $doc->createElement( 'contact:id', $contactUpd->id ) );
		$chg  = $con->appendChild( $doc->createElement( 'contact:chg' ) );
		if ($contactUpd->hasLoc()) {
			$postal  = $chg->appendChild( $doc->createElement( 'contact:postalInfo') );
			$postal->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
			if (isset($contactUpd->postalLocName)) {
				$postal->appendChild( $doc->createElement( 'contact:name', $this->xmlAmpQuote($contactUpd->postalLocName) ) );
			}
			if (isset($contactUpd->postalLocOrg)) {
				$postal->appendChild( $doc->createElement( 'contact:org', $this->xmlAmpQuote($contactUpd->postalLocOrg) ) );
			}
			$addr  = $postal->appendChild( $doc->createElement( 'contact:addr') );
			if (isset($contactUpd->postalLocAddrStreet1)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalLocAddrStreet1)));
			}
			if (isset($contactUpd->postalLocAddrStreet2)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalLocAddrStreet2)));
			}
			if (isset($contactUpd->postalLocAddrStreet3)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalLocAddrStreet3)));
			}
			$addr->appendChild( $doc->createElement( 'contact:city' , $this->xmlAmpQuote($contactUpd->postalLocAddrCity)));
			if (isset($contactUpd->postalLocAddrPc)) {
				$addr->appendChild( $doc->createElement( 'contact:pc' ,$contactUpd->postalLocAddrPc));
			}
			if (isset($contactUpd->postalLocAddrSp)) {
				$addr->appendChild( $doc->createElement( 'contact:sp' , $this->xmlAmpQuote($contactUpd->postalLocAddrSp)));
			}
			$addr->appendChild( $doc->createElement( 'contact:cc' ,$contactUpd->postalLocAddrCc));
		}
		if ($contactUpd->hasInt()) {
			$postal  = $chg->appendChild( $doc->createElement( 'contact:postalInfo') );
			$postal->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
			if (isset($contactUpd->postalIntName)) {
				$postal->appendChild( $doc->createElement( 'contact:name', $this->xmlAmpQuote($contactUpd->postalIntName) ) );
			}
			if (isset($contactUpd->postalIntOrg)) {
				$postal->appendChild( $doc->createElement( 'contact:org', $this->xmlAmpQuote($contactUpd->postalIntOrg) ) );
			}
			$addr  = $postal->appendChild( $doc->createElement( 'contact:addr') );
			if (isset($contactUpd->postalIntAddrStreet1)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalIntAddrStreet1)));
			}
			if (isset($contactUpd->postalIntAddrStreet2)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalIntAddrStreet2)));
			}
			if (isset($contactUpd->postalIntAddrStreet3)) {
				$addr->appendChild( $doc->createElement( 'contact:street' , $this->xmlAmpQuote($contactUpd->postalIntAddrStreet3)));
			}
			$addr->appendChild( $doc->createElement( 'contact:city' , $this->xmlAmpQuote($contactUpd->postalIntAddrCity)));
			if (isset($contactUpd->postalIntAddrPc)) {
				$addr->appendChild( $doc->createElement( 'contact:pc' ,$contactUpd->postalIntAddrPc));
			}
			if (isset($contactUpd->postalIntAddrSp)) {
				$addr->appendChild( $doc->createElement( 'contact:sp' , $this->xmlAmpQuote($contactUpd->postalIntAddrSp)));
			}
			$addr->appendChild( $doc->createElement( 'contact:cc' ,$contactUpd->postalIntAddrCc));
		}
		if (isset($contactUpd->voice)) {
			$chg->appendChild( $doc->createElement( 'contact:voice', $contactUpd->voice ) );
		}
		if (isset($contactUpd->fax)) {
			$chg->appendChild( $doc->createElement( 'contact:fax', $contactUpd->fax ) );
		}
		if (isset($contactUpd->email)) {
			$chg->appendChild( $doc->createElement( 'contact:email', $contactUpd->email ) );
		}
		if (isset($contactUpd->auth)) {
			$auth = $con->appendChild( $doc->createElement( 'contact:authInfo') );
			$auth->appendChild( $doc->createElement( 'contact:pw', $this->xmlAmpQuote($contactUpd->auth) ) );
		}
		// Disclosure
		if ($contactUpd->hasDisclose()) {
			if ($contactUpd->hasDiscloseFalse()) {
				$dis  = $chg->appendChild( $doc->createElement( 'contact:disclose') );
				$dis->appendChild( $this->xmlCreateAttribute( $doc, 'flag', '0' ) );
				if ($contactUpd->discloseIntName === false) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocName === false) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseIntOrg === false) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocOrg === false) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseIntAddr === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocAddr === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseVoice === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:voice') );
				}
				if ($contactUpd->discloseFax === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:fax') );
				}
				if ($contactUpd->discloseEmail === false) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:email') );
				}
			}
			if ($contactUpd->hasDiscloseTrue()) {
				$dis  = $chg->appendChild( $doc->createElement( 'contact:disclose') );
				$dis->appendChild( $this->xmlCreateAttribute( $doc, 'flag', '1' ) );
				if ($contactUpd->discloseIntName === true) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocName === true) {
					$name  = $dis->appendChild( $doc->createElement( 'contact:name') );
					$name->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseIntOrg === true) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocOrg === true) {
					$org  = $dis->appendChild( $doc->createElement( 'contact:org') );
					$org->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseIntAddr === true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'int' ) );
				}
				if ($contactUpd->discloseLocAddr === true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:addr') );
					$addr->appendChild( $this->xmlCreateAttribute( $doc, 'type', 'loc' ) );
				}
				if ($contactUpd->discloseVoice === true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:voice') );
				}
				if ($contactUpd->discloseFax === true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:fax') );
				}
				if ($contactUpd->discloseEmail === true) {
					$addr  = $dis->appendChild( $doc->createElement( 'contact:email') );
				}
			}
		}
		// Extension
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('update', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$update =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$update."\n");
		$answer = $this->epp->request($update);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('update', $contactUpd->id, $update, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function hostCheck($hostName) {
		if ($this->debug) fwrite($this->dfh, "*in hostCheck()\n");
		if (!$this->hostObj) {
			return 0;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$host  = $chk->appendChild( $doc->createElement( 'host:check' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		$host->appendChild( $doc->createElement( 'host:name', $hostName ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', $hostName, $info, $answer);
		if ($this->resCode == 1000) {
			$avail = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:chkData/hos:cd/hos:name/@avail' )->item(0)->nodeValue;
			return $avail;
		}
		return "0";
	}

	//---------------------------------------------------------------------------
	public function hostCheckMulti($hostArray) {
		if ($this->debug) fwrite($this->dfh, "*in hostCheckMulti()\n");
		if (!$this->hostObj) {
			return 0;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return "0";
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'check' ) );
		$cmd->appendChild( $chk );
		$host  = $chk->appendChild( $doc->createElement( 'host:check' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		for ($i=0; $i<count($hostArray); $i++) {
			$host->appendChild( $doc->createElement( 'host:name', $hostArray[$i] ) );
		}
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('check', implode(", ", $hostArray), $info, $answer);
		$avail = array();
		if ($this->resCode == 1000) {
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/hos:chkData/hos:cd)' );
			for ($i=1; $i<=$no; $i++) {
				$name = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:chkData/hos:cd['.$i.']/hos:name/text()' )->item(0)->nodeValue;
				$avail[$name] = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:chkData/hos:cd['.$i.'.]/hos:name/@avail' )->item(0)->nodeValue;
			}
		}
		return $avail;
	}

	//---------------------------------------------------------------------------
	public function hostInfo($hostName) {
		if ($this->debug) fwrite($this->dfh, "*in hostInfo()\n");
		if (!$this->hostObj) {
			return NULL;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return NULL;
			}
		}
		//- Create the info xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'info' ) );
		$cmd->appendChild( $chk );
		$host  = $chk->appendChild( $doc->createElement( 'host:info' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		$host->appendChild( $doc->createElement( 'host:name', $hostName ) );
		$this->eppCreateCltrid($cmd, $doc);
		$info =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$info."\n");
		$answer = $this->epp->request($info);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('info', $hostName, $info, $answer);
		$hostRet = new ireg_host;
		if ($this->resCode == 1000) {
			$hostRet->name = $hostName;
			$hostRet->roid = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:roid/text()' )->item(0)->nodeValue;
			//$hostRet->status = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:status/@s' )->item(0)->nodeValue;
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/hos:infData/hos:status/@s)' );
			$first=true;
			for ($i=0; $i<$no; $i++) {
				$status = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:status/@s' )->item($i)->nodeValue;
				if ($first) {
					$hostRet->status = $status;
					$first = false;
				} else {
					$hostRet->status .= ", ".$status;
				}
			}
			$hostRet->clID = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:clID/text()' )->item(0)->nodeValue;
			$hostRet->crID = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:crID/text()' )->item(0)->nodeValue;
			$hostRet->crDate = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:crDate/text()' )->item(0)->nodeValue;
			$upd=$xpath->evaluate( 'name(/epp:epp/epp:response/epp:resData/hos:infData/hos:upID)' );
			if (!empty($upd)) {
				$hostRet->upID = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:upID/text()' )->item(0)->nodeValue;
				$hostRet->upDate = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:upDate/text()' )->item(0)->nodeValue;
			}
			$no = $xpath->evaluate( 'count(/epp:epp/epp:response/epp:resData/hos:infData/hos:addr)' );
			for ($i=1; $i<=$no; $i++) {
				$addr = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:addr['.$i.']/text()' )->item(0)->nodeValue;
				$typ = $xpath->query( '/epp:epp/epp:response/epp:resData/hos:infData/hos:addr['.$i.']/@ip' )->item(0)->nodeValue;
				$hostRet->insIp($typ, $addr);
			}
			return $hostRet;
		}
		return NULL;
	}

	//---------------------------------------------------------------------------
	public function hostCreate($hostCre) {
		if ($this->debug) fwrite($this->dfh, "*in hostCreate()\n");
		if (!$this->hostObj) {
			return false;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the create xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'create' ) );
		$cmd->appendChild( $chk );
		$host  = $chk->appendChild( $doc->createElement( 'host:create' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		$host->appendChild( $doc->createElement( 'host:name', $hostCre->name ) );
		for ($i=0; $i<count($hostCre->ipv4); $i++) {
			$addr  = $host->appendChild( $doc->createElement( 'host:addr', $hostCre->ipv4[$i] ) );
			$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
		}
		for ($i=0; $i<count($hostCre->ipv6); $i++) {
			$addr  = $host->appendChild( $doc->createElement( 'host:addr', $hostCre->ipv6[$i] ) );
			$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
		}
		// Extensions
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('create', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$create =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$create."\n");
		$answer = $this->epp->request($create);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('create', $hostCre->name, $create, $answer);
		$hostRet = new ireg_host;
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function hostDelete($hostName) {
		if ($this->debug) fwrite($this->dfh, "*in hostDetele()\n");
		if (!$this->hostObj) {
			return false;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the delete xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'delete' ) );
		$cmd->appendChild( $chk );
		$host  = $chk->appendChild( $doc->createElement( 'host:delete' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		$host->appendChild( $doc->createElement( 'host:name', $hostName ) );
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('delete', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$delete =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$delete."\n");
		$answer = $this->epp->request($delete);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('delete', $hostName, $delete, $answer);
		$hostRet = new ireg_host;
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	public function hostUpdate($hostUpd) {
		if ($this->debug) fwrite($this->dfh, "in hostUpdate\n");
		if (!$this->hostObj) {
			return false;
		}
		if ( ! $this->connected ) {
			$this->connect();
			if ( ! $this->connected ) {
				return false;
			}
		}
		//- Create the update xml -----------------------------------------------------
		$doc = $this->eppCreateDom();
		$root = $this->eppCreateRoot($doc);
		// Create the command
		$cmd  = $root->appendChild( $doc->createElement( 'command' ) );
		$chk  = $cmd->appendChild( $doc->createElement( 'update' ) );
		$cmd->appendChild( $chk );
		$host = $chk->appendChild( $doc->createElement( 'host:update' ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:host', $this->config['EppConnTest']['EppNsHostUri'] ) );
		$host->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $this->config['EppConnTest']['EppNsHostSl'] ) );
		$host->appendChild( $doc->createElement( 'host:name', $hostUpd->name ) );
		if ($hostUpd->hasAdd()) {
			$add = $host->appendChild( $doc->createElement( 'host:add' ) );
			$adrs = $hostUpd->getAdd('v4');
			for ($i=0; $i<count($adrs); $i++) {
				$addr  = $add->appendChild( $doc->createElement( 'host:addr', $adrs[$i] ) );
				$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
			}
			$adrs = $hostUpd->getAdd('v6');
			for ($i=0; $i<count($adrs); $i++) {
				$addr  = $add->appendChild( $doc->createElement( 'host:addr', $adrs[$i] ) );
				$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
			}
		}
		if ($hostUpd->hasRem()) {
			$rem = $host->appendChild( $doc->createElement( 'host:rem' ) );
			$adrs = $hostUpd->getRem('v4');
			for ($i=0; $i<count($adrs); $i++) {
				$addr  = $rem->appendChild( $doc->createElement( 'host:addr', $adrs[$i] ) );
				$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v4' ) );
			}
			$adrs = $hostUpd->getRem('v6');
			for ($i=0; $i<count($adrs); $i++) {
				$addr  = $rem->appendChild( $doc->createElement( 'host:addr', $adrs[$i] ) );
				$addr->appendChild( $this->xmlCreateAttribute( $doc, 'ip', 'v6' ) );
			}
		}
		// Ext
		$hasExt = false;
		$ext = $doc->createElement( 'extension');
		// Add extensions values
		$addext = $this->eppAddExtension('update', $ext, $doc);
		if ($addext) {
			$hasExt = true;
		}
		if ($hasExt) {
			$cmd->appendChild( $ext );
		}
		// Add transaction id
		$this->eppCreateCltrid($cmd, $doc);
		$update =  $doc->saveXML();
		if ($this->debug) fwrite($this->dfh,"------------\nXML: ".$update."\n");
		$answer = $this->epp->request($update);
		if ($this->debug) fwrite($this->dfh,"------------\nAnswer: ".$answer."\n");
		$xpath = $this->parseResult($answer);
		$this->epp_logcallback('update', $hostUpd->name, $update, $answer);
		if (($this->resCode == 1000) || ($this->resCode == 1001)) {
			return true;
		}
		return false;
	}

	//---------------------------------------------------------------------------
	private function xmlAmpQuote( $str )
	{
		return preg_replace("/&(?![A-Za-z]{0,4}\w{2,3};|#[0-9]{2,5};)/m", "&amp;", $str);
	}

	//---------------------------------------------------------------------------
	private function xmlCreateAttribute( $doc, $attrName, $attrValue )
	{
		$attr = $doc->createAttribute( $attrName );
		$attr->nodeValue = $attrValue;
		return $attr;
	}

	//---------------------------------------------------------------------------
	private function eppResultParse( $xml )
	{
		$node = new DOMDocument;
		if ( @$node->loadXML( $xml ) == false ) {
			print "Parse error\n";
			exit(1);
		}
		$xpath = new DOMXPath( $node );
		$xpath->registerNamespace( 'epp', XMLNS_EPP );
		$xpath->registerNamespace( 'con', $this->config['EppConnTest']['EppNsContactUri'] );
		$xpath->registerNamespace( 'dom', $this->config['EppConnTest']['EppNsDomainUri'] );
		if ($this->hostObj) {
			$xpath->registerNamespace( 'hos', $this->config['EppConnTest']['EppNsHostUri'] );
		}
		//$xpath->registerNamespace( 'iis', XSCHEMA_EXTIIS );
		//$xpath->registerNamespace( 'sec', XSCHEMA_EXTDNSSEC );
		return $xpath;
	}

	//---------------------------------------------------------------------------
	private function eppCreateDom()
	{
		// Create DOMDoc
		$doc = new DOMDocument("1.0", "UTF-8");
		// Remove this to get compact putput
		$doc->formatOutput = true;
		$doc->standalone = false;
		return ($doc);
	}

	//---------------------------------------------------------------------------
	private function eppCreateRoot($doc)
	{
		// Build the root
		$root  = $doc->appendChild( $doc->createElement( 'epp' ) );
		$root->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns', XMLNS_EPP ) );
		$root->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:xsi', XMLNS_XSCHEMA ) );
		$root->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', XSCHEMA_EPP ) );
		$doc->appendChild( $root );

		return ($root);
	}

	//---------------------------------------------------------------------------
	private function eppCreateClTrid( $cmd, $doc )
	{
		$ms = microtime();
		$ms = $ms - intval($ms);
		$ms = intval($ms * 10000);
		$clientTran = gmstrftime('%dT%H%M%SZ') . "-" . $ms;
		$cmd->appendChild( $doc->createElement( 'clTRID', $clientTran ) );
		$this->clTRID = $clientTran;
	}

	//---------------------------------------------------------------------------
	private function eppAddExtension($type, $ext, $doc)
	{
		$ret = false;
                $phaseConfig = $this->config[$this->tstPhase];
		for ($i = 1; $i <= 10; $i++) {
			$noa = sprintf("%02u",$i);
                        $keyPrefix = $this->tstPhase.$this->subPhase.'Ext'.$noa;
			if (isset($phaseConfig[$keyPrefix.'ExtName'])) {
				$eType = $phaseConfig[$keyPrefix.'ExtName'];
			} else {
				$eType = $type;
			}
			if (isset($phaseConfig[$keyPrefix.'Uri'])) {
				$ret = true;
				$uri=$ext->appendChild( $doc->createElement( 'ex'.$noa.':'.$eType) );
				$uri->appendChild( $this->xmlCreateAttribute( $doc, 'xmlns:ex'.$noa, $phaseConfig[$keyPrefix.'Uri']));
				$uri->appendChild( $this->xmlCreateAttribute( $doc, 'xsi:schemaLocation', $phaseConfig[$keyPrefix.'Sl']));
			}
			if (isset($phaseConfig[$keyPrefix.'ExtValue'])) {
				$uri->appendChild( $doc->createTextNode($phaseConfig[$keyPrefix.'ExtValue']));
			}
			for ($j = 1; $j <= 10; $j++) {
				$nob = sprintf("%02u",$j);
				if (isset($phaseConfig[$keyPrefix.'Field'.$nob])) {
					$field = $uri->appendChild( $doc->createElement('ex'.$noa.':'.$phaseConfig[$keyPrefix.'Field'.$nob],$phaseConfig[$keyPrefix.'Value'.$nob]));
					for ($k = 1; $k <= 10; $k++) {
						$nok = sprintf("%02u",$k);
						if (isset($phaseConfig[$keyPrefix.'Field'.$nob.'Sub'.$nok.'Field'])) {
							$field->appendChild( $doc->createElement('ex'.$noa.':'.$phaseConfig[$keyPrefix.'Field'.$nob.'Sub'.$nok.'Field'],$phaseConfig[$keyPrefix.'Field'.$nob.'Sub'.$nok.'Value']));
						}
					}
				}
			}
		}
		return $ret;
	}


	//---------------------------------------------------------------------------
	private function eppCreateLogin( $root, $doc, $user, $pwd )
	{
		$cmd   = $root->appendChild( $doc->createElement( 'command' ) );
		$login = $cmd->appendChild( $doc->createElement( 'login' ) );
		$login->appendChild( $doc->createElement( 'clID', $user ) );
		//$login->appendChild( $doc->createElement( 'pw', $pwd ) );
		$pwdnode = $login->appendChild( $doc->createElement( 'pw' ) );
		$pwdnode->appendChild( $doc->createCDATASection( $pwd ) );
		$opts = $login->appendChild( $doc->createElement( 'options' ) );
		$opts->appendChild( $doc->createElement( 'version', '1.0' ) );
		$opts->appendChild( $doc->createElement( 'lang', 'en' ) );
		$svcs = $login->appendChild( $doc->createElement( 'svcs' ) );
		$svcs->appendChild( $doc->createElement( 'objURI', $this->config['EppConnTest']['EppNsDomainUri'] ) );
		$svcs->appendChild( $doc->createElement( 'objURI', $this->config['EppConnTest']['EppNsContactUri'] ) );
		if ($this->hostObj) {
			$svcs->appendChild( $doc->createElement( 'objURI', $this->config['EppConnTest']['EppNsHostUri'] ) );
		}
		$svcx = $svcs->appendChild( $doc->createElement( 'svcExtension' ) );
		$svcx->appendChild( $doc->createElement( 'extURI', $this->config['EppConnTest']['EppExtSecDnsUri'] ) );
		for ($i = 1; $i <= 10; $i++) {
			if (isset($this->config['EppConnTest']['EppExtUri-'.$i])) {
				$svcx->appendChild( $doc->createElement( 'extURI', $this->config['EppConnTest']['EppExtUri-'.$i] ) );
			}
		}
		return ($cmd);
	}

	//---------------------------------------------------------------------------
	private function eppXmlGetField($xpath, $xf)
	{
		$ret = NULL;
		if (($field = strrchr($xf, '/')) == false) {
			die("Illigal xpath string\n");
		}
		$field = substr($field, 1);
		$eval='name('.$xf.')';
		$fname=$xpath->evaluate($eval);
		if (!empty($fname)) {
			$query=$xf.'/text()';
			$ret = $xpath->query($query)->item(0)->nodeValue;
		}
		return $ret;
	}

	//---------------------------------------------------------------------------
	private function epp_logcallback($command, $object, $sent, $answer)
	{
		if ($this->debug) fwrite($this->dfh, "in epp_logcallback\n");
		if ($this->log_callback != NULL) {
			$log = new ireg_epp_log();
			$log->command = $command;
			$log->object = $object;
			$log->svTrid = $this->svTRID;
			$log->clTrid = $this->clTRID;
			$log->status = $this->resCode;
			$log->connected = $this->connected;
			$log->sentXml = $sent;
			$log->receivedXml = $answer;
			// Call the callback
			call_user_func($this->log_callback, $log);
		}
	}

	//---------------------------------------------------------------------------
	private function epp_loglogincallback($command, $sent, $answer)
	{
		if ($this->debug) fwrite($this->dfh, "in epp_logcallback\n");
		if ($this->log_logincallback != NULL) {
			$log = new ireg_epp_log();
			$log->command = $command;
			$log->svTrid = $this->svTRID;
			$log->clTrid = $this->clTRID;
			$log->status = $this->resCode;
			$log->connected = $this->connected;
			$log->sentXml = $sent;
			$log->receivedXml = $answer;
			// Call the callback
			call_user_func($this->log_logincallback, $log);
		}
	}
}
?>
