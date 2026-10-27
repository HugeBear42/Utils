<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Scheduler;
/**
	2026-10-27	v1.00	frank.a.hubert@gmail.com: One PHP script of a Schedule & when it is due.
	2026-10-27	v1.01	frank.a.hubert@gmail.com: between() time windows & days() / weekdays() / weekends().

	- every('5 minutes', ...): due when the interval has passed since its last run, in whole minutes (the scheduler runs once a minute)
	- daily('02:00', ...): due once a day at that local time (Scheduler 'timezone'). If the scheduler was not running then
	  (server off), the run is caught up at the next minute: a daily task never silently skips a day
	- between('07:30', '21:30'): an every() task only runs in that window, both times included. At the start of the window
	  it runs at once, then every interval. A window may cross midnight ('22:00', '06:00'): it belongs to the day it starts
	- days('mon', 'wed', 'fri'), weekdays() (Monday to Friday), weekends(): the days an every() or a daily() task runs
	- timeout(): seconds before a run is stopped (default: Scheduler 'timeout')
**/

final class Task
{
	private const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

	private ?int $timeout = null;
	private ?string $windowFrom = null;		// 'HH:MM', null = the whole day
	private ?string $windowTo = null;
	private array $days = [];				// ISO day numbers, 1 = Monday ... 7 = Sunday, [] = every day

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
	 * Only runs from $from to $to ('HH:MM', local time, both included), e.g. between('07:30', '21:30').
	 * $to before $from crosses midnight: between('22:00', '06:00'). Only for every() tasks.
	 */
	public function between(string $from, string $to) : self
	{
		if($this->interval === null)
		{	throw new \InvalidArgumentException("Task {$this->name}: between() is for every() tasks, a daily() task already has its time");	}
		foreach([$from, $to] as $time)
		{
			if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))
			{	throw new \InvalidArgumentException("Task {$this->name}: invalid time '$time', expected HH:MM (00:00 to 23:59)");	}
		}
		if($from === $to)
		{	throw new \InvalidArgumentException("Task {$this->name}: the window $from-$to is empty");	}
		$this->windowFrom = $from;
		$this->windowTo = $to;
		return $this;
	}

	/**
	 * Only runs on these days: 'mon', 'Tuesday', ... or 1 (Monday) to 7 (Sunday).
	 * A window across midnight belongs to the day it starts: Friday 22:00 to Saturday 06:00 is a Friday.
	 */
	public function days(string|int ...$days) : self
	{
		$numbers = [];
		foreach($days as $day)
		{
			$number = is_int($day) ? $day : null;
			if(is_string($day) && preg_match('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*$/i', trim($day), $m))
			{	$number = array_search(ucfirst(strtolower($m[1])), self::DAY_NAMES, true);	}
			if($number === null || $number < 1 || $number > 7)
			{	throw new \InvalidArgumentException("Task {$this->name}: invalid day '$day', expected e.g. 'mon', 'Tuesday' or 1 (Monday) to 7 (Sunday)");	}
			$numbers[$number] = $number;
		}
		if($numbers === [])
		{	throw new \InvalidArgumentException("Task {$this->name}: days() needs at least one day");	}
		ksort($numbers);
		$this->days = array_values($numbers);
		return $this;
	}

	// Monday to Friday
	public function weekdays() : self
	{	return $this->days(1, 2, 3, 4, 5);	}

	// Saturday & Sunday
	public function weekends() : self
	{	return $this->days(6, 7);	}

	/**
	 * True if the task should run at $tick (a Unix time at the start of a minute), given its last run ($lastRun, a tick, null = never).
	 * $timezone: the time zone of the daily times & windows.
	 */
	public function isDue(?int $lastRun, int $tick, \DateTimeZone $timezone) : bool
	{
		if($this->dailyTime !== null)
		{	return $lastRun === null || $lastRun < $this->lastDailyTime($tick, $timezone);	}
		if(!$this->hasWindow())
		{	return $lastRun === null || $tick - $lastRun >= $this->interval;	}
		$windowStart = $this->currentWindowStart($tick, $timezone);
		if($windowStart === null)
		{	return false;	}
		return $lastRun === null || $lastRun < $windowStart || $tick - $lastRun >= $this->interval;
	}

	// When the task will run next, as a Unix time: $tick if it is due now
	public function nextRun(?int $lastRun, int $tick, \DateTimeZone $timezone) : int
	{
		if($this->isDue($lastRun, $tick, $timezone))
		{	return $tick;	}
		if($this->dailyTime !== null)
		{	return $this->nextDailyTime($tick, $timezone);	}
		if(!$this->hasWindow())
		{	return $lastRun + $this->interval;	}
		$windowStart = $this->currentWindowStart($tick, $timezone);
		if($windowStart !== null && $lastRun + $this->interval <= $this->windowEnd($windowStart, $timezone))
		{	return $lastRun + $this->interval;	}
		return $this->nextWindowStart($tick, $timezone);
	}

	// What the schedule says, e.g. 'every 5 minutes', 'every minute 07:30-21:30 Mon-Fri' or 'daily at 02:00'
	public function describe() : string
	{
		if($this->interval === null)
		{	$text = "daily at {$this->dailyTime}";	}
		else
		{
			$minutes = intdiv($this->interval, 60);
			$text = $minutes % 60 === 0
				? 'every '.($minutes === 60 ? 'hour' : ($minutes / 60).' hours')
				: 'every '.($minutes === 1 ? 'minute' : "$minutes minutes");
		}
		if($this->windowFrom !== null)
		{	$text .= " {$this->windowFrom}-{$this->windowTo}";	}
		if($this->days !== [])
		{	$text .= ' '.$this->describeDays();	}
		return $text;
	}

	// 'Mon-Fri' for 3 days or more in a row, else 'Mon,Wed'
	private function describeDays() : string
	{
		$count = count($this->days);
		if($count >= 3 && end($this->days) - $this->days[0] === $count - 1)
		{	return self::DAY_NAMES[$this->days[0]].'-'.self::DAY_NAMES[end($this->days)];	}
		return implode(',', array_map(fn(int $day) => self::DAY_NAMES[$day], $this->days));
	}

	private function hasWindow() : bool
	{	return $this->windowFrom !== null || $this->days !== [];	}

	private function isAllowedDay(\DateTimeImmutable $date) : bool
	{	return $this->days === [] || in_array((int)$date->format('N'), $this->days, true);	}

	// The window is the whole day when only days() was given
	private function windowTimes() : array
	{	return [$this->windowFrom ?? '00:00', $this->windowTo ?? '23:59'];	}

	// The start of the window $tick is in, as a Unix time, null if $tick is outside every window.
	// A window that crosses midnight may have started the day before
	private function currentWindowStart(int $tick, \DateTimeZone $timezone) : ?int
	{
		[$from] = $this->windowTimes();
		$today = (new \DateTimeImmutable('@'.$tick))->setTimezone($timezone);
		foreach([0, 1] as $daysBack)
		{
			$start = $this->atTime($today->modify("-$daysBack day"), $from);
			if($start->getTimestamp() <= $tick && $tick <= $this->windowEnd($start->getTimestamp(), $timezone) && $this->isAllowedDay($start))
			{	return $start->getTimestamp();	}
		}
		return null;
	}

	// The end of the window starting at $windowStart, as a Unix time (the last minute it runs)
	private function windowEnd(int $windowStart, \DateTimeZone $timezone) : int
	{
		[$from, $to] = $this->windowTimes();
		$start = (new \DateTimeImmutable('@'.$windowStart))->setTimezone($timezone);
		return $this->atTime($to <= $from ? $start->modify('+1 day') : $start, $to)->getTimestamp();
	}

	// The start of the first window after $tick, as a Unix time
	private function nextWindowStart(int $tick, \DateTimeZone $timezone) : int
	{
		[$from] = $this->windowTimes();
		$today = (new \DateTimeImmutable('@'.$tick))->setTimezone($timezone);
		for($daysAhead = 0; $daysAhead <= 7; $daysAhead++)
		{
			$start = $this->atTime($today->modify("+$daysAhead day"), $from);
			if($start->getTimestamp() > $tick && $this->isAllowedDay($start))
			{	return $start->getTimestamp();	}
		}
		throw new \LogicException("Task {$this->name}: no window in the next 7 days");		// Cannot happen: days() needs a day
	}

	// The most recent time the daily time was reached on an allowed day, at or before $tick, as a Unix time.
	// Computed on the local date, so the summer time changes are handled (a 02:30 that does not exist becomes 03:30)
	private function lastDailyTime(int $tick, \DateTimeZone $timezone) : int
	{
		$today = (new \DateTimeImmutable('@'.$tick))->setTimezone($timezone);
		for($daysBack = 0; $daysBack <= 7; $daysBack++)
		{
			$time = $this->atTime($today->modify("-$daysBack day"), (string)$this->dailyTime);
			if($time->getTimestamp() <= $tick && $this->isAllowedDay($time))
			{	return $time->getTimestamp();	}
		}
		throw new \LogicException("Task {$this->name}: no daily time in the last 7 days");
	}

	// The first daily time on an allowed day after $tick, as a Unix time
	private function nextDailyTime(int $tick, \DateTimeZone $timezone) : int
	{
		$today = (new \DateTimeImmutable('@'.$tick))->setTimezone($timezone);
		for($daysAhead = 0; $daysAhead <= 7; $daysAhead++)
		{
			$time = $this->atTime($today->modify("+$daysAhead day"), (string)$this->dailyTime);
			if($time->getTimestamp() > $tick && $this->isAllowedDay($time))
			{	return $time->getTimestamp();	}
		}
		throw new \LogicException("Task {$this->name}: no daily time in the next 7 days");
	}

	private function atTime(\DateTimeImmutable $date, string $time) : \DateTimeImmutable
	{
		[$hour, $minute] = array_map('intval', explode(':', $time));
		return $date->setTime($hour, $minute);
	}
}
