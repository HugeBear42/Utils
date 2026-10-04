<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;
/**
	2021-10-30	v1.00	frank.a.hubert@gmail.com: Simple object to encapsulate all generic logging functions
	2022-01-13	v1.01	removed not-logged-in username and ::1 IP address when call no session info is available or call is from localhost
	2024-02-13	v1.02   Updated logMessage() to also support command line scripts
	2026-10-04	v1.10	configure(): log files with a new file every month, application name on every line, minimum level.
	                    Optional context array (written as JSON), one line per message (line breaks escaped),
	                    the request path & client address instead of the front controller name.
	                    Without configure(), messages still go to PHP's error_log as before.

	Usage:
		Logger::configure(['dir' => __DIR__.'/../logs', 'application' => 'XMPieDashboard', 'level' => 'info']);
		Logger::info('Job registered', ['id' => 100001]);
	writes to <dir>/XMPieDashboard-2026-10.log:
		2026-10-04 14:05:12 [XMPieDashboard] INFO    Job registered (POST /XMPieDashboardBaloise from 10.0.0.7) {"id":100001}
**/

class Logger
{
	private const LEVELS = ['fine' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

	private static ?string $dir = null;			// null: PHP's error_log (the default, as before v1.10)
	private static string $application = '';	// added to every line, & the start of the log file name
	private static int $minLevel = 0;			// messages below this level are not logged, 0 = fine

	function __construct()
	{}

	/**
	 * Sets where & what is logged. Every option is optional, a missing one takes its default:
	 *   'dir'         => folder of the log files, one file per month: <application>-<yyyy-mm>.log (default: PHP's error_log)
	 *   'application' => name added to every line & used for the file name (default: none)
	 *   'level'       => 'fine', 'info', 'warning' or 'error': the lowest level logged (default: 'fine')
	 * Logger::configure([]) restores all the defaults.
	 */
	public static function configure(array $options) : void
	{
		self::$dir = isset($options['dir']) && $options['dir'] !== '' ? rtrim((string)$options['dir'], '/\\') : null;
		self::$application = (string)($options['application'] ?? '');
		self::$minLevel = self::LEVELS[$options['level'] ?? 'fine'] ?? self::LEVELS['fine'];
	}

	// Kept for compatibility: debug on = 'fine' messages are logged too, off = from 'info' up
	public static function setDebug(bool $debug) : void
	{	self::$minLevel = $debug ? self::LEVELS['fine'] : self::LEVELS['info'];	}
	public static function isDebug() : bool
	{	return self::$minLevel === self::LEVELS['fine'];	}

	// The file the next message goes to, null when logging to PHP's error_log
	public static function getLogFile() : ?string
	{
		if(self::$dir === null)
		{	return null;	}
		$name = preg_replace('/[^A-Za-z0-9_-]/', '', self::$application);
		return self::$dir.DIRECTORY_SEPARATOR.($name !== '' ? $name : 'app').'-'.date('Y-m').'.log';		// A new file every month
	}

	public static function fine(mixed $msg, array $context = []) : void
	{	self::log('fine', $msg, $context);	}

	public static function info(mixed $msg, array $context = []) : void
	{	self::log('info', $msg, $context);	}

	public static function warning(mixed $msg, array $context = []) : void
	{	self::log('warning', $msg, $context);	}

	public static function error(mixed $msg, array $context = []) : void
	{	self::log('error', $msg, $context);	}

	private static function log(string $level, mixed $msg, array $context) : void
	{
		if(self::LEVELS[$level] < self::$minLevel)
		{	return;	}
	// One line per message: a multi-line message (e.g. an XML payload) would mix with the next lines
		$text = str_replace(["\r\n", "\r", "\n"], '\n', self::toText($msg));
		$details = self::details();
		$line = (self::$application !== '' ? '['.self::$application.'] ' : '')
			.str_pad(strtoupper($level), 7).' '.$text
			.($details !== '' ? " ($details)" : '')
			.(empty($context) ? '' : ' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

		$file = self::getLogFile();
		if($file !== null && self::writeLine($file, date('Y-m-d H:i:s').' '.$line))
		{	return;	}
		error_log($line);		// No folder configured, or it cannot be written: PHP's error_log, logging never throws
	}

	// Where the message comes from: the script name from the command line, otherwise the request & the client address
	private static function details() : string
	{
		if(PHP_SAPI === 'cli')
		{	return 'cli: '.basename((string)($_SERVER['argv'][0] ?? ''));	}
		$request = trim(($_SERVER['REQUEST_METHOD'] ?? '').' '.(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
		$address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		$fromLocalhost = $address === '' || $address === '::1' || $address === '127.0.0.1';
		return trim($request.($fromLocalhost ? '' : ' from '.$address));
	}

	// Anything can be logged: a string as is, a number or Stringable as text, an array or object as print_r() shows it
	private static function toText(mixed $msg) : string
	{
		return match(true)
		{
			is_string($msg)							=> $msg,
			$msg === null							=> 'null',
			is_bool($msg)							=> $msg ? 'true' : 'false',
			is_scalar($msg), $msg instanceof \Stringable	=> (string)$msg,
			default									=> print_r($msg, true),
		};
	}

	// Appends the line, the folder is created when needed. False if it cannot be written
	private static function writeLine(string $file, string $line) : bool
	{
		$dir = dirname($file);
		if(!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
		{	return false;	}
		return @file_put_contents($file, $line.PHP_EOL, FILE_APPEND | LOCK_EX) !== false;		// LOCK_EX: two processes never mix their lines
	}
}
