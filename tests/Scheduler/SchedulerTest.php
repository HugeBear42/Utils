<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Core\EventLog;
use HugeBear42\Utils\Core\Logger;
use HugeBear42\Utils\Scheduler\Schedule;
use HugeBear42\Utils\Scheduler\Scheduler;


// Real PHP processes: small scripts written in a temporary folder, with the state & the events in the same folder
final class SchedulerTest extends TestCase
{
	private string $dir='';

	protected function setUp(): void
	{
		$this->dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'schedulertest_'.uniqid();
		mkdir($this->dir.DIRECTORY_SEPARATOR.'scripts', 0775, true);
		Logger::configure(['dir'=>$this->dir.DIRECTORY_SEPARATOR.'logs', 'application'=>'SchedulerTest']);
		EventLog::configure(['db'=>['type'=>'sqlite', 'connection'=>['path'=>':memory:']], 'logger'=>false]);
		EventLog::createTable();
	}

	protected function tearDown(): void
	{
		Logger::configure([]);
		$this->removeDir($this->dir);
	}

	private function removeDir(string $dir): void
	{
		foreach(glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $path)
		{	is_dir($path) ? $this->removeDir($path) : @unlink($path);	}
		@rmdir($dir);
	}

	// Writes a script of the test, e.g. $this->script('ok', 'echo "done";')
	private function script(string $name, string $code): string
	{
		file_put_contents($this->dir."/scripts/$name.php", "<?php\n$code\n");
		return "scripts/$name.php";
	}

	private function scheduler(Schedule $schedule, int $now, array $options=[]): Scheduler
	{	return new Scheduler($schedule, $options+['stateDir'=>$this->dir.DIRECTORY_SEPARATOR.'state', 'timezone'=>'Europe/Zurich', 'now'=>$now]);	}

	private function events(): array
	{	return EventLog::listEvents([], 'created_at', 'ASC');	}

	public function testRunsTheDueTasksOnly(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('often', 'echo "often ran";'));
		$schedule->every('1 hour', $this->script('hourly', 'echo "hourly ran";'));
		$start=strtotime('2026-10-27 10:00:00 UTC');

		$first=$this->scheduler($schedule, $start)->run();
		$this->assertSame(['often', 'hourly'], array_keys($first));
		$this->assertSame(Scheduler::OK, $first['often']['status']);
		$this->assertSame(0, $first['often']['exitCode']);
		$this->assertSame('often ran', file_get_contents($this->dir.'/state/often.out.txt'));

