<?php

namespace Capitaladot\Net;

/* vim: set expandtab softtabstop=4 tabstop=4 shiftwidth=4: */

/**
 * Net_Telnet provides an implementation of the TELNET protocol.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright 2012 Jesse Norell <jesse@kci.net>
 * Copyright 2012 Kentec Communications, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @category Networking
 * @package Net_Telnet
 * @version 0.1 alpha
 * @author Jesse Norell <jesse@kci.net>
 * @copyright 2012 Jesse Norell <jesse@kci.net>
 * @copyright 2012 Kentec Communications, Inc.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License
 * @link https://github.com/jnorell/Net_Telnet
 *      
 * @example telnet.php A basic line-mode telnet client using Net_Telnet
 */

/**
 * Net_Telnet provides an implementation of the TELNET protocol.
 *
 * This has been used for short-running scripts, eg. login and reboot
 * a device, I don't know how it would fair in handling long-running
 * connections (eg. no signal handling, keepalives, etc.).
 *
 * @category Networking
 * @package Telnet
 * @version 0.1 alpha
 * @author Jesse Norell <jesse@kci.net>
 * @copyright 2012 Jesse Norell <jesse@kci.net>
 * @copyright 2012 Kentec Communications, Inc.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License
 * @link https://github.com/jnorell/Net_Telnet
 */
class Net_Telnet {
	
	/**
	 * Remote host to connect to
	 */
	protected $host;
	
	/**
	 * TCP Port to connect to
	 */
	protected $port = 23;
	
	/**
	 * Connect and read timeout
	 */
	protected $timeout = 6;
	
	/**
	 * Command interpreter prompt
	 */
	protected $prompt = null;
	
	/**
	 * Format of debug messages ('txt' or 'html')
	 */
	protected $debugfmt = 'txt';
	
	/**
	 * Prompt for page full of output
	 */
	protected $page_prompt = ' --More-- ';
	
	/**
	 * String to send to continue when at $page_prompt
	 */
	protected $page_continue = ' ';
	
	/**
	 * Settings related to automatic login
	 */
	protected $login = array (
			'login_prompt' => 'Login: ',
			'password_prompt' => 'Password: ',
			'login_success' => 'Login successful',
			'login_fail' => null,
			'login' => null,
			'password' => '' 
	);
	
	/**
	 * Current operating modes
	 */
	protected $mode = array (
			'telnet' => true,		/* send and interpret TELNET commands */
		'telnet_bugs' => true,		/* try to work around bad TELNET implementations */
		'linefeeds' => true,		/* do \r\n <-> \n conversions */
		'tx_binmode' => false,	   /* we transmit in telnet binary mode */
		'rx_binmode' => false,	   /* they are transmitting in binary mode */
		'echomode' => "default",   /* preferred echo mode:  "local", "remote" or "none" */
		'echo_local' => true,		/* we echo to local Net_Telnet user */
		'echo_remote' => false,	   /* remote end is performing echo */
		'echo_net' => false,	   /* we echo back to network */
		'tx_sga' => false,	   /* we are suppresssing go ahead */
		'rx_sga' => false,	   /* they are suppressing go ahead */
		'debug' => false,	   /* print messages in-stream (for debugging NET_Telnet) */
		'pager' => false,	   /* watch for a prompt at a "page" (screen) full */
	);
	
	/**
	 * Network Socket
	 */
	private $s = null;
	
	/**
	 * Incoming data buffer read from the network.
	 */
	private $readbuf = null;
	
	/**
	 * Outgoing data buffer to be written to the network.
	 */
	private $writebuf = null;
	
	/**
	 * Buffer of data read from the network and processed
	 * but still to be read by the user.
	 */
	private $userbuf = null;
	
	/**
	 * Last pattern matched when reading from the network with read_stream()
	 */
	private $lastmatch = null;
	
	/**
	 * TELNET Go Ahead indicator
	 */
	private $GA = true;
	
	/**
	 * Track DO/DONT/WILL/WONT commands sent and received
	 */
	private $telcmds = array (
			'sent' => array (),
			'sent_opts' => array (),
			'received' => array (),
			'received_opts' => array () 
	);
	
	/**
	 * Definitions for the TELNET protocol.
	 */
	const TEL_IAC = 255;
	// interpret as command:
	const TEL_DONT = 254;
	// you are not to use option
	const TEL_DO = 253;
	// please, you use option
	const TEL_WONT = 252;
	// I won't use option
	const TEL_WILL = 251;
	// I will use option
	const TEL_SB = 250;
	// interpret as subnegotiation
	const TEL_GA = 249;
	// you may reverse the line
	const TEL_EL = 248;
	// erase the current line
	const TEL_EC = 247;
	// erase the current character
	const TEL_AYT = 246;
	// are you there
	const TEL_AO = 245;
	// abort output--but let prog finish
	const TEL_IP = 244;
	// interrupt process--permanently
	const TEL_BREAK = 243;
	// break
	const TEL_DM = 242;
	// data mark--for connect. cleaning
	const TEL_NOP = 241;
	// nop
	const TEL_SE = 240;
	// end sub negotiation
	const TEL_EOR = 239;
	// end of record (transparent mode)
	const TEL_ABORT = 238;
	// Abort process
	const TEL_SUSP = 237;
	// Suspend process
	const TEL_EOF = 236;
	// End of file: EOF is already used...
	const TEL_SYNCH = 242;
	// for telfunc calls
	const TEL_xEOF = 236;
	// Name compatible with bsd telnet.h
	
	/**
	 * TELNET command printable names.
	 */
	private $TELCMDS = array (
			self::TEL_IAC => "IAC",
			self::TEL_DONT => "DONT",
			self::TEL_DO => "DO",
			self::TEL_WONT => "WONT",
			self::TEL_WILL => "WILL",
			self::TEL_SB => "SB",
			self::TEL_GA => "GA",
			self::TEL_EL => "EL",
			self::TEL_EC => "EC",
			self::TEL_AYT => "AYT",
			self::TEL_AO => "AO",
			self::TEL_IP => "IP",
			self::TEL_BREAK => "BRK",
			self::TEL_DM => "DMARK",
			self::TEL_NOP => "NOP",
			self::TEL_SE => "SE",
			self::TEL_EOR => "EOR",
			self::TEL_ABORT => "ABORT",
			self::TEL_SUSP => "SUSP",
			self::TEL_EOF => "EOF" 
	);
	
	/**
	 * Tests for valid TELNET command.
	 *
	 * @param integer $x
	 *        	value to test as a TELNET command
	 * @return boolean
	 */
	function TELCMD_OK($x) {
		return array_key_exists ( $x, $this->TELCMDS );
	}
	
	/**
	 * TELNET options
	 */
	const TELOPT_BINARY = 0;
	
	/* 8-bit data path */
	const TELOPT_ECHO = 1;
	
	/* echo */
	const TELOPT_RCP = 2;
	
	/* prepare to reconnect */
	const TELOPT_SGA = 3;
	
	/* suppress go ahead */
	const TELOPT_NAMS = 4;
	
	/* approximate message size */
	const TELOPT_STATUS = 5;
	
	/* give status */
	const TELOPT_TM = 6;
	
	/* timing mark */
	const TELOPT_RCTE = 7;
	
	/* remote controlled transmission and echo */
	const TELOPT_NAOL = 8;
	
	/* negotiate about output line width */
	const TELOPT_NAOP = 9;
	
	/* negotiate about output page size */
	const TELOPT_NAOCRD = 10;
	
	/* negotiate about CR disposition */
	const TELOPT_NAOHTS = 11;
	
