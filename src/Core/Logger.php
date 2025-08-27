<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;
/**
	2021-10-30	v1.00	frank.a.hubert@gmail.com: Simple object to encapsulate all generic logging functions 
	2022-01-13	v1.01	removed not-logged-in username and ::1 IP address when call no session info is available or call is from localhost
	2024-02-13	v1.02   Updated logMessage() to also support command line scripts

**/

class Logger
{
	function __construct()
	{}
	
	private static bool $debug=true;	// determines the logging level
	
	
	public static function setDebug(bool $debug) : void
	{	self::$debug=$debug;	}
	public static function isDebug() : bool
	{	return self::$debug==true;	}
	
	public static function fine($msg) : void
	{
		if(self::isDebug())
		{	self::logMessage("FINE:    ".$msg);	}
	}

	public static function info($msg) : void
	{	self::logMessage("INFO:    ".$msg);	}

	public static function warning($msg) : void
	{	self::logMessage("WARNING: ".$msg);	}

	public static function error($msg) : void
	{	self::logMessage("ERROR:   ".$msg);	}

	private static function logMessage($msg) : void
	{
		//$user=isset($_SESSION['user']) ? "user: ".$_SESSION['user'].", " : "";
		//$host=(isset($_SESSION['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR']!="::1") ? " from host ".$_SERVER['REMOTE_ADDR'] : "";
		$host=(isset($_SERVER['REMOTE_ADDR']) && isset($_SERVER['REMOTE_HOST']) && $_SERVER['REMOTE_ADDR']!="::1") ? " from host ".$_SERVER['REMOTE_HOST']." (".$_SERVER['REMOTE_ADDR'].")" : "";
		$page=self::isDebug() ? "page: ".basename($_SERVER['PHP_SELF']) : "";
		$details=trim($page.$host);
		error_log($msg.((strlen($details)==0) ? "" : " ($details)"));
	}
}
