<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Auth\LastProtectedUserException;
use HugeBear42\Utils\Auth\LoginResult;
use HugeBear42\Utils\Auth\User;
use HugeBear42\Utils\Auth\Users;


// Every test runs on its own empty SQLite database in memory
final class UsersTest extends TestCase
{
	private const PASSWORD = 'correct horse battery';

	protected function setUp(): void
	{
		Users::configure(['db'=>['type'=>'sqlite', 'connection'=>['path'=>':memory:']],
			'roles'=>['operator', 'administrator'], 'maxFailedLogins'=>3, 'lockMinutes'=>15]);
		Users::createTable();
	}

	// --- Creating & reading ---

	public function testCreateAndFind(): void
	{
		$user=Users::create('  Frank ', self::PASSWORD, 'administrator', 'DE');
		$this->assertInstanceOf(User::class, $user);
		$this->assertSame('frank', $user->username);			// trimmed & lower case
		$this->assertSame('administrator', $user->role);
		$this->assertSame('de', $user->language);
		$this->assertTrue($user->active);
		$this->assertNull($user->lastLoginAt);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $user->createdAt);

		$this->assertEquals($user, Users::find($user->id));
		$this->assertEquals($user, Users::findByUsername('FRANK'));
		$this->assertNull(Users::find(999));
		$this->assertNull(Users::findByUsername('nobody'));
	}

	public function testPasswordIsHashed(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		$hash=Users::database()->query('SELECT password_hash FROM users WHERE id = :id', ['id'=>$user->id])->find()['password_hash'];
		$this->assertNotSame(self::PASSWORD, $hash);
		$this->assertTrue(password_verify(self::PASSWORD, $hash));
		$this->assertArrayNotHasKey('password_hash', $user->toSession());
	}

	public function testEmptyLanguageIsNull(): void
	{
		$this->assertNull(Users::create('anna', self::PASSWORD, 'operator', '')->language);
		$this->assertNull(Users::create('ben', self::PASSWORD, 'operator')->language);
	}

	public function testDuplicateUsernameIsRefused(): void
	{
		Users::create('frank', self::PASSWORD, 'operator');
		$this->expectException(\InvalidArgumentException::class);
		Users::create('Frank', self::PASSWORD, 'operator');
	}

	public function testInvalidUsernameIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::create('frank hubert', self::PASSWORD, 'operator');
	}

	public function testShortPasswordIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::create('frank', 'short', 'operator');
	}

	public function testTooLongPasswordIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::create('frank', str_repeat('x', 73), 'operator');
	}

	public function testUnknownRoleIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::create('frank', self::PASSWORD, 'superuser');
	}

	public function testAnyRoleWithoutRolesList(): void
	{
		Users::configure(['db'=>Users::database()]);
		$this->assertSame('viewer', Users::create('frank', self::PASSWORD, 'viewer')->role);
	}

	public function testAllAndCount(): void
	{
		Users::create('zoe', self::PASSWORD, 'operator');
		Users::create('anna', self::PASSWORD, 'administrator');
		$ben=Users::create('ben', self::PASSWORD, 'administrator');
		Users::setActive($ben->id, false);

		$this->assertSame(['anna', 'ben', 'zoe'], array_map(fn(User $user)=>$user->username, Users::all()));
		$this->assertSame(2, Users::count());
		$this->assertSame(3, Users::count(activeOnly: false));
		$this->assertSame(1, Users::count('administrator'));
		$this->assertSame(2, Users::count('administrator', false));
	}

	// --- Changing ---

	public function testSetters(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator', 'de');
		$this->assertTrue(Users::setRole($user->id, 'administrator'));
		$this->assertTrue(Users::setLanguage($user->id, 'fr'));
		$this->assertTrue(Users::setActive($user->id, false));
		$changed=Users::find($user->id);
		$this->assertSame('administrator', $changed->role);
		$this->assertSame('fr', $changed->language);
		$this->assertFalse($changed->active);

		$this->assertTrue(Users::setLanguage($user->id, null));
		$this->assertNull(Users::find($user->id)->language);
	}

	public function testSettersOfUnknownUser(): void
	{
		$this->assertFalse(Users::setRole(999, 'operator'));
		$this->assertFalse(Users::setPassword(999, self::PASSWORD));
		$this->assertFalse(Users::setActive(999, true));
	}

	public function testSetRoleChecksTheRole(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		$this->expectException(\InvalidArgumentException::class);
		Users::setRole($user->id, 'superuser');
	}

	public function testSetPassword(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		Users::setPassword($user->id, 'a brand new password');
		$this->assertFalse(Users::attemptLogin('frank', self::PASSWORD)->ok());
		$this->assertTrue(Users::attemptLogin('frank', 'a brand new password')->ok());
	}

	public function testDelete(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		$this->assertTrue(Users::delete($user->id));
		$this->assertNull(Users::find($user->id));
		$this->assertFalse(Users::delete($user->id));
		$this->assertSame('frank', Users::create('frank', self::PASSWORD, 'operator')->username);	// The name is free again
	}

	// --- The last administrator ---

	private function protectAdministrators(): void
	{	Users::configure(['db'=>Users::database(), 'roles'=>['operator', 'administrator'], 'protectedRole'=>'administrator']);	}

	public function testLastAdministratorCannotBeDeactivated(): void
	{
		$this->protectAdministrators();
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		try
		{
			Users::setActive($anna->id, false);
			$this->fail('The last administrator was deactivated');
		}
		catch(LastProtectedUserException $e)
		{
			$this->assertSame('anna', $e->username);
			$this->assertSame('administrator', $e->role);
			$this->assertStringContainsString("'anna' is the last active administrator", $e->getMessage());
		}
		$this->assertTrue(Users::find($anna->id)->active);
	}

	public function testLastAdministratorCannotBeDeleted(): void
	{
		$this->protectAdministrators();
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		$this->expectException(LastProtectedUserException::class);
		Users::delete($anna->id);
	}

	public function testLastAdministratorCannotChangeRole(): void
	{
		$this->protectAdministrators();
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		$this->assertTrue(Users::setRole($anna->id, 'administrator'));		// The same role: allowed
		$this->expectException(LastProtectedUserException::class);
		Users::setRole($anna->id, 'operator');
	}

	public function testAdministratorCanGoWhenAnotherRemains(): void
	{
		$this->protectAdministrators();
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		$ben=Users::create('ben', self::PASSWORD, 'administrator');
		$this->assertTrue(Users::setRole($anna->id, 'operator'));
		$this->expectException(LastProtectedUserException::class);		// Now ben is the last one
		Users::setActive($ben->id, false);
	}

	public function testInactiveAdministratorDoesNotCount(): void
	{
		$this->protectAdministrators();
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		$ben=Users::create('ben', self::PASSWORD, 'administrator');
		Users::setActive($ben->id, false);
		$this->assertTrue(Users::delete($ben->id));		// Inactive: not protected
		$this->expectException(LastProtectedUserException::class);
		Users::delete($anna->id);
	}

	public function testOperatorsAreNotProtected(): void
	{
		$this->protectAdministrators();
		$zoe=Users::create('zoe', self::PASSWORD, 'operator');
		$this->assertTrue(Users::setActive($zoe->id, false));
		$this->assertTrue(Users::delete($zoe->id));
	}

	public function testNoProtectionWithoutProtectedRole(): void
	{
		$anna=Users::create('anna', self::PASSWORD, 'administrator');
		$this->assertTrue(Users::delete($anna->id));
	}

	public function testProtectedRoleMustBeARole(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::configure(['db'=>Users::database(), 'roles'=>['operator', 'administrator'], 'protectedRole'=>'admin']);
	}

	// --- Logging in ---

	public function testLoginOk(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		$result=Users::attemptLogin('Frank', self::PASSWORD);
		$this->assertTrue($result->ok());
		$this->assertSame(LoginResult::OK, $result->status);
		$this->assertSame($user->id, $result->user->id);
		$this->assertNotNull($result->user->lastLoginAt);
	}

	public function testLoginWrongPasswordAndUnknownUserLookTheSame(): void
	{
		Users::create('frank', self::PASSWORD, 'operator');
		$wrong=Users::attemptLogin('frank', 'wrong password');
		$unknown=Users::attemptLogin('nobody', self::PASSWORD);
		$this->assertSame(LoginResult::INVALID, $wrong->status);
		$this->assertSame(LoginResult::INVALID, $unknown->status);
		$this->assertNull($wrong->user);
	}

	public function testLoginInactive(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		Users::setActive($user->id, false);
		$this->assertSame(LoginResult::INACTIVE, Users::attemptLogin('frank', self::PASSWORD)->status);
		$this->assertSame(LoginResult::INVALID, Users::attemptLogin('frank', 'wrong password')->status);	// No hint without the password
	}

	public function testLockAfterTooManyFailures(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		$this->assertSame(LoginResult::INVALID, Users::attemptLogin('frank', 'wrong 1')->status);
		$this->assertSame(LoginResult::INVALID, Users::attemptLogin('frank', 'wrong 2')->status);
		$third=Users::attemptLogin('frank', 'wrong 3');
		$this->assertSame(LoginResult::LOCKED, $third->status);
		$this->assertTrue($third->lockedNow);
		$this->assertNotNull($third->lockedUntil);
		$this->assertSame($third->lockedUntil, Users::lockedUntil($user->id));

		$locked=Users::attemptLogin('frank', self::PASSWORD);		// Even the right password, while locked
		$this->assertSame(LoginResult::LOCKED, $locked->status);
		$this->assertFalse($locked->lockedNow);
	}

	public function testSuccessResetsTheFailures(): void
	{
		Users::create('frank', self::PASSWORD, 'operator');
		Users::attemptLogin('frank', 'wrong 1');
		Users::attemptLogin('frank', 'wrong 2');
		$this->assertTrue(Users::attemptLogin('frank', self::PASSWORD)->ok());
		$this->assertSame(LoginResult::INVALID, Users::attemptLogin('frank', 'wrong 3')->status);	// Counting starts again
	}

	public function testLockExpires(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		for($n=1; $n<=3; $n++)
		{	Users::attemptLogin('frank', "wrong $n");	}
		Users::database()->query("UPDATE users SET locked_until = '2000-01-01 00:00:00' WHERE id = :id", ['id'=>$user->id]);
		$this->assertNull(Users::lockedUntil($user->id));
		$this->assertTrue(Users::attemptLogin('frank', self::PASSWORD)->ok());
	}

	public function testUnlockAndNewPasswordEndTheLock(): void
	{
		$user=Users::create('frank', self::PASSWORD, 'operator');
		for($n=1; $n<=3; $n++)
		{	Users::attemptLogin('frank', "wrong $n");	}
		Users::unlock($user->id);
		$this->assertTrue(Users::attemptLogin('frank', self::PASSWORD)->ok());

		for($n=1; $n<=3; $n++)
		{	Users::attemptLogin('frank', "wrong $n");	}
		Users::setPassword($user->id, 'a brand new password');
		$this->assertTrue(Users::attemptLogin('frank', 'a brand new password')->ok());
	}

	// --- The table ---

	public function testSchemaForEveryDatabase(): void
	{
		foreach(['mysql', 'sqlsrv', 'sqlite'] as $type)
		{
			$statements=Users::schema($type, 'app_users');
			$this->assertNotEmpty($statements);
			$this->assertStringContainsString('app_users', $statements[0]);
		}
		$this->expectException(\InvalidArgumentException::class);
		Users::schema('oracle');
	}

	public function testInvalidTableNameIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Users::configure(['db'=>Users::database(), 'table'=>'users; DROP TABLE users']);
	}

	public function testCreateTableTwiceIsHarmless(): void
	{
		Users::create('frank', self::PASSWORD, 'operator');
		Users::createTable();
		$this->assertSame(1, Users::count());
	}
}
