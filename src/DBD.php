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

abstract class DBD {

	protected $dsn			= null;
	protected $username		= null;
	protected $password		= null;
	protected $dbh			= null;
	
	protected $query		= "";
	protected $result		= null;
	protected $debug		= null;
	protected $cache		=
		[
		'key'				=> null,
		'result'			=> null,
		'compress'			=> null,
		'expire'			=> null,
		];
	
	protected $options =
		[
			'PrintError'			=> true,
			'RaiseError'			=> false,
			'ShowErrorStatement'	=> true,
			'Persistent'			=> false,
			'ConvertNumeric'		=> false,
			'UseDebug'			 	=> false,
			/** @var Cache CacheDriver */
			'CacheDriver'			=> null,
		];
	
	protected $transaction	= false;

	abstract public function connect();
	abstract public function disconnect();
	abstract public function begin();
	abstract public function commit();
	abstract public function rollback();

	public function create($dsn, $username, $password, $options = array()) {
	
		$driver = get_class($this);
		
		/** @var DBD $db */
		$db = new $driver;
		
		return $db->setDsn($dsn)->
					setUsername($username)->
					setPassword($password)->
					setOptions($options);
	}

	public function setOptions($options) {
		foreach ($options as $key => $value) {
			if (is_string($key)) {
				if (array_key_exists($key, $this->options)) {
					$this->options[$key] = $value;
				} else {
					throw new Exception(
						"Unknown option provided"
					);
				}
			} else {
				throw new Exception(
					"Option must be a string"
				);
			}
		}
		
		return $this;
	}
	
	public function setUsername($name) {
		$this->username = $name;
		
		return $this;
	}

	public function setPassword($password) {
		$this->password = $password;
		
		return $this;
	}

	/**
	 * @param $dsn
	 * @return $this
	 */
	public function setDsn($dsn) {
		$this->dsn = $dsn;
		
		return $this;
	}
	
	public function isConnected() {
		return is_resource($this->dbh);
	}
	
	protected function parse_args($ARGS) {
		$args = array();
		
		foreach ($ARGS as $arg) {
			if (is_array($arg)) {
				foreach ($arg as $subarg) {
					$args[] = $subarg;
				}
			} else {
				$args[] = $arg;
			}
		}
		return $args;
	}
	
	protected function compile_insert($data) {
	
		$columns  = "";
		$values = "";
		$args = array();
		
		foreach ($data as $c => $v) {
			$pattern = "/[^\"a-zA-Z0-9_-]/";
			$c = preg_replace($pattern ,"",$c);
			$columns .= "$c, ";
			$values  .= "?,";
			if ($v === true) { $v = 't'; }
			if ($v === false) { $v = 'f'; }
			$args[]   = $v;
		}
		
		$columns  = preg_replace( "/, $/" , "" , $columns  );
		$values = preg_replace( "/,$/" , "" , $values );
		
		return array( 'COLUMNS' => $columns, 'VALUES' => $values, 'ARGS' => $args );
	}
	
	protected function compile_update($data) {
	
		$columns = "";
		$args = array();
		
		$pattern = "/[^\"a-zA-Z0-9_-]/";
		foreach ($data as $k => $v) {
			$k = preg_replace($pattern,"",$k);
			$columns .=  "$k = ?, ";
			$args[]   = $v;
		}
		
		$columns = preg_replace( "/, $/", "", $columns);
		
		return array( 'COLUMNS' => $columns, 'ARGS' => $args );
	}
	
	public function cache($key, $expire = null, $compress = null)
	{
		if ( ! isset($key) or ! $key)
			trigger_error("caching failed: key is not set or empty", E_USER_ERROR);
		
		if ( preg_match("/^[\s\t\r\n]*select/i", $this->query) ) {
			// set hash key
			$this->cache['key'] = $key;
			
			if ($compress !== null)
				$this->cache['compress'] = $compress;
				
			if ($expire !== null)
				$this->cache['expire'] = $expire;
		} else {
			trigger_error("caching failed: current query is not of SELECT type", E_USER_ERROR);
		}
		
		return;
	}
	
	protected function caller()
	{
		$debug = debug_backtrace();
		
		// working directory
		$wd = $_SERVER["DOCUMENT_ROOT"];
		$wd = str_replace("\\","/",$wd);
		
		foreach ($debug as $ind => $call)
		{
			// our filename
			$call['file'] = str_replace("\\","/",$call['file']);
			$call['file'] = str_replace($wd,'',$call['file']);
			
			if ( !preg_match("/".__CLASS__."\.php/", $call['file']) )
			{
				return array('file' => $call['file'], 'line' => $call['line']);
			}
		}
		return array('file' => $debug[0]['file'], 'line' => $debug[0]['line']);
	}
}