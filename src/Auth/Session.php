<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Auth;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: The logged-in user of a web application: login, logout, idle timeout,
	                    the token protecting the forms (CSRF) & the permissions of the roles.
	2026-10-23	v1.01	start(false) for a request the page sends by itself (e.g. a periodic status check): it sees an expired
	                    login but does not count as activity, otherwise an open page would keep the user logged in for ever

	Setup, at the start of every request (front controller):
		Session::configure([
			'idleMinutes' => 60,
			'permissions' => [
				'operator'      => ['jobs.view', 'jobs.manage', 'events.view', 'events.acknowledge'],
				'administrator' => ['*'],			// every permission
			],
		]);
		Session::start();                          // Session::start(false) for a background request, see start()
	Use:
		Session::login($user);                     // after Users::attemptLogin() succeeded
		Session::can('jobs.manage');               // in a controller or a view
		$router->post('/resendJob', ...)->only('can:jobs.manage');
		<?= Session::csrfField() ?>                // in every form, checked with Session::validCsrf($_POST['_csrf'] ?? null)

	- The user is kept in $_SESSION['user'] (id, username, role, language), as the older login() helper did
	- The session id changes at login & logout, so a session id known before the login is worthless after it
	- The session cookie is HttpOnly & SameSite=Lax, & Secure when the request came over HTTPS.
	  A login form must be served over HTTPS in production: over HTTP the password travels readable
**/

class Session
{
	private const USER_KEY = 'user';
	private const ACTIVITY_KEY = '_last_activity';
	private const CSRF_KEY = '_csrf';

	private static int $idleMinutes = 60;
	private static array $permissions = [];		// role => list of permissions, '*' = all
	private static string $loginUrl = '/login';
	private static ?string $cookieName = null;	// null: PHP's default (PHPSESSID)
	private static bool $expired = false;		// True if start() ended an idle session in this request

	/**
	 * Options, all optional:
	 *   'idleMinutes' => minutes without a request before the user is logged out (default: 60)
	 *   'permissions' => role => list of permissions, '*' for all of them (default: none, every can() is false)
	 *   'loginUrl'    => where Auth middleware sends a user who is not logged in (default: /login)
	 *   'cookieName'  => the name of the session cookie (default: PHP's)
	 */
	public static function configure(array $options) : void
	{
		self::$idleMinutes = max(1, (int)($options['idleMinutes'] ?? 60));
		self::$permissions = $options['permissions'] ?? [];
		self::$loginUrl = (string)($options['loginUrl'] ?? '/login');
		self::$cookieName = isset($options['cookieName']) && $options['cookieName'] !== '' ? (string)$options['cookieName'] : null;
		self::$expired = false;
	}

	/**
	 * Starts the PHP session with safe cookie settings, if not started yet, & logs out a user idle for too long.
	 * Call it before any output.
	 * $isActivity: false for a request the page sends by itself, not the user (e.g. a status check every 30 seconds).
	 * It is still refused once the user is idle for too long, but does not restart the idle time.
	 */
	public static function start(bool $isActivity = true) : void
	{
		if(session_status() !== PHP_SESSION_ACTIVE)
		{
			if(self::$cookieName !== null)
			{	session_name(self::$cookieName);	}
			ini_set('session.use_strict_mode', '1');		// A session id the server did not create is refused
			session_set_cookie_params([
				'lifetime' => 0,							// Until the browser closes, the idle timeout ends it earlier
				'path'     => '/',
				'secure'   => self::isHttps(),
				'httponly' => true,
				'samesite' => 'Lax',
			]);
			session_start();
		}
		self::checkIdle(null, $isActivity);
	}

	/**
	 * Logs out the user if the last activity is older than 'idleMinutes', then notes this request's time if $isActivity.
	 * Called by start(). $now is for the tests (a Unix time), default: now. Works on $_SESSION only.
	 */
	public static function checkIdle(?int $now = null, bool $isActivity = true) : void
	{
		$now ??= time();
		if(self::check() && isset($_SESSION[self::ACTIVITY_KEY]) && $now - (int)$_SESSION[self::ACTIVITY_KEY] > self::$idleMinutes * 60)
		{
			self::clear();
			self::$expired = true;
		}
		if($isActivity || !isset($_SESSION[self::ACTIVITY_KEY]))
		{	$_SESSION[self::ACTIVITY_KEY] = $now;	}
	}

