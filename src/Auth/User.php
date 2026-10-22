<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Auth;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: One user of an application, as read from the users table (see Users).
	                    Read only: changes go through Users (setPassword(), setRole()...). The password hash is never exposed.
**/

final class User
{
	public function __construct(
		public readonly int $id,
		public readonly string $username,
		public readonly string $role,
		public readonly ?string $language,		// Preferred language of the user interface, e.g. 'de', null = the browser's
		public readonly bool $active,
		public readonly ?string $lastLoginAt,	// UTC, yyyy-mm-dd hh:mm:ss
		public readonly string $createdAt,		// UTC
	)
	{}

	// A user from a row of the users table
	public static function fromRow(array $row) : self
	{
		return new self(
			(int)$row['id'],
			(string)$row['username'],
			(string)$row['role'],
			isset($row['language']) && $row['language'] !== '' ? (string)$row['language'] : null,
			(bool)(int)$row['active'],
			$row['last_login_at'] ?? null,
			(string)$row['created_at'],
		);
	}

	// What the session keeps about the user (see Session::login()), also readable by older code as $_SESSION['user']
	public function toSession() : array
	{	return ['id' => $this->id, 'username' => $this->username, 'role' => $this->role, 'language' => $this->language];	}
}