	/* negotiate about horizontal tabstops */
	const TELOPT_NAOHTD = 12;
	
	/* negotiate about horizontal tab disposition */
	const TELOPT_NAOFFD = 13;
	
	/* negotiate about formfeed disposition */
	const TELOPT_NAOVTS = 14;
	
	/* negotiate about vertical tab stops */
	const TELOPT_NAOVTD = 15;
	
	/* negotiate about vertical tab disposition */
	const TELOPT_NAOLFD = 16;
	
	/* negotiate about output LF disposition */
	const TELOPT_XASCII = 17;
	
	/* extended ascii character set */
	const TELOPT_LOGOUT = 18;
	
	/* force logout */
	const TELOPT_BM = 19;
	
	/* byte macro */
	const TELOPT_DET = 20;
	
	/* data entry terminal */
	const TELOPT_SUPDUP = 21;
	
	/* supdup protocol */
	const TELOPT_SUPDUPOUTPUT = 22;
	
	/* supdup output */
	const TELOPT_SNDLOC = 23;
	
	/* send location */
	const TELOPT_TTYPE = 24;
	
	/* terminal type */
	const TELOPT_EOR = 25;
	
	/* end or record */
	const TELOPT_TUID = 26;
	
	/* TACACS user identification */
	const TELOPT_OUTMRK = 27;
	
	/* output marking */
	const TELOPT_TTYLOC = 28;
	
	/* terminal location number */
	const TELOPT_3270REGIME = 29;
	
	/* 3270 regime */
	const TELOPT_X3PAD = 30;
	
	/* X.3 PAD */
	const TELOPT_NAWS = 31;
	
	/* window size */
	const TELOPT_TSPEED = 32;
	
	/* terminal speed */
	const TELOPT_LFLOW = 33;
	
	/* remote flow control */
	const TELOPT_LINEMODE = 34;
	
	/* Linemode option */
	const TELOPT_XDISPLOC = 35;
	
	/* X Display Location */
	const TELOPT_OLD_ENVIRON = 36;
	
	/* Old - Environment variables */
	const TELOPT_AUTHENTICATION = 37;
	
	/* Authenticate */
	const TELOPT_ENCRYPT = 38;
	
	/* Encryption option */
	const TELOPT_NEW_ENVIRON = 39;
	
	/* New - Environment variables */
	const TELOPT_EXOPL = 255;
	
	/* extended-options-list */
	
	/**
	 * TELNET option printable names.
	 */
	private $TELOPTS = array (
			self::TELOPT_BINARY => "BINARY",
			self::TELOPT_ECHO => "ECHO",
			self::TELOPT_RCP => "RCP",
			self::TELOPT_SGA => "SUPPRESS GO AHEAD",
			self::TELOPT_NAMS => "NAME",
			self::TELOPT_STATUS => "STATUS",
			self::TELOPT_TM => "TIMING MARK",
			self::TELOPT_RCTE => "RCTE",
			self::TELOPT_NAOL => "NAOL",
			self::TELOPT_NAOP => "NAOP",
			self::TELOPT_NAOCRD => "NAOCRD",
			self::TELOPT_NAOHTS => "NAOHTS",
			self::TELOPT_NAOHTD => "NAOHTD",
			self::TELOPT_NAOFFD => "NAOFFD",
			self::TELOPT_NAOVTS => "NAOVTS",
			self::TELOPT_NAOVTD => "NAOVTD",
			self::TELOPT_NAOLFD => "NAOLFD",
			self::TELOPT_XASCII => "EXTEND ASCII",
			self::TELOPT_LOGOUT => "LOGOUT",
			self::TELOPT_BM => "BYTE MACRO",
			self::TELOPT_DET => "DATA ENTRY TERMINAL",
			self::TELOPT_SUPDUP => "SUPDUP",
			self::TELOPT_SUPDUPOUTPUT => "SUPDUP OUTPUT",
			self::TELOPT_SNDLOC => "SEND LOCATION",
			self::TELOPT_TTYPE => "TERMINAL TYPE",
			self::TELOPT_EOR => "END OF RECORD",
			self::TELOPT_TUID => "TACACS UID",
			self::TELOPT_OUTMRK => "OUTPUT MARKING",
			self::TELOPT_TTYLOC => "TTYLOC",
			self::TELOPT_3270REGIME => "3270 REGIME",
			self::TELOPT_X3PAD => "X.3 PAD",
			self::TELOPT_NAWS => "NAWS",
			self::TELOPT_TSPEED => "TSPEED",
			self::TELOPT_LFLOW => "LFLOW",
			self::TELOPT_LINEMODE => "LINEMODE",
			self::TELOPT_XDISPLOC => "XDISPLOC",
			self::TELOPT_OLD_ENVIRON => "OLD-ENVIRON",
			self::TELOPT_AUTHENTICATION => "AUTHENTICATION",
			self::TELOPT_ENCRYPT => "ENCRYPT",
			self::TELOPT_NEW_ENVIRON => "NEW-ENVIRON",
			self::TELOPT_EXOPL => "EXTENDED OPTIONS LIST" 
	);
	
	/**
	 * Tests for valid TELNET option
	 *
	 * @param integer $x
	 *        	value to test as a TELNET option
	 * @return boolean
	 */
	function TELOPT_OK($x) {
		return array_key_exists ( $x, $this->TELOPTS );
	}
	
	/**
	 * sub-option qualifiers
	 */
	const TELQUAL_IS = 0;
	
	/* option is... */
	const TELQUAL_SEND = 1;
	
	/* send option */
	const TELQUAL_INFO = 2;
	
	/* ENVIRON: informational version of IS */
	const TELQUAL_REPLY = 2;
	
	/* AUTHENTICATION: client version of IS */
	const TELQUAL_NAME = 3;
	
	/* AUTHENTICATION: client version of IS */
	const LFLOW_OFF = 0;
	
	/* Disable remote flow control */
	const LFLOW_ON = 1;
	
	/* Enable remote flow control */
	const LFLOW_RESTART_ANY = 2;
	
	/* Restart output on any char */
	const LFLOW_RESTART_XON = 3;
	
	/* Restart output only on XON */
	
	/**
	 * Prints debug messages if debug mode is enbled
	 *
	 * @todo add ability to provide file handle and/or alternate function handle
	 *      
	 * @param string $str
	 *        	message sent to debug facility
	 */
	function debug($str) {
		if ($this->mode ['debug'])
			switch ($this->debugfmt) {
				case 'html' :
					echo '<span class="telnet_debug_msg">' . htmlspecialchars ( $str ) . '</span>';
					break;
				case 'txt' :
				default :
					echo (substr ( $str, 0 - 1 ) !== "\n") ? "{$str}\n" : "{$str}";
					break;
			}
	}
	
