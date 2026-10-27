<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Scheduler\Schedule;
use HugeBear42\Utils\Scheduler\Task;


// The schedule & the due times, no process is started
final class ScheduleTest extends TestCase
{
	private \DateTimeZone $zurich;

	protected function setUp(): void
	{	$this->zurich=new \DateTimeZone('Europe/Zurich');	}

	// A Unix time from a local date & time in Zurich
	private function at(string $local): int
	{	return (new \DateTimeImmutable($local, $this->zurich))->getTimestamp();	}

	// --- Schedule ---

	public function testEveryAndDaily(): void
	{
		$schedule=new Schedule('C:/app/src');
		$export=$schedule->every('1 minute', 'CLI/exportPendingJobs.php');
		$cleanup=$schedule->daily('02:00', 'CLI/cleanupEvents.php', ['--quiet'])->timeout(600);

		$this->assertSame(['exportPendingJobs', 'cleanupEvents'], array_keys($schedule->tasks()));
		$this->assertSame('C:/app/src'.DIRECTORY_SEPARATOR.'CLI/exportPendingJobs.php', $export->script);
		$this->assertSame(60, $export->interval);
		$this->assertNull($export->getTimeout());
		$this->assertSame('02:00', $cleanup->dailyTime);
		$this->assertSame(['--quiet'], $cleanup->arguments);
		$this->assertSame(600, $cleanup->getTimeout());
		$this->assertSame($cleanup, $schedule->task('cleanupEvents'));
		$this->assertNull($schedule->task('nothing'));
	}

	public function testAbsoluteScriptPath(): void
	{
		$schedule=new Schedule('C:/app/src');
		$this->assertSame('D:/tools/report.php', $schedule->every('1 hour', 'D:/tools/report.php')->script);
	}

	public function testIntervals(): void
	{
		$this->assertSame(60, Schedule::parseInterval('1 minute'));
		$this->assertSame(900, Schedule::parseInterval('15 minutes'));
		$this->assertSame(300, Schedule::parseInterval('5 min'));
		$this->assertSame(3600, Schedule::parseInterval('1 hour'));
		$this->assertSame(7200, Schedule::parseInterval('2 Hours'));
		$this->assertSame(10800, Schedule::parseInterval('3h'));
	}

	public function testInvalidIntervals(): void
	{
		foreach(['0 minutes', '30 seconds', 'every minute', '', '1 day'] as $interval)
		{
			try
			{
				Schedule::parseInterval($interval);
				$this->fail("'$interval' was accepted");
			}
			catch(\InvalidArgumentException $e)
			{	$this->assertStringContainsString('invalid interval', $e->getMessage());	}
		}
	}

	public function testInvalidDailyTime(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new Schedule('C:/app'))->daily('24:00', 'x.php');
	}

	public function testSameScriptTwiceNeedsAName(): void
	{
		$schedule=new Schedule('C:/app');
		$schedule->every('1 hour', 'report.php');
		$this->assertSame('reportNight', $schedule->daily('03:00', 'report.php', ['--full'], 'reportNight')->name);
		$this->expectException(\InvalidArgumentException::class);
		$schedule->daily('04:00', 'report.php');
	}

	public function testInvalidTimeout(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new Schedule('C:/app'))->every('1 minute', 'x.php')->timeout(0);
	}

	public function testDescribe(): void
	{
		$schedule=new Schedule('C:/app');
		$this->assertSame('every minute', $schedule->every('1 minute', 'a.php')->describe());
		$this->assertSame('every 15 minutes', $schedule->every('15 minutes', 'b.php')->describe());
		$this->assertSame('every hour', $schedule->every('60 minutes', 'c.php')->describe());
		$this->assertSame('every 2 hours', $schedule->every('2 hours', 'd.php')->describe());
		$this->assertSame('daily at 02:00', $schedule->daily('02:00', 'e.php')->describe());
	}

	// --- Due times ---

	public function testNeverRunIsDue(): void
	{
		$schedule=new Schedule('C:/app');
		$tick=$this->at('2026-10-27 15:00');
		$this->assertTrue($schedule->every('1 hour', 'a.php')->isDue(null, $tick, $this->zurich));
		$this->assertTrue($schedule->daily('02:00', 'b.php')->isDue(null, $tick, $this->zurich));
	}

	public function testEveryIsDueAfterItsInterval(): void
	{
		$task=(new Schedule('C:/app'))->every('15 minutes', 'a.php');
		$last=$this->at('2026-10-27 10:00');
		$this->assertFalse($task->isDue($last, $this->at('2026-10-27 10:14'), $this->zurich));
		$this->assertTrue($task->isDue($last, $this->at('2026-10-27 10:15'), $this->zurich));
		$this->assertTrue($task->isDue($last, $this->at('2026-10-27 11:40'), $this->zurich));		// Late: runs at once
		$this->assertSame($this->at('2026-10-27 10:15'), $task->nextRun($last, $this->at('2026-10-27 10:05'), $this->zurich));
	}

	public function testDailyIsDueOncePerDay(): void
	{
		$task=(new Schedule('C:/app'))->daily('02:00', 'a.php');
		$ranLastNight=$this->at('2026-10-27 02:00');
		$this->assertFalse($task->isDue($ranLastNight, $this->at('2026-10-27 15:00'), $this->zurich));
		$this->assertFalse($task->isDue($ranLastNight, $this->at('2026-10-28 01:59'), $this->zurich));
		$this->assertTrue($task->isDue($ranLastNight, $this->at('2026-10-28 02:00'), $this->zurich));
		$this->assertSame($this->at('2026-10-28 02:00'), $task->nextRun($ranLastNight, $this->at('2026-10-27 15:00'), $this->zurich));
	}

	public function testDailyMissedRunIsCaughtUp(): void
	{
		$task=(new Schedule('C:/app'))->daily('02:00', 'a.php');
		$ranTwoDaysAgo=$this->at('2026-10-25 02:00');		// The server was off on the 26th at 02:00
		$this->assertTrue($task->isDue($ranTwoDaysAgo, $this->at('2026-10-26 08:30'), $this->zurich));
	}

	public function testDailyAcrossTheSummerTimeChange(): void
	{
		// 2026-03-29: the clocks go from 02:00 to 03:00 in Zurich, 02:30 does not exist that day
		$task=(new Schedule('C:/app'))->daily('02:30', 'a.php');
		$ranTheDayBefore=$this->at('2026-03-28 02:30');
		$this->assertFalse($task->isDue($ranTheDayBefore, $this->at('2026-03-29 01:59'), $this->zurich));
		$this->assertTrue($task->isDue($ranTheDayBefore, $this->at('2026-03-29 03:30'), $this->zurich));
	}
}
