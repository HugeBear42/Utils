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

	// --- Windows & days ---

	public function testBetweenRunsOnlyInTheWindow(): void
	{
		$task=(new Schedule('C:/app'))->every('15 minutes', 'a.php')->between('07:30', '21:30');
		$ranLastEvening=$this->at('2026-10-27 21:30');
		$this->assertFalse($task->isDue(null, $this->at('2026-10-28 07:29'), $this->zurich));			// Never run, but outside
		$this->assertFalse($task->isDue($ranLastEvening, $this->at('2026-10-27 23:00'), $this->zurich));
		$this->assertTrue($task->isDue($ranLastEvening, $this->at('2026-10-28 07:30'), $this->zurich));		// Start of the window
		$this->assertSame($this->at('2026-10-28 07:30'), $task->nextRun($ranLastEvening, $this->at('2026-10-27 23:00'), $this->zurich));

		$ranAt2120=$this->at('2026-10-28 21:20');
		$this->assertFalse($task->isDue($ranAt2120, $this->at('2026-10-28 21:34'), $this->zurich));		// 21:35 is outside
		$this->assertSame($this->at('2026-10-29 07:30'), $task->nextRun($ranAt2120, $this->at('2026-10-28 21:25'), $this->zurich));
		$this->assertSame($this->at('2026-10-28 21:30'), $task->nextRun($this->at('2026-10-28 21:15'), $this->at('2026-10-28 21:20'), $this->zurich));
		$this->assertTrue($task->isDue($this->at('2026-10-28 21:15'), $this->at('2026-10-28 21:30'), $this->zurich));	// The end is included
	}

	public function testWindowStartRunsAtOnceWhateverTheInterval(): void
	{
		$task=(new Schedule('C:/app'))->every('12 hours', 'a.php')->between('07:30', '21:30');
		$this->assertTrue($task->isDue($this->at('2026-10-27 21:00'), $this->at('2026-10-28 07:30'), $this->zurich));
	}

	public function testWindowAcrossMidnight(): void
	{
		$task=(new Schedule('C:/app'))->every('1 hour', 'a.php')->between('22:00', '06:00');
		$this->assertTrue($task->isDue(null, $this->at('2026-10-27 23:00'), $this->zurich));
		$this->assertTrue($task->isDue($this->at('2026-10-28 00:00'), $this->at('2026-10-28 01:00'), $this->zurich));
		$this->assertTrue($task->isDue($this->at('2026-10-28 05:00'), $this->at('2026-10-28 06:00'), $this->zurich));
		$this->assertFalse($task->isDue($this->at('2026-10-28 05:00'), $this->at('2026-10-28 06:01'), $this->zurich));
		$this->assertFalse($task->isDue(null, $this->at('2026-10-28 12:00'), $this->zurich));
		$this->assertSame($this->at('2026-10-28 22:00'), $task->nextRun($this->at('2026-10-28 06:00'), $this->at('2026-10-28 12:00'), $this->zurich));
	}

	public function testWeekdays(): void
	{
		// 2026-10-30 is a Friday, 2026-11-02 a Monday
		$task=(new Schedule('C:/app'))->every('15 minutes', 'a.php')->between('07:30', '21:30')->weekdays();
		$ranFridayEvening=$this->at('2026-10-30 21:30');
		$this->assertFalse($task->isDue($ranFridayEvening, $this->at('2026-10-31 10:00'), $this->zurich));	// Saturday
		$this->assertTrue($task->isDue($ranFridayEvening, $this->at('2026-11-02 07:30'), $this->zurich));
		$this->assertSame($this->at('2026-11-02 07:30'), $task->nextRun($ranFridayEvening, $this->at('2026-10-31 10:00'), $this->zurich));
	}

	public function testDaysWithoutWindowIsTheWholeDay(): void
	{
		$task=(new Schedule('C:/app'))->every('1 hour', 'a.php')->weekends();
		$this->assertTrue($task->isDue(null, $this->at('2026-10-31 00:00'), $this->zurich));		// Saturday
		$this->assertTrue($task->isDue(null, $this->at('2026-11-01 23:59'), $this->zurich));		// Sunday
		$this->assertFalse($task->isDue(null, $this->at('2026-11-02 00:00'), $this->zurich));		// Monday
	}

	public function testOvernightWindowBelongsToTheDayItStarts(): void
	{
		$task=(new Schedule('C:/app'))->every('1 hour', 'a.php')->between('22:00', '06:00')->days('fri');
		$this->assertTrue($task->isDue(null, $this->at('2026-10-31 03:00'), $this->zurich));		// Saturday night, Friday's window
		$this->assertFalse($task->isDue(null, $this->at('2026-10-30 03:00'), $this->zurich));		// Friday night, Thursday's window
	}

	public function testDailyOnSomeDays(): void
	{
		$task=(new Schedule('C:/app'))->daily('02:00', 'a.php')->weekdays();
		$ranFriday=$this->at('2026-10-30 02:00');
		$this->assertFalse($task->isDue($ranFriday, $this->at('2026-10-31 02:00'), $this->zurich));		// Saturday
		$this->assertFalse($task->isDue($ranFriday, $this->at('2026-11-01 12:00'), $this->zurich));		// Sunday
		$this->assertTrue($task->isDue($ranFriday, $this->at('2026-11-02 02:00'), $this->zurich));
		$this->assertSame($this->at('2026-11-02 02:00'), $task->nextRun($ranFriday, $this->at('2026-10-31 02:00'), $this->zurich));
	}

	public function testDays(): void
	{
		$schedule=new Schedule('C:/app');
		$this->assertSame('every minute 07:30-21:30 Mon-Fri', $schedule->every('1 minute', 'a.php')->between('07:30', '21:30')->weekdays()->describe());
		$this->assertSame('every hour Mon,Wed', $schedule->every('1 hour', 'b.php')->days('Wednesday', 'mon', 1)->describe());
		$this->assertSame('daily at 02:00 Sat,Sun', $schedule->daily('02:00', 'c.php')->days(7, 'SAT')->describe());
		$this->assertSame('every 2 hours 22:00-06:00', $schedule->every('2 hours', 'd.php')->between('22:00', '06:00')->describe());
		$this->assertSame('every hour Tue-Thu', $schedule->every('1 hour', 'e.php')->days('thu', 'tue', 'wed')->describe());
	}

	public function testInvalidWindowsAndDays(): void
	{
		$schedule=new Schedule('C:/app');
		$invalid=[
			fn() => $schedule->every('1 hour', 'a.php')->between('7:30', '21:30'),
			fn() => $schedule->every('1 hour', 'b.php')->between('08:00', '08:00'),
			fn() => $schedule->daily('02:00', 'c.php')->between('01:00', '03:00'),
			fn() => $schedule->every('1 hour', 'd.php')->days('noday'),
			fn() => $schedule->every('1 hour', 'e.php')->days(8),
			fn() => $schedule->every('1 hour', 'f.php')->days(),
		];
		foreach($invalid as $i => $call)
		{
			try
			{
				$call();
				$this->fail("Case $i was accepted");
			}
			catch(\InvalidArgumentException $e)
			{	$this->assertStringContainsString('Task ', $e->getMessage());	}
		}
	}
}