	// True if start() logged the user out in this request because of the idle timeout, e.g. to show "logged out after inactivity"
	public static function expired() : bool
	{	return self::$expired;	}

	// Opens the session of the user, after Users::attemptLogin() succeeded
	public static function login(User $user) : void
	{
		self::regenerateId();
		$_SESSION[self::USER_KEY] = $user->toSession();
		$_SESSION[self::ACTIVITY_KEY] = time();
		$_SESSION[self::CSRF_KEY] = self::newToken();		// A token known before the login is worthless after it
		self::$expired = false;
	}

	// Ends the session of the user
	public static function logout() : void
	{
		self::clear();
		self::regenerateId();
	}

	// --- The logged-in user ---

	public static function check() : bool
	{	return !empty($_SESSION[self::USER_KEY]);	}

	// The session data of the user (id, username, role, language), or null if nobody is logged in
	public static function user() : ?array
	{	return self::check() ? $_SESSION[self::USER_KEY] : null;	}

	public static function id() : ?int
	{	return isset($_SESSION[self::USER_KEY]['id']) ? (int)$_SESSION[self::USER_KEY]['id'] : null;	}

	public static function username() : ?string
	{	return $_SESSION[self::USER_KEY]['username'] ?? null;	}

	public static function role() : ?string
	{	return $_SESSION[self::USER_KEY]['role'] ?? null;	}

	public static function language() : ?string
	{	return $_SESSION[self::USER_KEY]['language'] ?? null;	}

	// After the user changed the language (Users::setLanguage()), so the session follows without a new login
	public static function setLanguage(?string $language) : void
	{
		if(self::check())
		{	$_SESSION[self::USER_KEY]['language'] = $language;	}
	}

	// --- Permissions ---

	// True if the logged-in user's role has this permission
	public static function can(string $permission) : bool
	{
		$role = self::role();
		return $role !== null && self::roleCan($role, $permission);
	}

	// True if the role has this permission, according to 'permissions'
	public static function roleCan(string $role, string $permission) : bool
	{
		$granted = self::$permissions[$role] ?? [];
		return in_array('*', $granted, true) || in_array($permission, $granted, true);
	}

	public static function loginUrl() : string
	{	return self::$loginUrl;	}

	// --- Protecting the forms (CSRF) ---

	// The token of this session, created at the first use: sent back by every form & checked with validCsrf()
	public static function csrfToken() : string
	{
		if(empty($_SESSION[self::CSRF_KEY]))
		{	$_SESSION[self::CSRF_KEY] = self::newToken();	}
		return $_SESSION[self::CSRF_KEY];
	}

	// A hidden form field holding the token, named _csrf
	public static function csrfField() : string
	{	return '<input type="hidden" name="_csrf" value="'.htmlspecialchars(self::csrfToken(), ENT_QUOTES).'">';	}

	// True if $token is the token of this session
	public static function validCsrf(?string $token) : bool
	{
		return $token !== null && $token !== '' && !empty($_SESSION[self::CSRF_KEY])
			&& hash_equals((string)$_SESSION[self::CSRF_KEY], $token);
	}

	// --- Helpers ---

	// True if the request came over HTTPS, directly or through a proxy that says so
	public static function isHttps() : bool
	{
		return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
			|| (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
			|| strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	}

	private static function clear() : void
	{	unset($_SESSION[self::USER_KEY], $_SESSION[self::CSRF_KEY]);	}

	private static function regenerateId() : void
	{
		if(session_status() === PHP_SESSION_ACTIVE)		// Not in the command line or the unit tests
		{	session_regenerate_id(true);	}
	}

	private static function newToken() : string
	{	return bin2hex(random_bytes(32));	}
}
