<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Auth;

use HugeBear42\Utils\Core\ApplicationException;
use HugeBear42\Utils\Core\Database;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: The users of an application, stored in a database table, with a role & a preferred language.
	2026-10-22	v1.01	The reads close their query: an open one kept a SQLite file locked for another connection (e.g. EventLog's), whose writes failed

	Setup, once per request or script:
		Users::configure(['db' => $config['db'], 'table' => 'users', 'roles' => ['operator', 'administrator']]);
		Users::createTable();      // optional, creates the table if it does not exist yet (MySQL, SQL Server, SQLite)
	Use:
		Users::create('frank', 'a long password', 'administrator', 'de');
		$result = Users::attemptLogin($_POST['username'], $_POST['password']);
		if($result->ok())	Session::login($result->user);

	- The passwords are stored as hashes (password_hash()), they cannot be read back
	- The usernames are stored in lower case: 'Frank' & 'frank' are the same user
	- After 'maxFailedLogins' wrong passwords in a row, the user is locked for 'lockMinutes' minutes
	- An unknown username takes as long to check as a known one, so the response time does not tell which usernames exist
	- The package knows no role names: the application lists its roles ('roles') & what each may do (see Session)
	- With 'protectedRole' (e.g. 'administrator'), the last active user of that role cannot be deleted, deactivated or
	  given another role, so the application always keeps someone able to manage it
	- The dates are stored in UTC
**/

class Users
{
	public const MIN_PASSWORD_LENGTH = 10;		// Default, see configure()
	private const MAX_PASSWORD_BYTES = 72;		// bcrypt, PHP's default hash, ignores anything longer
	private const USERNAME_PATTERN = '/^[a-z0-9._@-]{1,64}$/';

	private static array|Database|null $db = null;	// The configured connection, or its settings until the first use
	private static string $table = 'users';
	private static array $roles = [];				// Empty: any role name is accepted
	private static ?string $protectedRole = null;	// The role always kept by at least one active user, null: none
	private static int $maxFailedLogins = 5;
	private static int $lockMinutes = 15;
	private static int $minPasswordLength = self::MIN_PASSWORD_LENGTH;
	private static ?string $dummyHash = null;		// Checked against when the username is unknown

	/**
	 * Sets where the users are stored & the login rules. Options:
	 *   'db'                => a Database, or the settings to open one at the first use (required)
	 *   'table'             => the table name (default: users)
	 *   'roles'             => the role names allowed, e.g. ['operator', 'administrator'] (default: any)
	 *   'protectedRole'     => the role at least one active user must keep, e.g. 'administrator' (default: none)
	 *   'maxFailedLogins'   => wrong passwords in a row before the user is locked (default: 5)
	 *   'lockMinutes'       => how long a user stays locked (default: 15)
	 *   'minPasswordLength' => (default: 10)
	 * @throws \InvalidArgumentException for an invalid table name (a programming error)
	 */
	public static function configure(array $options) : void
	{
		$table = (string)($options['table'] ?? 'users');
		self::checkTableName($table);
		self::$db = $options['db'] ?? null;
		self::$table = $table;
		self::$roles = array_values(array_map('strval', $options['roles'] ?? []));
		self::$protectedRole = isset($options['protectedRole']) && $options['protectedRole'] !== '' ? (string)$options['protectedRole'] : null;
		if(self::$protectedRole !== null && !empty(self::$roles) && !in_array(self::$protectedRole, self::$roles, true))
		{	throw new \InvalidArgumentException("Users: the protected role '".self::$protectedRole."' is not one of the roles");	}
		self::$maxFailedLogins = max(1, (int)($options['maxFailedLogins'] ?? 5));
		self::$lockMinutes = max(1, (int)($options['lockMinutes'] ?? 15));
		self::$minPasswordLength = max(1, (int)($options['minPasswordLength'] ?? self::MIN_PASSWORD_LENGTH));
	}

	// The connection, opened at the first use. Throws if Users is not configured or the database cannot be reached
	public static function database() : Database
	{
		if(self::$db === null)
		{	throw new ApplicationException('Users is not configured, call Users::configure() first');	}
		if(is_array(self::$db))
		{	self::$db = new Database(self::$db);	}
		return self::$db;
	}

	public static function table() : string
	{	return self::$table;	}

	public static function roles() : array
	{	return self::$roles;	}

	// --- Logging in ---

	/**
	 * Checks a username & password, counts the failures & locks the user after too many. Throws on a database error.
	 * The caller records the outcome (a log line, an event) & opens the session with Session::login($result->user).
	 */
	public static function attemptLogin(string $username, string $password) : LoginResult
	{
		$row = self::findRow('username = :username', ['username' => self::normaliseUsername($username)]);
		if($row === null)
		{
			password_verify($password, self::dummyHash());		// Same time as a known username
			return new LoginResult(LoginResult::INVALID);
		}
		$now = self::utcNow();
		if($row['locked_until'] !== null && (string)$row['locked_until'] > $now)
		{	return new LoginResult(LoginResult::LOCKED, lockedUntil: (string)$row['locked_until']);	}

		if(!password_verify($password, (string)$row['password_hash']))
		{
			$failures = (int)$row['failed_logins'] + 1;
			if($failures >= self::$maxFailedLogins)
			{
				$lockedUntil = self::utcNow('+'.self::$lockMinutes.' minutes');
				self::update((int)$row['id'], ['failed_logins' => 0, 'locked_until' => $lockedUntil]);
				return new LoginResult(LoginResult::LOCKED, lockedUntil: $lockedUntil, lockedNow: true);
			}
			self::update((int)$row['id'], ['failed_logins' => $failures, 'locked_until' => null]);
			return new LoginResult(LoginResult::INVALID);
		}
		if(!(int)$row['active'])
		{	return new LoginResult(LoginResult::INACTIVE);	}

		$changes = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => $now];
		if(password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT))		// PHP's default hash became stronger
		{	$changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);	}
		self::update((int)$row['id'], $changes);
		return new LoginResult(LoginResult::OK, self::find((int)$row['id']));
	}

	// --- Reading ---

	// The user with this id, or null. Throws on a database error.
	public static function find(int $id) : ?User
	{
		$row = self::findRow('id = :id', ['id' => $id]);
		return $row === null ? null : User::fromRow($row);
	}

	// The user with this username (any case), or null. Throws on a database error.
	public static function findByUsername(string $username) : ?User
	{
		$row = self::findRow('username = :username', ['username' => self::normaliseUsername($username)]);
		return $row === null ? null : User::fromRow($row);
	}

	// All the users, sorted by username. Throws on a database error.
	public static function all() : array
	{
		$rows = self::database()->query('SELECT * FROM '.self::$table.' ORDER BY username')->get();
		return array_map([User::class, 'fromRow'], $rows);
	}

	// Number of users, optionally of one role & only the active ones, e.g. to keep at least one administrator. Throws on a database error.
	public static function count(?string $role = null, bool $activeOnly = true) : int
	{
		$where = [];
		$params = [];
		if($role !== null)
		{
			$where[] = 'role = :role';
			$params['role'] = $role;
		}
		if($activeOnly)
		{	$where[] = 'active = 1';	}
		$filter = empty($where) ? '' : ' WHERE '.implode(' AND ', $where);
		return (int)self::database()->query('SELECT COUNT(*) AS n FROM '.self::$table.$filter, $params)->find()['n'];
	}

	// The user is locked by failed logins until this UTC date & time, or null. Throws on a database error.
	public static function lockedUntil(int $id) : ?string
	{
		$row = self::findRow('id = :id', ['id' => $id]);
		return $row !== null && $row['locked_until'] !== null && (string)$row['locked_until'] > self::utcNow() ? (string)$row['locked_until'] : null;
	}

	// --- Changing ---

	/**
	 * Adds a user & returns it.
	 * @throws \InvalidArgumentException for an invalid or existing username, a too short password or an unknown role
	 * Throws on a database error.
	 */
	public static function create(string $username, string $password, string $role, ?string $language = null) : User
	{
		$username = self::normaliseUsername($username);
		if(!preg_match(self::USERNAME_PATTERN, $username))
		{	throw new \InvalidArgumentException("Invalid username '$username': 1 to 64 letters, digits or . _ @ -");	}
		if(self::findByUsername($username) !== null)
		{	throw new \InvalidArgumentException("The user '$username' already exists");	}
		self::checkPassword($password);
		self::checkRole($role);
		self::database()->query('INSERT INTO '.self::$table.' (username, password_hash, role, language, active, failed_logins, created_at)
			VALUES (:username, :password_hash, :role, :language, 1, 0, :created_at)', [
			'username'      => $username,
			'password_hash' => password_hash($password, PASSWORD_DEFAULT),
			'role'          => $role,
			'language'      => self::normaliseLanguage($language),
			'created_at'    => self::utcNow(),
		]);
		return self::findByUsername($username);
	}

	// Each setter returns false if the user does not exist, & throws on a database error.

	/** @throws \InvalidArgumentException for a too short or too long password */
	public static function setPassword(int $id, string $password) : bool
	{
		self::checkPassword($password);			// A new password also unlocks the user
		return self::update($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null]);
	}

	/**
	 * @throws \InvalidArgumentException for a role not in 'roles'
	 * @throws LastProtectedUserException if the user is the last active one of the protected role & $role is another one
	 */
	public static function setRole(int $id, string $role) : bool
	{
		self::checkRole($role);
		if($role !== self::$protectedRole)
		{	self::checkNotLastProtected($id);	}
		return self::update($id, ['role' => $role]);
	}

	// null: the user interface follows the browser's language
	public static function setLanguage(int $id, ?string $language) : bool
	{	return self::update($id, ['language' => self::normaliseLanguage($language)]);	}

	/**
	 * A deactivated user cannot log in, but keeps the username (a new user cannot take it, so the events
	 * signed with that name stay unambiguous) & can be activated again.
	 * @throws LastProtectedUserException when deactivating the last active user of the protected role
	 */
	public static function setActive(int $id, bool $active) : bool
	{
		if(!$active)
		{	self::checkNotLastProtected($id);	}
		return self::update($id, ['active' => $active ? 1 : 0]);
	}

	/**
	 * Removes the user, returns false if the user does not exist. The username becomes free for a new user,
	 * prefer setActive(false) for someone who used the application (the events show the usernames).
	 * @throws LastProtectedUserException when deleting the last active user of the protected role
	 */
	public static function delete(int $id) : bool
	{
		if(self::findRow('id = :id', ['id' => $id]) === null)
		{	return false;	}
		self::checkNotLastProtected($id);
		self::database()->query('DELETE FROM '.self::$table.' WHERE id = :id', ['id' => $id]);
		return true;
	}

	// Ends a lock after failed logins before its time
	public static function unlock(int $id) : bool
	{	return self::update($id, ['failed_logins' => 0, 'locked_until' => null]);	}

	// --- The table ---

	/**
	 * The statements creating the users table, if it does not exist yet, for 'mysql', 'sqlsrv' or 'sqlite'.
	 * Run them with createTable(), or copy them into an application's installation scripts.
	 */
	public static function schema(string $type, ?string $table = null) : array
	{
		$t = $table ?? self::$table;
		self::checkTableName($t);
		return match($type)
		{
			'mysql' => ["CREATE TABLE IF NOT EXISTS $t (
				id               INT AUTO_INCREMENT PRIMARY KEY,
				username         VARCHAR(64)  NOT NULL,
				password_hash    VARCHAR(255) NOT NULL,
				role             VARCHAR(32)  NOT NULL,
				language         VARCHAR(8)   NULL,         -- NULL: the browser's language
				active           BOOLEAN      NOT NULL DEFAULT TRUE,
				failed_logins    INT          NOT NULL DEFAULT 0,
				locked_until     DATETIME     NULL,
				last_login_at    DATETIME     NULL,
				created_at       DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),    -- UTC, requires MySQL 8.0.13+ / MariaDB 10.2.1+
				CONSTRAINT uq_{$t}_username UNIQUE (username)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],
			'sqlsrv' => ["IF OBJECT_ID(N'$t', N'U') IS NULL
			BEGIN
				CREATE TABLE $t (
					id               INT IDENTITY(1,1) NOT NULL CONSTRAINT pk_$t PRIMARY KEY,
					username         NVARCHAR(64)   NOT NULL CONSTRAINT uq_{$t}_username UNIQUE,
					password_hash    VARCHAR(255)   NOT NULL,
					role             VARCHAR(32)    NOT NULL,
					language         VARCHAR(8)         NULL,
					active           BIT            NOT NULL CONSTRAINT df_{$t}_active DEFAULT 1,
					failed_logins    INT            NOT NULL CONSTRAINT df_{$t}_failed_logins DEFAULT 0,
					locked_until     DATETIME2(0)       NULL,
					last_login_at    DATETIME2(0)       NULL,
					created_at       DATETIME2(0)   NOT NULL CONSTRAINT df_{$t}_created_at DEFAULT SYSUTCDATETIME()
				);
			END"],
			'sqlite' => ["CREATE TABLE IF NOT EXISTS $t (
				id               INTEGER PRIMARY KEY AUTOINCREMENT,
				username         TEXT    NOT NULL UNIQUE,
				password_hash    TEXT    NOT NULL,
				role             TEXT    NOT NULL,
				language         TEXT    NULL,
				active           INTEGER NOT NULL DEFAULT 1,
				failed_logins    INTEGER NOT NULL DEFAULT 0,
				locked_until     TEXT    NULL,
				last_login_at    TEXT    NULL,
				created_at       TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP    -- UTC, yyyy-mm-dd hh:mm:ss
			)"],
			default => throw new \InvalidArgumentException("Users: no schema for database type '$type'"),
		};
	}

	// Creates the users table in the configured database if it does not exist yet. Throws on a database error.
	public static function createTable() : void
	{
		$db = self::database();
		foreach(self::schema($db->getType()) as $statement)
		{	$db->connection->exec($statement);	}
	}

	// --- Helpers ---

	private static function findRow(string $condition, array $params) : ?array
	{
		$db = self::database();
		$row = $db->query('SELECT * FROM '.self::$table." WHERE $condition", $params)->find();
		$db->getStatement()->closeCursor();		// A query left open keeps a SQLite file locked for another connection (e.g. EventLog's)
		return $row ?: null;
	}

	// Sets the columns of one user, returns false if the user does not exist. The column names come from this class only.
	private static function update(int $id, array $columns) : bool
	{
		if(self::findRow('id = :id', ['id' => $id]) === null)		// rowCount() is 0 as well when nothing changed
		{	return false;	}
		$set = implode(', ', array_map(fn($column) => "$column = :$column", array_keys($columns)));
		self::database()->query('UPDATE '.self::$table." SET $set WHERE id = :id", $columns + ['id' => $id]);
		return true;
	}

	// Throws if the user is the last active one of the protected role, i.e. removing it would leave nobody able to manage the application
	private static function checkNotLastProtected(int $id) : void
	{
		if(self::$protectedRole === null)
		{	return;	}
		$row = self::findRow('id = :id', ['id' => $id]);
		if($row !== null && (int)$row['active'] && $row['role'] === self::$protectedRole && self::count(self::$protectedRole) <= 1)
		{	throw new LastProtectedUserException((string)$row['username'], self::$protectedRole);	}
	}

	private static function normaliseUsername(string $username) : string
	{	return mb_strtolower(trim($username));	}

	private static function normaliseLanguage(?string $language) : ?string
	{
		$language = $language === null ? '' : strtolower(trim($language));
		return $language === '' ? null : substr($language, 0, 8);
	}

	private static function checkPassword(string $password) : void
	{
		if(mb_strlen($password) < self::$minPasswordLength)
		{	throw new \InvalidArgumentException('The password must have at least '.self::$minPasswordLength.' characters');	}
		if(strlen($password) > self::MAX_PASSWORD_BYTES)
		{	throw new \InvalidArgumentException('The password must have at most '.self::MAX_PASSWORD_BYTES.' bytes');	}
	}

	private static function checkRole(string $role) : void
	{
		if(!empty(self::$roles) && !in_array($role, self::$roles, true))
		{	throw new \InvalidArgumentException("Unknown role '$role', expected one of: ".implode(', ', self::$roles));	}
		if($role === '' || strlen($role) > 32)
		{	throw new \InvalidArgumentException("Invalid role '$role': 1 to 32 characters");	}
	}

	private static function checkTableName(string $table) : void
	{
		if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table))		// The name is written into the SQL, it is checked instead
		{	throw new \InvalidArgumentException("Users: invalid table name '$table'");	}
	}

	private static function dummyHash() : string
	{	return self::$dummyHash ??= password_hash('not a real password', PASSWORD_DEFAULT);	}

	// The current UTC date & time, optionally moved (e.g. '+15 minutes'), in the format of the date columns
	private static function utcNow(string $modify = '') : string
	{
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		return ($modify === '' ? $now : $now->modify($modify))->format('Y-m-d H:i:s');
	}
}
