<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;
/**
	2026-10-27	v1.01	The single-row reads close their query (fetchOne()): an open one kept a SQLite file locked for another connection
	2026-10-18	v1.00	frank.a.hubert@gmail.com: Operator events (a rejected request, an export, a resend...) stored in a database table,
	                    to be shown in an application's dashboard. Generalised from the XMPieDashboardBaloise project.

	Setup, once per request or script:
		EventLog::configure(['db' => $config['db'], 'table' => 'events', 'logger' => true]);
		EventLog::createTable();      // optional, creates the table if it does not exist yet (MySQL, SQL Server, SQLite)
	Use:
		EventLog::error('job_rejected', 'api', "Job 100001 rejected: <n_up> value '40' must be at most 32", reference: '100001', payload: $xml);
		EventLog::info('job_exported', 'export_cli', 'Job 100001 exported', reference: '100001');

	- Recording never throws: a failure is written to the Logger & the caller carries on,
	  logging must never break the work it describes (e.g. the HTTP response to a client)
	- Every event is also written to the Logger (unless 'logger' => false), at the same level
	- The dates are stored in UTC
	- 'reference' is free text (up to 64 characters) chosen by the application: an order number, a file name, a customer id...
**/

class EventLog
{
	public const INFO = 'info';
	public const WARNING = 'warning';
	public const ERROR = 'error';
	public const LEVELS = [self::INFO, self::WARNING, self::ERROR];

	// The columns a list can be sorted by
	public const SORTABLE_COLUMNS = ['created_at', 'level', 'event_type', 'source', 'reference', 'message'];
	public const LIST_LIMIT = 200;					// Default maximum number of events listed

	private const MAX_MESSAGE_CHARS = 512;			// message VARCHAR(512)
	private const MAX_REFERENCE_CHARS = 64;		// reference VARCHAR(64)
	private const MAX_TYPE_CHARS = 32;				// event_type & source VARCHAR(32)
	private const MAX_TEXT_BYTES = 65535;			// details & payload: MySQL TEXT holds 64 KB

	private static array|Database|null $db = null;	// The configured connection, or its settings until the first use
	private static string $table = 'events';
	private static bool $mirrorToLogger = true;