	/**
	 * class constructor, used to initialize quite a few variables
	 *
	 * @param array $opts
	 *        	array of optional arguments.
	 *        	The keys are:
	 *        	
	 *        	'host' : Host to connect to.
	 *        	
	 *        	'port' : TCP port to connect to; defaults to 23.
	 *        	
	 * @todo : finish preceding list of options
	 */
	function __construct($opts = null) {
		$auto_connect = false;
		
		if (is_array ( $opts ) && array_key_exists ( 'debug', $opts ))
			$this->mode ['debug'] = ($opts ['debug']) ? true : false;
		
		$this->debug ( "debug set " . ($this->mode ['debug'] ? "on" : "off") );
		
		if (($val = intval ( ini_get ( 'default_socket_timeout' ) )) > 0) {
			$this->timeout = $val;
			$this->debug ( "timeout set to " . $this->timeout );
		}
		
		if (is_string ( $opts )) {
			$this->host = $opts;
			$this->debug ( "host set to " . $this->host );
		} else if (is_array ( $opts )) {
			if (array_key_exists ( 'debugfmt', $opts )) {
				$this->debugfmt = $opts ['debugfmt'];
				$this->debug ( "prompt " . (strlen ( $this->debugfmt ) > 0 ? "set to {$this->debugfmt}" : "unset") );
			}
			
			if (array_key_exists ( 'host', $opts )) {
				$this->host = $opts ['host'];
				$this->debug ( "host set to " . $this->host );
			}
			
			if (array_key_exists ( 'port', $opts )) {
				if (is_numeric ( $opts ['port'] ) && $opts ['port'] > 0)
					$this->port = $opts ['port'] % 65535;
				else if ($p = getservbyname ( $opts ['port'], 'tcp' ))
					$this->port = $p;
				else
					throw new \Exception ( "invalid port" );
				
				$this->debug ( "port set to " . $this->port );
			}
			
			if (array_key_exists ( 'timeout', $opts )) {
				if (is_numeric ( $opts ['timeout'] ) && $opts ['timeout'] > 0)
					$this->timeout = $opts ['timeout'];
				else
					throw new \Exception ( "invalid timeout" );
				
				$this->debug ( "timeout set to " . $this->timeout );
			}
			
			if (array_key_exists ( 'telnet', $opts )) {
				$this->mode ['telnet'] = ($opts ['telnet']) ? true : false;
				$this->debug ( "telnet mode set " . ($this->mode ['telnet'] ? "on" : "off") );
			}
			
			if (array_key_exists ( 'telnet_bugs', $opts )) {
				$this->mode ['telnet_bugs'] = ($opts ['telnet_bugs']) ? true : false;
				$this->debug ( "telnet_bugs mode set " . ($this->mode ['telnet_bugs'] ? "on" : "off") );
			}
			
			if (array_key_exists ( 'linefeeds', $opts )) {
				$this->mode ['linefeeds'] = ($opts ['linefeeds']) ? true : false;
				$this->debug ( "linefeeds set " . ($this->mode ['linefeeds'] ? "on" : "off") );
			}
			
			if (array_key_exists ( 'connect', $opts )) {
				$auto_connect = ($opts ['connect']) ? true : false;
				$this->debug ( "auto_connect set " . ($auto_connect ? "on" : "off") );
			}
			
			if (array_key_exists ( 'prompt', $opts )) {
				$this->prompt = $opts ['prompt'];
				$this->debug ( "prompt " . (strlen ( $this->prompt ) > 0 ? "set to {$this->prompt}" : "unset") );
			}
			
			if (array_key_exists ( 'pager', $opts )) {
				$this->mode ['pager'] = ($opts ['pager']) ? true : false;
				$this->debug ( "pager set " . ($this->mode ['pager'] ? "on" : "off") );
			}
		}
		
		if ($auto_connect) {
			$this->connect ();
		}
	}
	
	/**
	 * class deconstructor
	 */
	function __destruct() {
		$this->disconnect ();
	}
	
	/**
	 * Enables/Disables CR NL <-> CR translations
	 *
	 * @param boolean $opt
	 *        	true if linefeed translation should be done
	 * @return s boolean true if linefeed translation will be done
	 */
	function linefeeds($opt = null) {
		if ($opt !== null)
			$this->mode ['linefeeds'] = ($opt) ? true : false;
		return $this->mode ['linefeeds'];
	}
	
	/**
	 * Sets or returns the command line interpreter prompt.
	 *
	 * @param string $p
	 *        	command line interpreter prompt to use
	 * @return s string command line interpreter prompt in use
	 */
	function prompt($p = null) {
		if ($p !== null)
			$this->prompt = $p;
		return $this->prompt;
	}
	
	/**
	 * Sets or returns the pager prompt, ie.
	 * what is printed
	 * at a page full of output (eg. '--More--').
	 *
	 * @param string $p
	 *        	pager prompt to use
	 * @param string $c
	 *        	sent to 'continue' when at a pager prompt
	 * @return s string pager prompt in use
	 */
	function page_prompt($p = null, $c = null) {
		if ($p !== null)
			$this->page_prompt = $p;
		if ($c !== null)
			$this->page_continue = $c;
		
		if (strlen ( $this->page_prompt ) > 0 && strlen ( $this->page_continue ) > 0)
			$this->mode ['pager'] = true;
		
		return $this->page_prompt;
	}
	
	/**
	 * Attempts to set preferred echo mode.
	 *
	 * Note: Remote echo isn't guaranteed to be supported by the remote host.
	 *
	 * @param string $mode
	 *        	echo mode to use, one of
	 *        	
	 *        	'local' : Echo commands/characters sent back to user of
	 *        	Net_Telnet
	 *        	
	 *        	'remote' : Attempt to enable remote echo from TELNET peer,
	 *        	but falls back to local echo.
	 *        	
	 *        	'none' : Disable local and remote echo
	 *        	
	 * @return s string|boolean current preferred echo mode or false on error.
	 */
	function echomode($mode = null) {
		switch ($mode) {
			case "local" :
				if ($this->mode ['echo_remote']) {
					$this->debug ( "Disabling Remote Echo" );
					$this->send_telcmd ( self::TEL_DONT, self::TELOPT_ECHO );
					$this->mode ['echo_remote'] = false;
				}
				break;
			case "remote" :
				if (! $this->mode ['echo_remote']) {
					$this->debug ( "Requesting Remote Echo" );
					$this->send_telcmd ( self::TEL_DO, self::TELOPT_ECHO );
					
					if (! $this->mode ['echo_local']) {
						$this->debug ( "Enabling Local Echo" );
						$this->mode ['echo_local'] = true;
					}
				}
				break;
			case "none" :
				if ($this->mode ['echo_local']) {
					$this->debug ( "Disabling Local Echo" );
					$this->mode ['echo_local'] = false;
				}
				if ($this->mode ['echo_remote']) {
					$this->debug ( "Disabling Remote Echo" );
					$this->send_telcmd ( self::TEL_DONT, self::TELOPT_ECHO );
					$this->mode ['echo_remote'] = false;
				}
				break;
			default :
				return false;
				break;
		}
		
		$this->mode ['echomode'] = $mode;
		return $this->mode ['echomode'];
	}
	
