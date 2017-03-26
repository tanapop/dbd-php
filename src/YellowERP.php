<?php
/*************************************************************************************
 *   MIT License                                                                     *
 *                                                                                   *
 *   Copyright (C) 2009-2017 by Nurlan Mukhanov <nurike@gmail.com>                   *
 *                                                                                   *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy    *
 *   of this software and associated documentation files (the "Software"), to deal   *
 *   in the Software without restriction, including without limitation the rights    *
 *   to use, copy, modify, merge, publish, distribute, sublicense, and/or sell       *
 *   copies of the Software, and to permit persons to whom the Software is           *
 *   furnished to do so, subject to the following conditions:                        *
 *                                                                                   *
 *   The above copyright notice and this permission notice shall be included in all  *
 *   copies or substantial portions of the Software.                                 *
 *                                                                                   *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR      *
 *   IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,        *
 *   FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE     *
 *   AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER          *
 *   LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,   *
 *   OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE   *
 *   SOFTWARE.                                                                       *
 ************************************************************************************/
 
 namespace DBD;
 use Exception;
 
 final class YellowERP extends OData {
	private static $retry = 0;
	private static $ibsession = null;
	
	protected $reuseSessions = false;
	protected $maxRetries = 3;
	
	public function connect() {
//		echo("YellowERP connector\n");
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->dsn);
		curl_setopt($ch, CURLOPT_USERAGENT, 'DBD\YellowERP driver');
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		if ($this->username && $this->password) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $this->username.":".$this->password);
		}
		curl_setopt($ch, CURLOPT_POST, false);
		
		if ($this->reuseSessions) {
			self::$retry++;
			
			if (isset($_COOKIE['IBSession']) && trim($_COOKIE['IBSession']) && self::$retry == 1) {
				self::$ibsession = urldecode($_COOKIE['IBSession']);
			}
			
			if (self::$retry > $this->maxRetries) {
				throw new Exception("Too many connection retiries. Can't initiate session");
			}
			
			if (self::$ibsession) {
				curl_setopt($ch, CURLOPT_COOKIE, "ibsession=".self::$ibsession);
//				echo("Reusing session: ".self::$ibsession."\n");
			} else {
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('IBSession: start'));
//				echo("Starting session\n");
			}
		}
		
		$response  = curl_exec($ch);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		if ($this->reuseSessions) {
			if ($httpcode == 0) { throw new Exception("No connection"); }
			if ($httpcode == 406) { throw new Exception("406 Not Acceptable. ERP can't initiate new session"); }
			if ($httpcode == 400 || $httpcode == 404) {self::$ibsession = null; return $this->connect(); } 
			
			preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
			
			$cookies = array();
			foreach($matches[1] as $item) {
				parse_str($item, $cookie);
				$cookies = array_merge($cookies, $cookie);
			}
			if ($cookies['ibsession']) {
//				echo("Cookie: ".$cookies['ibsession']."\n");
				self::$ibsession = $cookies['ibsession'];
				@setcookie('IBSession', $cookies['ibsession'], time() + 60*60*24, '/');
			}
			self::$retry = 0;
		}
		
		if ($httpcode>=200 && $httpcode<300) {
			$this->dbh = $ch;
			return $this;
		} else {
			trigger_error($body, E_USER_ERROR);
		}
	}
	
	public function finish()
	{
		if ($this->dbh && self::$ibsession) {
			curl_setopt($this->dbh, CURLOPT_URL, $this->dsn);
			curl_setopt($this->dbh, CURLOPT_COOKIE, "ibsession=".self::$ibsession);
			curl_setopt($this->dbh, CURLOPT_HTTPHEADER, array('IBSession: finish'));
			curl_exec($this->dbh);
		}
		self::$ibsession = null;
		return $this;
	}
	
	public function reuseSessions($maxRetries = 3) {
		$this->reuseSessions = true;
		$this->maxRetries = $maxRetries;
		
		return $this;
	}
 }