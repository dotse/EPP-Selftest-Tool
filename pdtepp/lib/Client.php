<?php

	/*	EPP Client class for PHP, Copyright 2005 CentralNic Ltd
		This program is free software; you can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation; either version 2 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU General Public License for more details.

		You should have received a copy of the GNU General Public License
		along with this program; if not, write to the Free Software
		Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
	*/

	/**
	* A simple client class for the Extensible Provisioning Protocol (EPP)
	* @package Net_EPP_Client
	* @version 0.0.3
	* @author Gavin Brown <gavin.brown@nospam.centralnic.com>
	* @revision $Id: Client.php,v 1.8 2007/05/25 09:26:49 gavin Exp $
	*/

	require_once('PEAR.php');

	/* $GLOBALS[Net_EPP_Client_Version] = '0.0.3'; */

	/**
	* A simple client class for the Extensible Provisioning Protocol (EPP)
	* @package Net_EPP_Client
	*/
	class Net_EPP_Client {

		/**
		* @var resource the socket resource, once connected
		*/
		var $socket;

		private $local_cert;
		private $local_cert_path;
		private $local_cert_pwd;

		public function __construct() {
			$this->local_cert = false;
			$this->local_cert_path = NULL;
			$this->local_cert_pwd = NULL;
		}

		public function __destruct() {
			//print "In destructor (domain_se)\n";
		}

		public function local_cert($path, $pwd) {
			$this->local_cert = true;
			$this->local_cert_path = $path;
			$this->local_cert_pwd = $pwd;
		}

		static function _fread_nb($socket,$length) {
			$result = '';

			// Loop reading and checking info to see if we hit timeout
			$info = stream_get_meta_data($socket);
			$time_start = microtime(true);

			while (!$info['timed_out'] && !feof($socket)) {
				// Try read remaining data from socket
				$buffer = @fread($socket,$length - strlen($result));
				// If the buffer actually contains something then add it to the result
				if ($buffer !== false) {
					$result .= $buffer;
					// If we hit the length we looking for, break
					if (strlen($result) == $length) {
						break;
					}
				} else {
					// Sleep 0.25s
					usleep(250000);
				}
				// Update metadata
				$info = stream_get_meta_data($socket);
				$time_end = microtime(true);
				if (($time_end - $time_start) > 10000000) {
					return new PEAR_Error(sprintf('Timeout while reading from EPP Server'));
				}
			}

			// Check for timeout
			if ($info['timed_out']) {
				return new PEAR_Error(sprintf('Timeout while reading from EPP Server'));
			}

			return $result;
		}


		static function _fwrite_nb($socket,$buffer,$length) {
			// Loop writing and checking info to see if we hit timeout
			$info = stream_get_meta_data($socket);
			$time_start = microtime(true);

			$pos = 0;
			while (!$info['timed_out'] && !feof($socket)) {
				// Some servers don't like alot of data, so keep it small per chunk
				$wlen = $length - $pos;
				if ($wlen > 1024) { $wlen = 1024; }
				// Try write remaining data from socket
				$written = @fwrite($socket,substr($buffer,$pos),$wlen);
				// If we read something, bump up the position
				if ($written && $written !== false) {
					$pos += $written;
					// If we hit the length we looking for, break
					if ($pos == $length) {
						break;
					}
				} else {
					// Sleep 0.25s
					usleep(250000);
				}
				// Update metadata
				$info = stream_get_meta_data($socket);
				$time_end = microtime(true);
				if (($time_end - $time_start) > 10000000) {
					return new PEAR_Error(sprintf('Timeout while writing to EPP Server'));
				}
			}
			// Check for timeout
			if ($info['timed_out']) {
				return new PEAR_Error(sprintf('Timeout while writing to EPP Server'));
			}

			return $pos;
		}

		/**
		* Establishes a connect to the server
		* This method establishes the connection to the server. If the connection was
		* established, then this method will call getFrame() and return the EPP <greeting>
		* frame which is sent by the server upon connection. If connection fails, then
		* a PEAR_Error object explaining the error will be returned instead.
		* @param string the hostname
		* @param integer the TCP port
		* @param integer the timeout in seconds
		* @param boolean whether to connect using SSL
		* @return PEAR_Error|string a PEAR_Error on failure, or a string containing the server <greeting>
		*/
		function connect($host, $port=700, $timeout=1, $ssl=true, $sniname=NULL) {
			$target = sprintf('%s://[%s]:%d', ($ssl === true ? 'ssl' : 'tcp'), $host, $port);
			$errno='';
			$errstr='';
			if ($this->local_cert) {
				$context = stream_context_create();
				$result = stream_context_set_option($context, 'ssl', 'local_cert', $this->local_cert_path);
				$result = stream_context_set_option($context, 'ssl', 'passphrase', $this->local_cert_pwd);
				if (isset($sniname)) {
				   	$result = stream_context_set_option($context, 'ssl', 'SNI_enabled', True);
					$result = stream_context_set_option($context, 'ssl', 'SNI_server_name', $sniname);
				}
				if (!$this->socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context)) {
					return new PEAR_Error("Error connecting to $target: $errstr (code $errno)");
				} else {
					return $this->getFrame();
				}
			} else {
				if (!$this->socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT)) {
					return new PEAR_Error("Error connecting to $target: $errstr (code $errno)");
				} else {
					return $this->getFrame();
				}
			}

		}

		/**
		* Get an EPP frame from the server.
		* This retrieves a frame from the server. Since the connection is blocking, this
		* method will wait until one becomes available. If the connection has been broken,
		* this method will return a PEAR_Error object, otherwise it will return a string
		* containing the XML from the server
		* @return PEAR_Error|string a PEAR_Error on failure, or a string containing the frame
		*/
		function getFrame() {
			if (@feof($this->socket)) return new PEAR_Error('connection closed by remote server');

			$hdr = $this->_fread_nb($this->socket,4);

			if (empty($hdr) && feof($this->socket)) {
				return new PEAR_Error('connection closed by remote server');

			} elseif (empty($hdr)) {
				return new PEAR_Error('Error reading from server: '.$php_errormsg);

			} else {
				$unpacked = unpack('N', $hdr);
				$length = $unpacked[1];
				if ($length < 5) {
					return new PEAR_Error(sprintf('Got a bad frame header length of %d bytes from server', $length));

				} else {
					return $this->_fread_nb($this->socket, ($length - 4));

				}
			}
		}

		/**
		* Send an XML frame to the server.
		* This method sends an EPP frame to the server.
		* @param string the XML data to send
		* @return boolean the result of the fwrite() operation
		*/
		function sendFrame($xml) {
			$length = strlen($xml) + 4;
			$res = $this->_fwrite_nb($this->socket, pack('N',$length) . $xml,$length);
			// Check our write matches
			if ($length != $res) {
				return new PEAR_Error(sprintf('Short write when sending XML'));
			}
		}

		/**
		* a wrapper around sendFrame() and getFrame()
		* @param string $xml the frame to send to the server
		* @return PEAR_Error|string the frame returned by the server, or an error object
		*/
		function request($xml) {
			$this->sendFrame($xml);
			return $this->getFrame();
		}

		/**
		* Close the connection.
		* This method closes the connection to the server. Note that the
		* EPP specification indicates that clients should send a <logout>
		* command before ending the session.
		* @return boolean the result of the fclose() operation
		*/
		function disconnect() {
			return @fclose($this->socket);
		}

	}

?>