	/**
	 * Establishes tcp connection with remote host.
	 *
	 * @param string|array $opts
	 *        	can specify host and/or port
	 */
	function connect($opts = null) {
		$this->debug ( "called connect()" );
		
		if (is_string ( $opts )) {
			$this->host = $opts;
			$this->debug ( "host set to " . $this->host );
		} else if (is_array ( $opts )) {
			if (array_key_exists ( 'host', $opts )) {
				$this->host = $opts ['host'];
				$this->debug ( "host set to " . $this->host );
			}
			
			if (array_key_exists ( 'port', $opts )) {
				if (is_numeric ( $opts ['port'] ) && $opts ['port'] > 0)
					$this->port = $opts ['port'] % 65535;
				else if ($p = getservbyname ( $opts ['port'], 'tcp' ))
					$this->port = $p;
				else
					throw new \Exception ( "invalid port" );
				
				$this->debug ( "port set to " . $this->port );
			}
		} else if ($this->host == null)
			throw new \Exception ( "remote host is required" );
		
		if ($this->s !== null)
			if (! $this->disconnect ())
				throw new \Exception ( "connect called with non-null socket and disconnect failed" );
		
		if (ip2long ( $this->host )) {
			$this->debug ( "attempting connection to " . $this->host . ":" . $this->port );
			$this->s = fsockopen ( $this->host, $this->port, $errno, $errstr, $this->timeout );
			if ($this->s)
				$this->debug ( "connected to " . $this->host . ":" . $this->port );
		} else {
			if ($addrs = gethostbynamel ( $this->host )) {
				foreach ( $addrs as $a ) {
					if ($this->s) {
						continue;
					}
					$this->debug ( "attempting connection to " . $a . ":" . $this->port );
					$this->s = fsockopen ( $a, $this->port, $errno, $errstr, $this->timeout );
					if ($this->s)
						$this->debug ( "connected to " . $a . ":" . $this->port );
				}
			} else
				throw new \Exception ( "invalid or unknown hostname: " . $this->host );
		}
		
		if (! $this->s)
			throw new \Exception ( "connection failed:  $errstr ($errno)" );
		else
			stream_set_timeout ( $this->s, $this->timeout );
		
		$this->GA = true;
		
		if ($this->mode ['telnet']) {
			$this->initial_options ();
		}
	}
	
	/**
	 * Closes tcp connection with remote host.
	 * Does not return data in userbuf,
	 * you might need to call get_data() still.
	 */
	function disconnect() {
		if ($this->s !== null) {
			try {
				$this->net_write ();
				$this->read_stream ();
			} catch ( Exception $e ) {
				$this->debug ( "disconnect: {$e}" );
			}
			$this->debug ( "closing network socket" );
			if (($this->s !== null) && (fclose ( $this->s ) === false))
				throw new \Exception ( "error closing socket" );
			$this->s = null;
		}
	}
	
	/**
	 * Tests if TELNET is "online" - ie.
	 * network socket is
	 * set and not at EOF.
	 *
	 * @return s boolean
	 */
	function online() {
		if ($this->s) {
			$info = stream_get_meta_data ( $this->s );
			return ($info ['eof']) ? false : true;
		}
		return false;
	}
	
	/**
	 * Writes raw data to the network.
	 * If no data is provided,
	 * loops through $writebuf until empty.
	 *
	 * @param string $data
	 *        	Data to be written
	 * @return s integer Number of bytes written
	 */
	function net_write($data = null) {
		$written = 0;
		$n = 0;
		
		if ($this->s === null)
			return 0;
		
		if ($data !== null && strlen ( $data ) > 0) {
			$total = strlen ( $data );
			$d = $data;
		} else {
			$total = strlen ( $this->writebuf );
			$d = $this->writebuf;
			$this->writebuf = null;
		}
		while ( $written < $total ) {
			$d = substr ( $d, $n );
			if (($n = fwrite ( $this->s, $d, 4096 )) === false)
				if (feof ( $this->s ))
					break;
				else
					throw new \Exception ( "error writing to socket" );
			$written += $n;
		}
		
		if ($this->s)
			fflush ( $this->s );
		
		return $written;
	}
	
	/**
	 * Sends commands for initial TELNET options
	 */
	function initial_options() {
		if (! $this->mode ['telnet_bugs']) {
			/*
			 * Some implementations only keep a single SGA state,
			 * though rfc858 says it must be suppressed in
			 * both directions independently.
			 */
			$this->send_telcmd ( self::TEL_WILL, self::TELOPT_SGA );
		}
		
		switch ($this->mode ['echomode']) {
			case "local" :
			case "none" :
				break;
			case "remote" :
			default :
				$this->send_telcmd ( self::TEL_DO, self::TELOPT_ECHO );
				$this->send_telcmd ( self::TEL_DO, self::TELOPT_SGA );
				break;
		}
		
		if (! $this->mode ['telnet_bugs']) {
			/*
			 * Working around a buggy telnet that strips the BINARY
			 * (ie. ASCII 0) out of the datastream by not requesting it.
			 */
			$this->send_telcmd ( self::TEL_DO, self::TELOPT_BINARY );
			$this->send_telcmd ( self::TEL_WILL, self::TELOPT_BINARY );
		}
		
		$this->net_write ();
		
		if ($this->s)
			fflush ( $this->s );
	}
	