	/**
	 * Sets where the events are stored. Options:
	 *   'db'     => a Database, or the settings to open one when the first event is recorded (required)
	 *   'table'  => the table name (default: events)
	 *   'logger' => true to also write every event to the Logger (default: true)
	 * @throws \InvalidArgumentException for an invalid table name (a programming error)
	 */
	public static function configure(array $options) : void
	{
		$table = (string)($options['table'] ?? 'events');
		if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table))		// The name is written into the SQL, it is checked instead
		{	throw new \InvalidArgumentException("EventLog: invalid table name '$table'");	}
		self::$db = $options['db'] ?? null;
		self::$table = $table;
		self::$mirrorToLogger = (bool)($options['logger'] ?? true);
	}

	// The connection, opened at the first use. Throws if EventLog is not configured or the database cannot be reached
	public static function database() : Database
	{
		if(self::$db === null)
		{	throw new ApplicationException('EventLog is not configured, call EventLog::configure() first');	}
		if(is_array(self::$db))
		{	self::$db = new Database(self::$db);	}
		return self::$db;
	}

	public static function table() : string
	{	return self::$table;	}

	// --- Recording ---

	public static function info(string $eventType, string $source, string $message, ?string $reference = null, ?string $details = null, ?string $payload = null) : bool
	{	return self::record(self::INFO, $eventType, $source, $message, $reference, $details, $payload);	}

	public static function warning(string $eventType, string $source, string $message, ?string $reference = null, ?string $details = null, ?string $payload = null) : bool
	{	return self::record(self::WARNING, $eventType, $source, $message, $reference, $details, $payload);	}

	public static function error(string $eventType, string $source, string $message, ?string $reference = null, ?string $details = null, ?string $payload = null) : bool
	{	return self::record(self::ERROR, $eventType, $source, $message, $reference, $details, $payload);	}

	/**
	 * Records an event, returns false if it could not be saved (the reason is in the Logger). Never throws.
	 *   $level      EventLog::INFO, WARNING or ERROR (anything else is stored as ERROR)
	 *   $eventType  what happened, e.g. job_rejected (cut at 32 characters)
	 *   $source     who or what caused it, e.g. api, dashboard, export_cli (cut at 32 characters)
	 *   $message    one line, shown in the events list (cut at 512 characters)
	 *   $reference  what it is about: an order number, a file name... (cut at 64 characters)
	 *   $details    e.g. the full list of validation errors (cut at 64 KB)
	 *   $payload    e.g. the rejected request body (cut at 64 KB, not written to the Logger)
	 */
	public static function record(string $level, string $eventType, string $source, string $message,
								  ?string $reference = null, ?string $details = null, ?string $payload = null) : bool
	{
		if(!in_array($level, self::LEVELS, true))
		{	$level = self::ERROR;	}
		$message = mb_substr(self::validUtf8($message), 0, self::MAX_MESSAGE_CHARS);
		$reference = $reference === null ? null : mb_substr(self::validUtf8($reference), 0, self::MAX_REFERENCE_CHARS);
		$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;	// null from the command line

		if(self::$mirrorToLogger)
		{
			Logger::$level("Event $eventType: $message", array_filter(
				['source' => $source, 'reference' => $reference], fn($value) => $value !== null));
		}
		try
		{
			self::database()->query('INSERT INTO '.self::$table.' (level, event_type, source, reference, message, details, payload, remote_ip)
				VALUES (:level, :event_type, :source, :reference, :message, :details, :payload, :remote_ip)', [
				'level'      => $level,
				'event_type' => mb_substr($eventType, 0, self::MAX_TYPE_CHARS),
				'source'     => mb_substr($source, 0, self::MAX_TYPE_CHARS),
				'reference'  => $reference,
				'message'    => $message,
				'details'    => self::cutText($details),
				'payload'    => self::cutText($payload),
				'remote_ip'  => $remoteIp,
			]);
			return true;
		}
		catch(\Throwable $t)
		{
			Logger::error("EventLog: could not record [$level] $eventType: $message, reason: ".$t->getMessage());
			return false;
		}
	}

	/**
	 * True if an event of this type was recorded in the last $minutes minutes, from $remoteIp or, if $remoteIp is null,
	 * from any address, & if $reference is given, about that reference only.
	 * Used to record repeated failures (e.g. a wrong token sent every few seconds) only once in a while.
	 * Returns false on a database error: the event is then recorded rather than lost.
	 */
	public static function recentlyRecorded(string $eventType, ?string $remoteIp, int $minutes, ?string $reference = null) : bool
	{
		try
		{
			$where = ['event_type = :event_type', 'created_at >= :since'];
			$params = ['event_type' => $eventType, 'since' => self::utcNow("-$minutes minutes")];
			if($remoteIp !== null)
			{
				$where[] = 'remote_ip = :remote_ip';
				$params['remote_ip'] = $remoteIp;
			}
			if($reference !== null)
			{
				$where[] = 'reference = :reference';
				$params['reference'] = $reference;
			}
			return (int)self::fetchOne('SELECT COUNT(*) AS n FROM '.self::$table.' WHERE '.implode(' AND ', $where), $params)['n'] > 0;
		}
		catch(\Throwable $t)
		{
			Logger::error("EventLog: could not check the recent $eventType events: ".$t->getMessage());
			return false;
		}
	}

	// --- Reading, for a dashboard ---

	/**
	 * Number of events of this level not acknowledged yet, e.g. for a warning in a dashboard's top bar.
	 * Returns null if it cannot be read (e.g. the database is down), so a page can still open.
	 */
	public static function countOpen(string $level = self::ERROR) : ?int
	{
		try
		{	return (int)self::fetchOne('SELECT COUNT(*) AS n FROM '.self::$table.' WHERE acknowledged_at IS NULL AND level = :level', ['level' => $level])['n'];	}
		catch(\Throwable $t)
		{
			Logger::error("EventLog: could not count the open $level events: ".$t->getMessage());
			return null;
		}
	}

	/**
	 * The events matching the filters, newest first by default, at most $limit rows. Throws on a database error.
	 * Filters, all optional:
	 *   'level'     => error, warning, info, or all (default)
	 *   'date'      => a local day yyyy-mm-dd, in 'timezone' (default: any date)
	 *   'timezone'  => the time zone of 'date' (default: UTC)
	 *   'reference' => only the events about this reference
	 *   'openOnly'  => true: only the events not acknowledged yet
	 * $sortColumn: one of SORTABLE_COLUMNS, otherwise created_at
	 * Each row has the event columns except the payload, & has_payload (1 if there is one, see find())
	 */
	public static function listEvents(array $filters = [], string $sortColumn = 'created_at', string $sortDirection = 'DESC', int $limit = self::LIST_LIMIT) : array
	{
		$db = self::database();
		[$where, $params] = self::buildFilter($filters);
	// Column names & directions cannot be bound as parameters, they are checked against a whitelist instead
		if(!in_array($sortColumn, self::SORTABLE_COLUMNS, true))
		{	$sortColumn = 'created_at';	}
		$sortDirection = strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';
		$orderBy = "$sortColumn $sortDirection".($sortColumn !== 'created_at' ? ', created_at DESC' : '').", id $sortDirection";
		$limit = max(1, $limit);

		$columns = 'id, created_at, level, event_type, source, reference, message, details,
		            CASE WHEN payload IS NULL THEN 0 ELSE 1 END AS has_payload, remote_ip, acknowledged_at, acknowledged_by';
		$filter = empty($where) ? '' : ' WHERE '.implode(' AND ', $where);
	// The row limit is written differently by SQL Server
		$query = $db->getType() === 'sqlsrv'
			? "SELECT $columns FROM ".self::$table."$filter ORDER BY $orderBy OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY"
			: "SELECT $columns FROM ".self::$table."$filter ORDER BY $orderBy LIMIT $limit";
		return $db->query($query, $params)->get();
	}

	/**
	 * The events matching the filters (see listEvents()) recorded after the event $sinceId: ['new'=>n, 'newErrors'=>n].
	 * Used for live updates of a page. Throws on a database error.
	 */
	public static function countSince(int $sinceId, array $filters = []) : array
	{
		[$where, $params] = self::buildFilter($filters);
		$where[] = 'id > :since_id';
		$params['since_id'] = $sinceId;
		$row = self::fetchOne("SELECT COUNT(*) AS new_count, SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS new_errors
			FROM ".self::$table.' WHERE '.implode(' AND ', $where), $params);
		return ['new' => (int)$row['new_count'], 'newErrors' => (int)$row['new_errors']];	// SUM() is NULL when there are none
	}

	// The highest event id, 0 if there are none: the events recorded later have a higher id. Throws on a database error.
	public static function maxId() : int
	{	return (int)(self::fetchOne('SELECT MAX(id) AS max_id FROM '.self::$table)['max_id'] ?? 0);	}

	// One event with its payload, or an empty array if it does not exist. Throws on a database error.
	public static function find(int $id) : array
	{
		$row = self::fetchOne('SELECT * FROM '.self::$table.' WHERE id = :id', ['id' => $id]);
		return $row ?: [];
	}

	/**
	 * Marks the events as seen by $acknowledgedBy, events already acknowledged are left unchanged.
	 * Returns the number of events acknowledged. Throws on a database error.
	 */
	public static function acknowledge(array $ids, string $acknowledgedBy) : int
	{
		$count = 0;
		$now = self::utcNow();
		foreach(array_chunk(array_values($ids), 500) as $chunk)		// SQL Server accepts at most 2100 parameters per query
		{
			$params = ['acknowledged_at' => $now, 'acknowledged_by' => mb_substr($acknowledgedBy, 0, 128)];
			$placeholders = [];
			foreach($chunk as $n => $id)
			{
				$placeholders[] = ":id$n";
				$params["id$n"] = (int)$id;
			}
			$count += self::database()->query('UPDATE '.self::$table.' SET acknowledged_at = :acknowledged_at, acknowledged_by = :acknowledged_by
				WHERE acknowledged_at IS NULL AND id IN ('.implode(', ', $placeholders).')', $params)->rowCount();
		}
		return $count;
	}

	// Deletes the events older than $days days, returns the number deleted. For a scheduled cleanup task. Throws on a database error.
	public static function purgeOlderThan(int $days) : int
	{
		if($days < 1)
		{	throw new \InvalidArgumentException("EventLog: purgeOlderThan() needs at least 1 day, $days given");	}
		return self::database()->query('DELETE FROM '.self::$table.' WHERE created_at < :before', ['before' => self::utcNow("-$days days")])->rowCount();
	}

	// --- The table ---

	/**
	 * The statements creating the events table & its indexes, if it does not exist yet, for 'mysql', 'sqlsrv' or 'sqlite'.
	 * Run them with createTable(), or copy them into an application's installation scripts.
	 */
	public static function schema(string $type, ?string $table = null) : array
	{
		$t = $table ?? self::$table;
		if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $t))
		{	throw new \InvalidArgumentException("EventLog: invalid table name '$t'");	}
		$levels = "'".implode("', '", self::LEVELS)."'";
		return match($type)
		{
			'mysql' => ["CREATE TABLE IF NOT EXISTS $t (
				id               BIGINT AUTO_INCREMENT PRIMARY KEY,
				created_at       DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),    -- UTC, requires MySQL 8.0.13+ / MariaDB 10.2.1+
				level            VARCHAR(8)   NOT NULL,
				event_type       VARCHAR(32)  NOT NULL,
				source           VARCHAR(32)  NOT NULL,
				reference        VARCHAR(64)  NULL,
				message          VARCHAR(512) NOT NULL,
				details          TEXT NULL,
				payload          TEXT NULL,
				remote_ip        VARCHAR(45)  NULL,
				acknowledged_at  DATETIME NULL,
				acknowledged_by  VARCHAR(128) NULL,
				CONSTRAINT chk_{$t}_level CHECK (level IN ($levels)),
				INDEX idx_{$t}_created_at (created_at),
				INDEX idx_{$t}_open (acknowledged_at, level),
				INDEX idx_{$t}_reference (reference)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],
			'sqlsrv' => ["IF OBJECT_ID(N'$t', N'U') IS NULL
			BEGIN
				CREATE TABLE $t (
					id               BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT pk_$t PRIMARY KEY,
					created_at       DATETIME2(0)   NOT NULL CONSTRAINT df_{$t}_created_at DEFAULT SYSUTCDATETIME(),
					level            VARCHAR(8)     NOT NULL,
					event_type       VARCHAR(32)    NOT NULL,
					source           VARCHAR(32)    NOT NULL,
					reference        NVARCHAR(64)       NULL,
					message          NVARCHAR(512)  NOT NULL,
					details          NVARCHAR(MAX)      NULL,
					payload          NVARCHAR(MAX)      NULL,
					remote_ip        VARCHAR(45)        NULL,
					acknowledged_at  DATETIME2(0)       NULL,
					acknowledged_by  NVARCHAR(128)      NULL,
					CONSTRAINT chk_{$t}_level CHECK (level IN ($levels))
				);
				CREATE INDEX idx_{$t}_created_at ON $t (created_at);
				CREATE INDEX idx_{$t}_open ON $t (acknowledged_at, level);
				CREATE INDEX idx_{$t}_reference ON $t (reference);
			END"],
			'sqlite' => [
				"CREATE TABLE IF NOT EXISTS $t (
					id               INTEGER PRIMARY KEY AUTOINCREMENT,
					created_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,    -- UTC, yyyy-mm-dd hh:mm:ss
					level            TEXT NOT NULL CHECK (level IN ($levels)),
					event_type       TEXT NOT NULL,
					source           TEXT NOT NULL,
					reference        TEXT NULL,
					message          TEXT NOT NULL,
					details          TEXT NULL,
					payload          TEXT NULL,
					remote_ip        TEXT NULL,
					acknowledged_at  TEXT NULL,
					acknowledged_by  TEXT NULL
				)",
				"CREATE INDEX IF NOT EXISTS idx_{$t}_created_at ON $t (created_at)",
				"CREATE INDEX IF NOT EXISTS idx_{$t}_open ON $t (acknowledged_at, level)",
				"CREATE INDEX IF NOT EXISTS idx_{$t}_reference ON $t (reference)",
			],
			default => throw new \InvalidArgumentException("EventLog: no schema for database type '$type'"),
		};
	}

	// Creates the events table in the configured database if it does not exist yet. Throws on a database error.
	public static function createTable() : void
	{
		$db = self::database();
		foreach(self::schema($db->getType()) as $statement)
		{	$db->connection->exec($statement);	}
	}

	// --- Helpers ---

	// One row (or false), the query closed at once: an open query keeps a SQLite file locked for another connection (e.g. a job insert)
	private static function fetchOne(string $query, array $params = []) : array|false
	{
		$db = self::database();
		$row = $db->query($query, $params)->find();
		$db->getStatement()->closeCursor();
		return $row;
	}

	// The WHERE conditions & parameters of the filters, shared by listEvents() & countSince()
	private static function buildFilter(array $filters) : array
	{
		$where = [];
		$params = [];
		$level = $filters['level'] ?? 'all';
		if(in_array($level, self::LEVELS, true))
		{
			$where[] = 'level = :level';
			$params['level'] = $level;
		}
		if(($filters['date'] ?? '') !== '')
		{
			[$dayStart, $dayEnd] = self::localDayToUtcRange((string)$filters['date'], (string)($filters['timezone'] ?? 'UTC'));
			$where[] = 'created_at >= :day_start AND created_at < :day_end';
			$params['day_start'] = $dayStart;
			$params['day_end'] = $dayEnd;
		}
		if(isset($filters['reference']) && $filters['reference'] !== '')
		{
			$where[] = 'reference = :reference';
			$params['reference'] = (string)$filters['reference'];
		}
		if(!empty($filters['openOnly']))
		{	$where[] = 'acknowledged_at IS NULL';	}
		return [$where, $params];
	}

	// A local day (yyyy-mm-dd in $timezone) as a UTC [start, end) range, the events are stored in UTC.
	// The next day is computed in local time, so the 23 & 25 hour days of the summer time changes are handled.
	// An invalid date or timezone falls back to today / UTC
	private static function localDayToUtcRange(string $date, string $timezone) : array
	{
		$utc = new \DateTimeZone('UTC');
		try
		{	$localZone = new \DateTimeZone($timezone);	}
		catch(\Throwable $t)
		{	$localZone = $utc;	}
		$day = \DateTime::createFromFormat('!Y-m-d', $date, $localZone);
		if(!$day || $day->format('Y-m-d') !== $date)
		{	$day = new \DateTime('today', $localZone);	}
		$nextDay = (clone $day)->modify('+1 day');
		return [$day->setTimezone($utc)->format('Y-m-d H:i:s'), $nextDay->setTimezone($utc)->format('Y-m-d H:i:s')];
	}

	// The current UTC date & time, optionally moved (e.g. '-30 days'), in the format of the created_at column
	private static function utcNow(string $modify = '') : string
	{
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		return ($modify === '' ? $now : $now->modify($modify))->format('Y-m-d H:i:s');
	}

	// Invalid UTF-8 (e.g. a Latin-1 XML file) would make the database reject the whole event: the invalid bytes are replaced by '?'
	private static function validUtf8(string $text) : string
	{
		if(mb_check_encoding($text, 'UTF-8'))
		{	return $text;	}
		mb_substitute_character(0x3F);
		return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	// Cuts a text at 64 KB without splitting a multi-byte (e.g. accented) character
	private static function cutText(?string $text) : ?string
	{
		if($text === null)
		{	return null;	}
		$text = self::validUtf8($text);
		if(strlen($text) <= self::MAX_TEXT_BYTES)
		{	return $text;	}
		$marker = "\n[... cut, the original was ".strlen($text).' bytes]';
		return mb_strcut($text, 0, self::MAX_TEXT_BYTES - strlen($marker), 'UTF-8').$marker;
	}
}