		$second=$this->scheduler($schedule, $start+60)->run();		// One minute later: the hourly task is not due
		$this->assertSame(['often'], array_keys($second));
		$this->assertSame([], $this->events());
	}

	public function testTasksRunAtTheSameTime(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('slowA', 'sleep(2);'));
		$schedule->every('1 minute', $this->script('slowB', 'sleep(2);'));
		$started=microtime(true);
		$results=$this->scheduler($schedule, time())->run();
		$this->assertLessThan(3.5, microtime(true)-$started, 'two tasks of 2 s took as long as one after the other');
		$this->assertSame(Scheduler::OK, $results['slowA']['status']);
		$this->assertSame(Scheduler::OK, $results['slowB']['status']);
	}

	public function testFailureIsAnEvent(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('broken', 'fwrite(STDERR, "cannot reach the hot folder\n"); exit(3);'));
		$results=$this->scheduler($schedule, time())->run();

		$this->assertSame(Scheduler::FAILED, $results['broken']['status']);
		$this->assertSame(3, $results['broken']['exitCode']);
		$this->assertSame('cannot reach the hot folder', $results['broken']['output']);
		$events=$this->events();
		$this->assertCount(1, $events);
		$this->assertSame('error', $events[0]['level']);
		$this->assertSame('task_failed', $events[0]['event_type']);
		$this->assertSame('scheduler', $events[0]['source']);
		$this->assertStringContainsString('Task broken failed with exit code 3', $events[0]['message']);
		$this->assertStringContainsString('cannot reach the hot folder', $events[0]['details']);
	}

	public function testRepeatedFailureIsReportedOncePerHour(): void
	{
		$schedule=new Schedule($this->dir);
		$exitCodeFile=$this->dir.'/exitcode.txt';
		$schedule->every('1 minute', $this->script('flaky', 'exit((int)file_get_contents('.var_export($exitCodeFile, true).'));'));
		$start=strtotime('2026-10-27 10:00:00 UTC');
		$runAt=function(int $minutes, int $exitCode) use ($schedule, $start, $exitCodeFile)
		{
			file_put_contents($exitCodeFile, (string)$exitCode);
			$this->scheduler($schedule, $start+$minutes*60)->run();
			return count($this->events());
		};
		$this->assertSame(1, $runAt(0, 1));		// The failure starts: reported
		$this->assertSame(1, $runAt(1, 1));		// The same failure: not again
		$this->assertSame(1, $runAt(59, 1));
		$this->assertSame(2, $runAt(60, 1));	// An hour later: reported again
		$this->assertSame(3, $runAt(61, 2));	// Another exit code: reported at once
		$this->assertSame(3, $runAt(62, 0));	// Works again: only logged
		$this->assertSame(4, $runAt(63, 2));	// Fails again: a new failure
	}

	public function testFatalErrorIsAFailure(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('fatal', 'undefinedFunction();'));
		$results=$this->scheduler($schedule, time())->run();
		$this->assertSame(Scheduler::FAILED, $results['fatal']['status']);
		$this->assertStringContainsString('undefinedFunction', $results['fatal']['output']);
	}

	public function testTimeout(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('hangs', 'sleep(30);'))->timeout(1);
		$started=microtime(true);
		$results=$this->scheduler($schedule, time())->run();
		$this->assertLessThan(10, microtime(true)-$started);
		$this->assertSame(Scheduler::TIMEOUT, $results['hangs']['status']);
		$this->assertSame('task_timeout', $this->events()[0]['event_type']);
	}

	public function testMissingScript(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', 'scripts/doesNotExist.php');
		$results=$this->scheduler($schedule, time())->run();
		$this->assertSame(Scheduler::MISSING, $results['doesNotExist']['status']);
		$this->assertStringContainsString('does not exist', $this->events()[0]['message']);
	}

	public function testRunningTaskIsSkipped(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('long', 'echo "x";'));
		$schedule->every('1 minute', $this->script('other', 'echo "y";'));
		$stateDir=$this->dir.DIRECTORY_SEPARATOR.'state';
		mkdir($stateDir);
		$lock=fopen($stateDir.DIRECTORY_SEPARATOR.'long.lock', 'c');		// As if an earlier run of 'long' were still going
		flock($lock, LOCK_EX);
		try
		{
			$results=$this->scheduler($schedule, time())->run();
			$this->assertSame(Scheduler::RUNNING, $results['long']['status']);
			$this->assertSame(Scheduler::OK, $results['other']['status']);		// The others run as usual
			$this->assertTrue($this->scheduler($schedule, time())->status()['long']['running']);
		}
		finally
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		$this->assertSame([], $this->events());
	}

	public function testArgumentsArePassed(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('args', 'echo implode("|", array_slice($argv, 1));'), ['--days', '30', 'with space']);
		$this->scheduler($schedule, time())->run();
		$this->assertSame('--days|30|with space', file_get_contents($this->dir.'/state/args.out.txt'));
	}

	public function testRunOneTaskNowKeepsItsSchedule(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->daily('02:00', $this->script('nightly', 'echo "ok";'));
		$schedule->every('1 minute', $this->script('often', 'echo "ok";'));
		$now=strtotime('2026-10-27 15:00:00 Europe/Zurich');

		$results=$this->scheduler($schedule, $now)->run('nightly');
		$this->assertSame(['nightly'], array_keys($results));
		$status=$this->scheduler($schedule, $now)->status();
		$this->assertNull($status['nightly']['lastRun']);					// A manual run does not move the schedule
		$this->assertSame(Scheduler::OK, $status['nightly']['lastStatus']);

		$this->expectException(\InvalidArgumentException::class);
		$this->scheduler($schedule, $now)->run('unknown');
	}

	public function testStatus(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('15 minutes', $this->script('quarter', 'exit(0);'));
		$now=strtotime('2026-10-27 10:00:00 UTC');
		$this->assertNull($this->scheduler($schedule, $now)->status()['quarter']['lastStatus']);	// Never run, no state file yet

		$this->scheduler($schedule, $now)->run();
		$status=$this->scheduler($schedule, $now+120)->status()['quarter'];
		$this->assertSame($now, $status['lastRun']);
		$this->assertSame(Scheduler::OK, $status['lastStatus']);
		$this->assertSame(0, $status['lastExitCode']);
		$this->assertSame($now+900, $status['nextRun']);
		$this->assertFalse($status['running']);
	}

	public function testFailuresOnlyLoggedWithoutEvents(): void
	{
		$schedule=new Schedule($this->dir);
		$schedule->every('1 minute', $this->script('broken', 'exit(1);'));
		$this->scheduler($schedule, time(), ['events'=>false])->run();
		$this->assertSame([], $this->events());
		$this->assertStringContainsString('Task broken failed with exit code 1', (string)file_get_contents(Logger::getLogFile()));
	}

	public function testStateDirIsRequired(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new Scheduler(new Schedule($this->dir), []);
	}
}