	/**
	 * Sends a TELNET command
	 *
	 * @param integer $cmd
	 *        	TELNET command number
	 * @param integer $opt
	 *        	TELNET command SubOption number
	 * @param string $data
	 *        	Raw data to send as the SubOption Negotiation
	 */
	function send_telcmd($cmd = self::TEL_NOP, $opt = null, $data = null) {
		if (! $this->TELCMD_OK ( $cmd ))
			throw new \Exception ( "unknown TELNET command: " . ord ( $cmd ) );
		
		switch ($cmd) {
			case self::TEL_WILL :
			case self::TEL_WONT :
			case self::TEL_DO :
			case self::TEL_DONT :
				if (! $this->TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				
				$this->debug ( "> " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] );
				
				$this->telcmds ['sent'] [$opt] [$cmd] = true;
				$this->put_data ( self::TEL_IAC . $cmd . $opt, false, false );
				break;
			case self::TEL_NOP :
				$this->debug ( "> " . $this->TELCMDS [$cmd] );
				$this->put_data ( self::TEL_IAC . $cmd . $opt, false, false );
				break;
			case self::TEL_SB :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->debug ( "> " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] . " " . $data ); // how
				                                                                                           // to
				                                                                                           // print/format
				                                                                                           // this
				                                                                                           // nicely?
				                                                                                           
				// Escape IAC char
				$data = preg_replace ( '/\xff/', "\xff\xff", $data );
				
				$this->telcmds ['sent_opts'] [$opt] [$cmd] = $data;
				$this->put_data ( self::TEL_IAC . self::TEL_SB . $opt . $data . self::TEL_IAC . self::TEL_SE, false, false );
				break;
			case self::TEL_SE :
				throw new \Exception ( "don't send SE, send SB and I'll add the SE" );
				break;
			default :
				throw new \Exception ( "don't know how to handle " . $this->TELCMDS [$cmd] . " command" );
				break;
		}
	}
	
	/**
	 * Handles TELNET commands we receive
	 *
	 * @param integer $cmd
	 *        	TELNET command number
	 * @param integer $opt
	 *        	TELNET command SubOption number
	 * @param string $data
	 *        	Raw data read in SubOption Negotiation
	 */
	function recv_telcmd($cmd = self::TEL_NOP, $opt = null, $data = null) {
		if (! TELCMD_OK ( $cmd ))
			throw new \Exception ( "unknown TELNET command: " . ord ( $cmd ) );
		
		switch ($cmd) {
			case self::TEL_WILL :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->telcmds ['received'] [$opt] [$cmd] = true;
				$this->debug ( "< " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] );
				switch ($opt) {
					case self::TELOPT_BINARY :
						if ($this->mode ['rx_binmode']) {
							continue;
						}
						
						// maybe should default to "DO" but needs testing
						if (array_key_exists ( self::TEL_DO, $this->telcmds ['sent'] [$opt] )) {
							$this->mode ['rx_binmode'] = true;
							$this->debug ( "Enabling Binary Mode on receive" );
						} else
							$this->send_telcmd ( self::TEL_DONT, $opt );
						break;
					case self::TELOPT_ECHO :
						if ($this->mode ['echo_remote']) {
							continue;
						}
						
						switch ($this->mode ['echomode']) {
							case "local" :
							case "none" :
								$this->debug ( "Refusing Remote Echo" );
								$this->send_telcmd ( self::TEL_DONT, $opt );
								break;
							case "remote" :
							default :
								$this->debug ( "Enabling Remote Echo" );
								$this->mode ['echo_local'] = false;
								$this->mode ['echo_remote'] = true;
								
								if ($this->mode ['echo_net']) {
									$this->debug ( "Disabling Local Network Echo" );
									$this->mode ['echo_net'] = false;
									$this->send_telcmd ( self::TEL_WONT, $opt );
								}
								
								if (! array_key_exists ( self::TEL_DO, $this->telcmds ['sent'] [$opt] ))
									$this->send_telcmd ( self::TEL_DO, $opt );
								break;
						}
						
						break;
					case self::TELOPT_SGA :
						if ($this->mode ['rx_sga']) {
							continue;
						}
						
						$this->debug ( "Enabling Suppress Go Ahead (SGA) on Receive" );
						$this->mode ['rx_sga'] = true;
						
						if ($this->mode ['telnet_bugs'] && ! $this->mode ['tx_sga']) {
							$this->debug ( "Enabling Suppress Go Ahead (SGA) on Transmit" );
							$this->mode ['tx_sga'] = true;
							
							if (! array_key_exists ( self::TEL_WILL, $this->telcmds ['sent'] [$opt] ))
								$this->send_telcmd ( self::TEL_WILL, $opt );
						}
						
						if (! array_key_exists ( self::TEL_DO, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_DO, $opt );
						break;
					case self::TELOPT_STATUS :
					case self::TELOPT_TM :
						break;
					case self::TELOPT_EXOPL :
						$this->send_telcmd ( self::TEL_DONT, $opt );
						break;
					default :
						break;
				}
				break;
			case self::TEL_WONT :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->telcmds ['received'] [$opt] [$cmd] = true;
				$this->debug ( "< " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] );
				switch ($opt) {
					case self::TELOPT_BINARY :
						if (! $this->mode ['rx_binmode']) {
							continue;
						}
						
						$this->debug ( "Disabling Binary Mode on receive" );
						$this->mode ['rx_binmode'] = false;
						
						if (! array_key_exists ( self::TEL_DONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_DONT, $opt );
						break;
					case self::TELOPT_ECHO :
						if (! $this->mode ['echo_remote']) {
							continue;
						}
						
						switch ($this->mode ['echomode']) {
							case "remote" :
								$this->debug ( "Remote won't ECHO, performing Local Echo" );
								$this->mode ['echo_local'] = true;
								break;
						}
						
						if (! array_key_exists ( self::TEL_DONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_DONT, $opt );
						break;
					case self::TELOPT_SGA :
						if (! $this->mode ['rx_sga']) {
							continue;
						}
						
						$this->debug ( "Disabling Suppress Go Ahead (SGA) on Receive" );
						$this->mode ['rx_sga'] = false;
						
						if ($this->mode ['echo_remote']) {
							$this->debug ( "Disabling Remote Echo" );
							$this->send_telcmd ( self::TEL_DONT, TELOPT_ECHO );
							$this->mode ['echo_remote'] = false;
							
							if ($this->mode ['echomode'] == "remote") {
								$this->debug ( "Enabling Local Echo" );
								$this->mode ['echo_local'] = true;
							}
						}
						
						if ($this->mode ['telnet_bugs'] && $this->mode ['tx_sga']) {
							$this->debug ( "Disabling Suppress Go Ahead (SGA) on Transmit" . " (workaround for broken TELNETs)" );
							$this->mode ['tx_sga'] = false;
							
							if (! array_key_exists ( self::TEL_WONT, $this->telcmds ['sent'] [$opt] ))
								$this->send_telcmd ( self::TEL_WONT, $opt );
						}
						
						if (! array_key_exists ( self::TEL_DONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_DONT, $opt );
						break;
					case self::TELOPT_STATUS :
					case self::TELOPT_TM :
					case self::TELOPT_EXOPL :
						break;
					default :
						break;
				}
				break;
			case self::TEL_DO :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->telcmds ['received'] [$opt] [$cmd] = true;
				$this->debug ( "< " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] );
				switch ($opt) {
					case self::TELOPT_BINARY :
						if ($this->mode ['rx_binmode']) {
							continue;
						}
						
						// maybe should default to "WILL" but needs testing
						if (array_key_exists ( self::TEL_WILL, $this->telcmds ['sent'] [$opt] )) {
							$this->mode ['tx_binmode'] = true;
							$this->debug ( "Enabling Binary Mode on transmit" );
						} else
							$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					case self::TELOPT_ECHO :
						if ($this->mode ['echo_net']) {
							continue;
						}
						
						if ($this->mode ['echo_remote']) {
							$this->debug ( "Disabling Remote Echo to prevent Echo loop" );
							$this->mode ['echo_remote'] = false;
							$this->send_telcmd ( self::TEL_DONT, $opt );
						}
						
						switch ($this->mode ['echomode']) {
							case "local" :
							case "remote" :
								$this->debug ( "Enabling Local Echo" );
								$this->mode ['echo_local'] = true;
								break;
						}
						
						$this->debug ( "Enabling Local Network Echo" );
						$this->mode ['echo_net'] = true;
						
						if (! array_key_exists ( self::TEL_WILL, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_WILL, $opt );
					
					case self::TELOPT_SGA :
						if ($this->mode ['tx_sga']) {
							continue;
						}
						
						$this->debug ( "Enabling Suppress Go Ahead (SGA) on Transmit" );
						$this->mode ['tx_sga'] = true;
						
						if ($this->mode ['telnet_bugs'] && ! $this->mode ['rx_sga']) {
							$this->debug ( "Enabling Suppress Go Ahead (SGA) on Receive" . " (workaround for broken TELNETs)" );
							$this->mode ['rx_sga'] = true;
							if (! array_key_exists ( self::TEL_DO, $this->telcmds ['sent'] [$opt] ))
								$this->send_telcmd ( self::TEL_DO, $opt );
						}
						
						if (! array_key_exists ( self::TEL_WILL, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_WILL, $opt );
						break;
					case self::TELOPT_STATUS :
						$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					case self::TELOPT_TM :
						$this->send_telcmd ( self::TEL_WILL, $opt );
						break;
					case self::TELOPT_EXOPL :
						$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					default :
						$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
				}
				break;
			case self::TEL_DONT :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->telcmds ['received'] [$opt] [$cmd] = true;
				$this->debug ( "< " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] );
				switch ($opt) {
					case self::TELOPT_BINARY :
						if (! $this->mode ['tx_binmode']) {
							continue;
						}
						
						$this->debug ( "Disabling Binary Mode on transmit" );
						$this->mode ['tx_binmode'] = false;
						
						if (! array_key_exists ( self::TEL_WONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					case self::TELOPT_ECHO :
						if (! $this->mode ['echo_net']) {
							continue;
						}
						
						$this->debug ( "Disabling Local Network Echo" );
						$this->mode ['echo_net'] = false;
						
						if (! array_key_exists ( self::TEL_WONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					case self::TELOPT_SGA :
						if (! $this->mode ['tx_sga']) {
							continue;
						}
						
						$this->debug ( "Disabling Suppress Go Ahead (SGA) on Transmit" );
						$this->mode ['tx_sga'] = false;
						
						if ($this->mode ['telnet_bugs'] && $this->mode ['rx_sga']) {
							$this->debug ( "Disabling Suppress Go Ahead (SGA) on Receive" . " (workaround for broken TELNETs)" );
							$this->mode ['rx_sga'] = false;
							if (! array_key_exists ( self::TEL_DONT, $this->telcmds ['sent'] [$opt] ))
								$this->send_telcmd ( self::TEL_DONT, $opt );
							
							if ($this->mode ['echo_remote']) {
								$this->debug ( "Disabling Remote Echo" );
								$this->send_telcmd ( self::TEL_DONT, TELOPT_ECHO );
								$this->mode ['echo_remote'] = false;
								
								if ($this->mode ['echomode'] == "remote") {
									$this->debug ( "Enabling Local Echo" );
									$this->mode ['echo_local'] = true;
								}
							}
						}
						
						if (! array_key_exists ( self::TEL_WONT, $this->telcmds ['sent'] [$opt] ))
							$this->send_telcmd ( self::TEL_WONT, $opt );
						break;
					case self::TELOPT_STATUS :
					case self::TELOPT_TM :
					case self::TELOPT_EXOPL :
					default :
						break;
				}
				break;
			case self::TEL_SB :
				if (! self::TELOPT_OK ( $opt ))
					throw new \Exception ( "invalid TELNET option: " . ord ( $opt ) );
				$this->debug ( "< " . $this->TELCMDS [$cmd] . " " . $this->TELOPTS [$opt] . ": " . $data );
				$this->debug ( "Ignoring SubOption negotiation (don't know what to do)" );
				$this->telcmds ['received_opts'] [$opt] [$cmd] = $data;
				break;
			default :
				$this->debug ( "< " . $this->TELCMDS [$cmd] );
				break;
		}
	}
	
	/**
	 * Flushes writebuf to the network
	 * and sends Go Ahead (if appropriate)
	 *
	 * @return s boolean indicates if data was written
	 */
	function go_ahead() {
		if ($this->mode ['tx_sga'])
			$this->net_write ();
		else if ($this->GA) {
			$this->net_write ();
			if ($this->mode ['telnet']) {
				$this->net_write ( self::TEL_IAC . self::TEL_GA );
				$this->GA = false;
			}
		} else
			return false;
		
		if ($this->s)
			fflush ( $this->s );
		
		return true;
	}
	
	/**
	 * Sends NVT character BRK
	 *
	 * @return s boolean indicates if data was written
	 */
	function send_break() {
		$this->writebuf .= self::TEL_IAC . self::TEL_BREAK;
		return ($this->net_write () > 0) ? true : false;
	}
	
	/**
	 * Returns contents of (and clears) userbuf.
	 *
	 * Note this does not read any data available from the network,
	 * call read_stream() to do so.
	 *
	 * @param integer $cnt
	 *        	return at most this many chars
	 */
	function get_data($cnt = 0) {
		if ((intval ( $cnt ) == 0) || (intval ( $cnt ) >= strlen ( $this->userbuf ))) {
			$tmpbuf = $this->userbuf;
			$this->userbuf = null;
		} else {
			$tmpbuf = substr ( $this->userbuf, 0, intval ( $cnt ) );
			$this->userbuf = substr ( $this->userbuf, 0 - intval ( $cnt ) );
		}
		
		return $tmpbuf;
	}
	
	/**
	 * Adds a string to writebuf, escaping special chars
	 * (ignores newline handling - see println() for that).
	 *
	 * Note this does not handle Go Ahead, and won't write
	 * data to the network if using local echo; call go_ahead()
	 * at the appropriate place after using put_data() (eg. after
	 * sending a "line" or set of command characters).
	 *
	 * @param string $data
	 *        	String to be written
	 * @param boolean $esc
	 *        	escape special chars in data or not
	 * @param boolean $echo
	 *        	echo chars when in echo_local mode
	 * @return s integer number of bytes written to network
	 */
	function put_data($data, $esc = true, $echo = true) {
		if ($this->mode ['telnet'] && $esc && ! $this->mode ['tx_binmode'])
			$data = preg_replace ( '/[\xf0-\xfe]/', '', $data );
		
		if ($echo && $this->mode ['echo_local'])
			$this->userbuf .= $data;
		
		if ($this->mode ['telnet'] && $esc && $this->mode ['tx_binmode'])
			$data = preg_replace ( '/\xff/', '\xff\xff', $data );
		
		$this->writebuf .= $data;
		
		// If using remote echo, flush the network buffer
		return $this->mode ['echo_remote'] ? $this->net_write () : 0;
	}
	
	/**
	 * Adds a string or array of strings, all newline terminated,
	 * to the network write buffer.
	 *
	 * @param string|array $arg
	 *        	string or array of strings to print
	 */
	function println($arg = '') {
		if (is_string ( $arg ) || is_null ( $arg )) {
			$strings = array (
					$arg 
			);
		} else if (is_array ( $arg )) {
			$strings = $arg;
		} else
			throw new \Exception ( "println called with invalid input" );
		
		foreach ( $strings as $str ) {
			if (substr ( $str, 0 - 1 ) !== "\n")
				$str .= "\n";
			
			if ($this->mode ['linefeeds'])
				$str = preg_replace ( '/([^\r])?\n/', "$1\r\n", $str );
			
			$this->put_data ( $str );
		}
		
		$this->go_ahead ();
	}
	
	/**
	 * Reads network socket till a string is found,
	 * and returns data read.
	 *
	 * @param string $arg
	 *        	string (not regex) to search for
	 * @return s string|boolean data read or false on failure
	 */
	function waitfor($arg = null) {
		if ($arg == null)
			$arg = $this->prompt;
		
		if (! strlen ( $arg ) > 0)
			return (! feof ( $this->s )) ? $this->get_data () : false;
		
		if ($this->s === null)
			return false;
		
		if (feof ( $this->s )) {
			$this->disconnect ();
			return $this->get_data ();
		}
		
		if ($this->read_stream ( $arg ) !== false)
			return $this->get_data ();
		else {
			$this->debug ( "waitfor: read_stream({$arg}) failed" );
			return false;
		}
	}
	
	/**
	 * Writes a command to the network then waits for
	 * the command prompt and returns what was output.
	 *
	 * @param string|array $cmd
	 *        	command or array of command to run
	 * @return s string|boolean data read or false on failure
	 */
	function cmd($arg) {
		if (is_string ( $arg ) || is_null ( $arg ))
			$cmds = array (
					$arg 
			);
		else if (is_array ( $arg ))
			$cmds = $arg;
		else
			throw new \Exception ( "cmd called with invalid input" );
		
		$retval = "";
		$ok = true;
		
		foreach ( $cmds as $cmd ) {
			if (! $ok) {
				$this->debug ( "cmd: aborting command {$cmd} due to previous failure" );
				continue;
			}
			$this->println ( $cmd );
			if (($ret = $this->waitfor ( $this->prompt )) === false) {
				$this->debug ( "cmd: waitfor({$this->prompt}) failed," . " aborting further commands" );
				$ok = false;
			} else
				$retval .= $ret;
		}
		
		if ($ok)
			return $retval;
		else
			return (strlen ( $retval )) ? $retval : false;
	}
	
	/**
	 * Waits for some text pattern on network input,
	 * then sends a response.
	 *
	 * This function borrows from common expect(1) usage,
	 * and is similar to cmd() but alters the script logic.
	 *
	 * @param string|array $arg
	 *        	pattern to wait for, or array of pattern/commands
	 * @param string $arg2
	 *        	command to send if $arg is a string
	 * @return s boolean false if error detected or pattern not found
	 */
	function expect($arg, $arg2 = null) {
		if ((is_string ( $arg ) || is_null ( $arg )) && ($arg2 !== null))
			$args = array (
					$arg => $arg2 
			);
		else if (is_array ( $arg ))
			$args = $arg;
		else
			throw new \Exception ( "expect called with invalid input" );
		
		if (count ( $args ) == 1 && (strlen ( key ( $args ) ) == 0)) {
			$this->debug ( "expect: reading stream, with nothing to watch for" );
			if ($this->read_stream () === false)
				return false;
			return $this->send ( current ( $args ) );
		}
		
		$pats = array ();
		foreach ( $args as $pat => $cmd ) {
			if (strlen ( $pat ) > 0) {
				$this->debug ( "expect: watching for {$pat}" );
				$pats [] = $pat;
			}
		}
		
		if (count ( $pats ) == 0)
			throw new \Exception ( "expect called with invalid input" );
		
		if ($this->read_stream ( $pats ) === false) {
			$this->debug ( "expect: read_stream failed" );
			return false;
		}
		
		if (array_key_exists ( $this->lastmatch, $args )) {
			$this->debug ( "expect: read_stream found {$this->lastmatch}," . " sending {$args[$this->lastmatch]}" );
			return $this->send ( $args [$this->lastmatch] );
		} else
			throw new \Exception ( "expect broke, don't have lastmatch ({$this->lastmatch})" );
	}
	
	/**
	 * Adds a string to writebuf, escaping special chars,
	 * flushes writebuf to the network, then calls go_ahead().
	 *
	 * This is fairly synonymous with put_data() (primarily
	 * for those using expect() who expect a send()), with
	 * the addition of Go Ahead
	 *
	 * @param string $data
	 *        	String to be written
	 * @param boolean $esc
	 *        	escape special chars in data or not
	 * @param boolean $echo
	 *        	echo chars when in echo_local mode
	 * @return s boolean false if error detected
	 */
	function send($data = null, $esc = true, $echo = true) {
		if (is_null ( $data ) || strlen ( $data ) == 0)
			return true;
		
		$ret = true;
		$written = $this->put_data ( $data, $esc, $echo );
		
		// go_ahead() can hang writes waiting for Go Ahead
		// with broken TELNET peers (seems nearly ubiquitous),
		// so we'll write first
		if ($this->mode ['telnet_bugs'])
			$ret = (($written += $this->net_write ()) > 0) ? true : false;
		
		$this->go_ahead ();
		return $ret;
	}
	
	/**
	 * Reads network socket watching for a search string
	 * and/or byte/time limit, while watching for telnet commands.
	 * Call with no parameters to do read all pending data.
	 *
	 * Bug: we could read in larger chunks for efficiency
	 *
	 * @param string $searchfor
	 *        	string (not regex) to search for
	 * @param integer $numbytes
	 *        	read this many bytes
	 * @param integer $timeout
	 *        	timeout for read_stream function
	 *        	
	 * @return integer|boolean number of bytes read or false on error
	 */
	function read_stream($searchfor = null, $numbytes = null, $timeout = null) {
		
		/**
		 * we found the criteria (string or # bytes) we want
		 */
		$found = false;
		
		/**
		 * Buffer of chars to add to $userbuf
		 */
		$buf = '';
		
		/**
		 * Shorter handle for convenience
		 */
		$s = $this->s;
		
		/**
		 * Timestamp when read_stream is called
		 */
		$ts = time ();
		
		/**
		 * We're supposed to read any/all available data
		 * from the network, then return immediately.
		 */
		$drain = false;
		
		/**
		 * Read timeout
		 */
		$t = $this->timeout;
		
		if (! $this->online ()) {
			if ($this->s) {
				$this->net_write ();
				if (fclose ( $this->s ) === false)
					throw new \Exception ( "error closing socket" );
				$this->s = null;
			}
			return false;
		}
		
		if ($searchfor === null && $numbytes === null && $timeout === null) {
			$drain = true;
			stream_set_timeout ( $s, 0, 200000 );
		} else if ($timeout === null) {
			stream_set_timeout ( $s, $this->timeout );
		} else {
			$t = (intval ( $timeout ) > 0) ? intval ( $timeout ) : 0;
			stream_set_timeout ( $s, $t, 200000 );
		}
		
		if (! is_array ( $searchfor ))
			$searchfor = array (
					$searchfor 
			);
		
		$this->lastmatch = null;
		
		while ( ! $found && ! feof ( $this->s ) && (! (intval ( $numbytes ) > 0 && strlen ( $buf ) >= intval ( $numbytes ))) ) {
			if (intval ( $timeout ) > 0) {
				if (($t = ($ts + intval ( $timeout ) - time ())) > 0)
					stream_set_timeout ( $s, $t, 200000 );
				else
					break;
			}
			
			if (isset ( $c )) {
				$last_c = $c;
			}
			
			if (feof ( $s ) || (($c = fgetc ( $s )) === false)) {
				$info = stream_get_meta_data ( $s );
				
				if ($info ['eof'])
					break;
				else if ($info ['timed_out'])
					if ($drain)
						break;
					else
						continue;
				else
					throw new \Exception ( "Error reading from network" );
			}
			
			if ($this->mode ['telnet'] && $c == self::TEL_IAC) { /*
			                                                      * Interpret As
			                                                      * Command
			                                                      */
				if (! feof ( $s ) && ($c = fgetc ( $s )) === false) {
					$info = stream_get_meta_data ( $s );
					
					if ($info ['eof'])
						break;
					else if ($info ['timed_out'])
						if ($drain)
							break;
						else
							continue;
					else if ($this->mode ['telnet_bugs']) {
						$this->debug ( "Error reading TELNET command char from network" );
						break;
					} else
						throw new \Exception ( "Error reading TELNET command char from network" );
				}
				
				switch ($c) {
					case self::TEL_IAC :
						$buf .= $c;
						break;
					case self::TEL_WILL :
					case self::TEL_WONT :
					case self::TEL_DO :
					case self::TEL_DONT :
						if (! feof ( $s ) && ($opt = fgetc ( $s )) === false) {
							$info = stream_get_meta_data ( $s );
							
							if ($info ['eof'])
								break;
							else if ($info ['timed_out'])
								if ($drain)
									break;
								else
									continue;
							else if ($this->mode ['telnet_bugs']) {
								$this->debug ( "Error reading TELNET option " . " char for {$this->TELCMDS[$c]} command" );
								continue;
							} else {
								throw new \Exception ( "Error reading TELNET option " . " char for {$this->TELCMDS[$c]} command" );
							}
						}
						
						$this->recv_telcmd ( $c, $opt );
						
						break;
					case self::TEL_SB :
						$telcmd = $c;
						$subopt = null;
						$data = $c;
						
						while ( ! feof ( $s ) && ($c != self::TEL_SE) && (($c = fgetc ( $s )) !== false) ) {
							if ($subopt === null)
								$subopt = $c;
							
							$data .= $c;
						}
						if ($c === false) {
							$info = stream_get_meta_data ( $s );
							
							if ($info ['eof'])
								break;
							else if ($info ['timed_out'])
								if ($drain)
									break;
								else
									continue;
							else if ($this->mode ['telnet_bugs']) {
								$this->debug ( "Error reading TELNET SubNegotiation command" );
								continue;
							} else
								throw new \Exception ( "Error reading TELNET SubNegotiation command" );
						}
						$this->recv_telcmd ( $telcmd, $subopt, $data );
						break;
					case self::TEL_GA :
						$this->GA = true;
						$this->net_write ();
						break;
					case self::TEL_AO :
						$this->debug ( "Received TELNET Abort Output (AO), clearing read buffers" );
						$this->userbuf = '';
						$buf = '';
						break;
					case self::TEL_IP :
						throw new \Exception ( "Received TELNET Interrupt Process (IP)" );
						break;
					case self::TEL_NOP :
					default :
						if ($this->TELCMD_OK ( $c ))
							$this->recv_telcmd ( $c );
						break;
				}
			} else {
				if ($this->mode ['telnet']) {
					// discard NUL received after a CR
					if ($c == chr ( 0 ) && $last_c == chr ( 13 ))
						continue;
					
					if (ord ( $c ) > 127 && (! $this->mode ['rx_binmode'])) {
						$this->debug ( "discarding non-ASCII char (" . ord ( $c ) . ")" );
						continue;
					}
				}
				
				$buf .= $c;
				
				if (! $drain) {
					foreach ( $searchfor as $pat ) {
						// TODO: add regex support
						if ($pat !== null && strlen ( $pat ) > 0 && (substr ( $buf, 0 - strlen ( $pat ) ) === $pat)) {
							$this->lastmatch = $pat;
							$found = true;
							continue;
						}
					}
				}
				
				if ($this->mode ['pager'] && (strlen ( $this->page_prompt ) > 0) && (substr ( $buf, 0 - strlen ( $this->page_prompt ) ) === $this->page_prompt)) {
					$this->put_data ( $this->page_continue );
					$this->go_ahead ();
					continue;
				}
			}
		}
		
		if ($this->mode ['linefeeds'])
			$buf = preg_replace ( "/\r\n/", "\n", $buf );
		
		$this->userbuf .= $buf;
		
		if ($drain || (count ( $searchfor ) == 0 && (intval ( $numbytes ) == 0) && (intval ( $timeout ) > 0)) || (count ( $searchfor ) == 0 && (intval ( $numbytes ) > 0) && strlen ( $buf ) >= intval ( $numbytes ))) {
			$found = true;
		}
		
		$info = stream_get_meta_data ( $s );
		if ($info ['eof']) {
			$this->net_write ();
			if (fclose ( $this->s ) === false)
				throw new \Exception ( "error closing socket" );
			$this->s = null;
		}
		
		return ($found) ? strlen ( $buf ) : false;
	}
	
	/**
	 * Attempt to perform automatic login.
	 *
	 * @param array $args
	 *        	sets (overwrites) login related info
	 * @param array $arg2
	 *        	optional password if $args is login name
	 *        	
	 * @todo : document $args
	 */
	function login($args = null, $arg2 = null) {
		if (is_string ( $args )) {
			$this->login ['login'] = $args;
			$this->debug ( "login set to " . $args );
			
			if (is_string ( $arg2 ) && strlen ( $arg2 ) > 0) {
				$this->login ['password'] = $arg2;
				$this->debug ( "password set to " . $arg2 );
			}
		} else if (is_array ( $args )) {
			if (array_key_exists ( 'login_prompt', $args )) {
				$this->login ['login_prompt'] = $args ['login_prompt'];
				$this->debug ( "login_prompt set to " . $args ['login_prompt'] );
			}
			
			if (array_key_exists ( 'password_prompt', $args )) {
				$this->login ['password_prompt'] = $args ['password_prompt'];
				$this->debug ( "password_prompt set to " . $args ['password_prompt'] );
			}
			
			if (array_key_exists ( 'login_success', $args )) {
				$this->login ['login_success'] = $args ['login_success'];
				$this->debug ( "login_success set to " . $args ['login_success'] );
			}
			
			if (array_key_exists ( 'login_fail', $args )) {
				$this->login ['login_fail'] = $args ['login_fail'];
				$this->debug ( "login_fail set to " . $args ['login_fail'] );
			}
			
			if (array_key_exists ( 'login', $args )) {
				$this->login ['login'] = $args ['login'];
				$this->debug ( "login set to " . $args ['login'] );
			}
			
			if (array_key_exists ( 'password', $args )) {
				$this->login ['password'] = $args ['password'];
				$this->debug ( "password set to " . $args ['password'] );
			}
			
			if (array_key_exists ( 'prompt', $args )) {
				$this->prompt = $args ['prompt'];
				$this->debug ( "prompt " . (strlen ( $this->prompt ) > 0 ? "set to {$this->prompt}" : "unset") );
			}
		}
		
		if ((! array_key_exists ( 'login_success', $this->login )) || (strlen ( $this->login ['login_success'] ) == 0)) {
			if (strlen ( $this->prompt ) == 0)
				throw new \Exception ( "login usage error:  " . "need to set login_success and/or command prompt" );
			$this->debug ( "login_success defaulting to " . $this->login ['login_prompt'] );
			$this->login ['login_success'] = $this->prompt;
		}
		
		if (! $this->online ())
			$this->connect ();
		
		if ($this->net_write () === false)
			throw new \Exception ( "login: error with network socket" );
		
		if (array_key_exists ( 'login_prompt', $this->login ) && strlen ( $this->login ['login_prompt'] ) > 0) {
			if (! (array_key_exists ( 'login_fail', $this->login ) && strlen ( $this->login ['login_fail'] ) > 0)) {
				$this->login ['login_fail'] = $this->login ['login_prompt'];
				$this->debug ( "login_fail defaulting to " . $this->login ['login_fail'] );
			}
			
			$this->debug ( "login: waiting for login prompt:  " . $this->login ['login_prompt'] );
			
			$l = (array_key_exists ( 'login', $this->login ) ? $this->login ['login'] : '');
			
			if ($this->expect ( $this->login ['login_prompt'], $l . "\r" ) === false)
				throw new \Exception ( "login: failed to find login prompt" );
		}
		
		if (array_key_exists ( 'password_prompt', $this->login ) && strlen ( $this->login ['password_prompt'] ) > 0) {
			if (! (array_key_exists ( 'login_fail', $this->login ) && strlen ( $this->login ['login_fail'] ) > 0)) {
				$this->login ['login_fail'] = $this->login ['password_prompt'];
				$this->debug ( "login_fail defaulting to " . $this->login ['login_fail'] );
			}
			
			$this->debug ( "login: waiting for password prompt:  " . $this->login ['password_prompt'] );
			
			$p = (array_key_exists ( 'password', $this->login ) ? $this->login ['password'] : '');
			
			if ($this->expect ( $this->login ['password_prompt'], $p . "\r" ) === false)
				throw new \Exception ( "login: failed to find password prompt" );
		}
		
		if (array_key_exists ( 'login_fail', $this->login ) && strlen ( $this->login ['login_fail'] ) > 0) {
			$this->debug ( "login: looking for login success or fail prompt" );
			
			$prompts = array (
					$this->login ['login_success'],
					$this->login ['login_fail'] 
			);
		} else {
			$this->debug ( "login: looking for login success prompt" );
			
			$prompts = array (
					$this->login ['login_success'] 
			);
		}
		
		if (($ret = $this->read_stream ( $prompts )) === false)
			throw new \Exception ( "login: failed to complete login" );
		
		if ($this->lastmatch == $this->login ['login_success'])
			$this->debug ( "login: login was successful" );
		else if ($this->lastmatch == $this->login ['login_fail'])
			throw new \Exception ( "login: login failed" );
		
		if ($this->login ['login_success'] != $this->prompt) {
			$this->debug ( "login: waiting for command prompt: {$this->prompt}" );
			
			if (($ret = $this->waitfor ( $this->prompt )) === false)
				throw new \Exception ( "login: error in telnet session," . " didn't find prompt (failed login?)" );
		} else
			$ret = $this->get_data ();
		
		$this->debug ( "login: we appear to be logged in." );
		
		return $ret;
	}
}

?>
