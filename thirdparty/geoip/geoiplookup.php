<?php
	// This product includes GeoLite data created by MaxMind, available from http://www.maxmind.com
	require_once (dirname(__FILE__) . '/geoip.inc.php');
	class GeoIpLookup {
		public $handle = null;
		
		public function __construct() {
			$this->handle = $this->get_handle();
		}
		
		public function get_handle() {
			return ($this->handle ? $this->handle : geoip_open(dirname(__FILE__) . '/GeoIP.dat', null));
		}
		
		public function lookup_country_name($ip) {
			if (!$ip) {
				return null;
			}
			return geoip_country_name_by_addr($this->get_handle(), $ip);
		}
		
		public function __destruct() {
			geoip_close($this->handle);
		}
	}
