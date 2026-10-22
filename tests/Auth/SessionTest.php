<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Auth\Session;
use HugeBear42\Utils\Auth\User;
use HugeBear42\Utils\Middleware\Middleware;


// The PHP session is not started in the tests: Session works on $_SESSION, which every test empties
final class SessionTest extends TestCase
{
	protected function setUp(): void
	{
		$_SESSION=[];
		Session::configure(['idleMinutes'=>60, 'permissions'=>[
			'operator'      => ['jobs.view', 'jobs.manage', 'events.view', 'events.acknowledge'],
			'administrator' => ['*'],
		]]);
		// deny() would end the script: it throws instead, with the status as the code
		Middleware::$onDeny=function(int $status, string $message, ?string $redirect)
		{	throw new \RuntimeException($redirect ?? $message, $status);	};
	}

	protected function tearDown(): void
	{
		$_SESSION=[];
		Middleware::$onDeny=null;
	}

	private function user(string $role='operator', ?string $language='de'): User
	{	return new User(7, 'frank', $role, $language, true, null, '2026-10-22 08:00:00');	}

	// --- Login & logout ---

	public function testNobodyLoggedIn(): void
	{
		$this->assertFalse(Session::check());
		$this->assertNull(Session::user());
		$this->assertNull(Session::id());
		$this->assertNull(Session::username());
		$this->assertFalse(Session::can('jobs.view'));
	}

	public function testLogin(): void
	{
		Session::login($this->user());
		$this->assertTrue(Session::check());
		$this->assertSame(7, Session::id());
		$this->assertSame('frank', Session::username());
		$this->assertSame('operator', Session::role());
		$this->assertSame('de', Session::language());
		$this->assertSame(['id'=>7, 'username'=>'frank', 'role'=>'operator', 'language'=>'de'], $_SESSION['user']);
	}

	public function testLogout(): void
	{
		Session::login($this->user());
		$token=Session::csrfToken();
		Session::logout();
		$this->assertFalse(Session::check());
		$this->assertFalse(Session::validCsrf($token));
	}

	public function testSetLanguage(): void
	{
		Session::setLanguage('fr');		// Nobody logged in: nothing happens
		$this->assertNull(Session::language());
		Session::login($this->user());
		Session::setLanguage('fr');
		$this->assertSame('fr', Session::language());
	}

	// --- Idle timeout ---

	public function testIdleTimeout(): void
	{
		Session::login($this->user());
		$start=time();
		Session::checkIdle($start+59*60);		// 59 minutes later: still logged in
		$this->assertTrue(Session::check());
		$this->assertFalse(Session::expired());
		Session::checkIdle($start+59*60+61*60);	// 61 minutes after the last request: logged out
		$this->assertFalse(Session::check());
		$this->assertTrue(Session::expired());
	}

	public function testIdleTimeoutIsConfigurable(): void
	{
		Session::configure(['idleMinutes'=>5]);
		Session::login($this->user());
		Session::checkIdle(time()+6*60);
		$this->assertFalse(Session::check());
	}

	public function testNoTimeoutWithoutUser(): void
	{
		Session::checkIdle(time()+24*3600);
		$this->assertFalse(Session::expired());
	}

	// --- Permissions ---

	public function testOperatorPermissions(): void
	{
		Session::login($this->user('operator'));
		$this->assertTrue(Session::can('jobs.manage'));
		$this->assertTrue(Session::can('events.acknowledge'));
		$this->assertFalse(Session::can('config.edit'));
		$this->assertFalse(Session::can('users.manage'));
	}

	public function testAdministratorHasEveryPermission(): void
	{
		Session::login($this->user('administrator'));
		$this->assertTrue(Session::can('jobs.manage'));
		$this->assertTrue(Session::can('config.edit'));
		$this->assertTrue(Session::can('users.manage'));
	}

	public function testUnknownRoleHasNoPermission(): void
	{
		Session::login($this->user('viewer'));
		$this->assertFalse(Session::can('jobs.view'));
		$this->assertTrue(Session::roleCan('operator', 'jobs.view'));
		$this->assertFalse(Session::roleCan('viewer', 'jobs.view'));
	}

	// --- CSRF ---

	public function testCsrfToken(): void
	{
		$token=Session::csrfToken();
		$this->assertSame(64, strlen($token));
		$this->assertSame($token, Session::csrfToken());		// The same for the whole session
		$this->assertTrue(Session::validCsrf($token));
		$this->assertFalse(Session::validCsrf('forged'));
		$this->assertFalse(Session::validCsrf(''));
		$this->assertFalse(Session::validCsrf(null));
		$this->assertStringContainsString('name="_csrf" value="'.$token.'"', Session::csrfField());
	}

	public function testLoginChangesTheCsrfToken(): void
	{
		$before=Session::csrfToken();
		Session::login($this->user());
		$this->assertFalse(Session::validCsrf($before));
	}

	public function testNoTokenNoMatch(): void
	{	$this->assertFalse(Session::validCsrf('anything'));	}

	// --- Middleware ---

	public function testAuthSendsToTheLoginPage(): void
	{
		$this->expectExceptionCode(401);
		$this->expectExceptionMessage('/login');
		Middleware::resolve('auth');
	}

	public function testAuthLetsALoggedInUserThrough(): void
	{
		Session::login($this->user());
		Middleware::resolve('auth');
		$this->assertTrue(true);
	}

	public function testCanLetsThrough(): void
	{
		Session::login($this->user('operator'));
		Middleware::resolve('can:jobs.manage');
		$this->assertTrue(true);
	}

	public function testCanRefusesWithoutThePermission(): void
	{
		Session::login($this->user('operator'));
		$this->expectExceptionCode(403);
		Middleware::resolve('can:config.edit');
	}

	public function testCanNeedsALogin(): void
	{
		$this->expectExceptionCode(401);
		Middleware::resolve('can:jobs.view');
	}

	public function testCanWithoutPermissionIsAnError(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage("'can' needs a permission");
		Middleware::resolve('can');
	}

	public function testUnknownMiddlewareIsAnError(): void
	{
		$this->expectException(\Exception::class);
		Middleware::resolve('admin');
	}

	public function testGuestLetsAVisitorThrough(): void
	{
		Middleware::resolve('guest');		// Nobody logged in: let through
		$this->assertTrue(true);
	}

	public function testWantsJson(): void
	{
		$saved=$_SERVER;
		unset($_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_REQUESTED_WITH']);
		$this->assertFalse(Middleware::wantsJson());
		$_SERVER['HTTP_ACCEPT']='application/json';
		$this->assertTrue(Middleware::wantsJson());
		$_SERVER=$saved;
	}
}
