<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Scheduler;
/**
	2026-10-27	v1.00	frank.a.hubert@gmail.com: One PHP script of a Schedule & when it is due.

	- every('5 minutes', ...): due when the interval has passed since its last run, in whole minutes (the scheduler runs once a minute)
	- daily('02:00', ...): due once a day at that local time (Scheduler 'timezone'). If the scheduler was not running then
	  (server off), the run is caught up at the next minute: a daily task never silently skips a day
	- timeout(): seconds before a run is stopped (default: Scheduler 'timeout')
**/

final class Task
{
	private ?int $timeout = null;

	/**
	 * Use Schedule::every() & Schedule::daily() rather than this constructor.
	 * $interval: seconds, a multiple of 60 (every) or null (daily); $dailyTime: 'HH:MM' (daily) or null (every)
	 */
	public function __construct(
		public readonly string $name,			// Unique in its Schedule, used for the lock & output files
		public readonly string $script,			// Full path of the PHP script
		public readonly array $arguments,		// Command line arguments of the script
		public readonly ?int $interval,
		public readonly ?string $dailyTime,
	)
	{}

	// Stops a run after $seconds, e.g. an export that hangs on a network folder
	public function timeout(int $seconds) : self
	{
		if($seconds < 1)
		{	throw new \InvalidArgumentException("Task {$this->name}: the timeout must be at least 1 second, $seconds given");	}
		$this->timeout = $seconds;
		return $this;
	}

	public function getTimeout() : ?int
	{	return $this->timeout;	}

	/**
	 * True if the task should run at $tick (a Unix time at the start of a minute), given its last run ($lastRun, a tick, null = never).
	 * $timezone: the time zone of the daily times.
	 */
	public function isDue(?int $lastRun, int $tick, \DateTimeZone $timezone) : bool
	{
		if($lastRun === null)
		{	return true;	}
		if($this->interval !== null)
		{	return $tick - $lastRun >= $this->interval;	}
		return $lastRun < $this->lastDailyTime($tick, $timezone);
	}

	// When the task will run next, as a Unix time: $tick if it is due now
	public function nextRun(?int $lastRun, int $tick, \DateTimeZone $timezone) : int
	{
		if($this->isDue($lastRun, $tick, $timezone))
		{	return $tick;	}
		if($this->interval !== null)
		{	return $lastRun + $this->interval;	}
		[$hour, $minute] = array_map('intval', explode(':', (string)$this->dailyTime));
		return (new \DateTimeImmutable('@'.$this->lastDailyTime($tick, $timezone)))->setTimezone($timezone)
			->modify('+1 day')->setTime($hour, $minute)->getTimestamp();
	}

	// What the schedule says, e.g. 'every 5 minutes' or 'daily at 02:00'
	public function describe() : string
	{
		if($this->interval === null)
		{	return "daily at {$this->dailyTime}";	}
		$minutes = intdiv($this->interval, 60);
		return $minutes % 60 === 0
			? 'every '.($minutes === 60 ? 'hour' : ($minutes / 60).' hours')
			: 'every '.($minutes === 1 ? 'minute' : "$minutes minutes");
	}

	// The most recent time the daily time was reached, at or before $tick, as a Unix time.
	// Computed on the local date, so the summer time changes are handled (a 02:30 that does not exist becomes 03:30)
	private function lastDailyTime(int $tick, \DateTimeZone $timezone) : int
	{
		[$hour, $minute] = array_map('intval', explode(':', (string)$this->dailyTime));
		$time = (new \DateTimeImmutable('@'.$tick))->setTimezone($timezone)->setTime($hour, $minute);
		if($time->getTimestamp() > $tick)
		{	$time = $time->modify('-1 day')->setTime($hour, $minute);	}
		return $time->getTimestamp();
	}
}
