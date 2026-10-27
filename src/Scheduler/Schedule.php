<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Scheduler;
/**
	2026-10-27	v1.00	frank.a.hubert@gmail.com: The list of the PHP scripts an application runs on a schedule, run by Scheduler.
	                    Written like the routes of the Router, usually in a file of the application (e.g. src/schedule.php):

		$schedule = new Schedule(__DIR__);                                          // The script paths are relative to this folder
		$schedule->every('1 minute', 'CLI/exportPendingJobs.php');
		$schedule->every('2 hours', 'CLI/report.php', ['--short'])->timeout(600);  // With arguments & its own time limit
		$schedule->daily('02:00', 'CLI/cleanupEvents.php');

	- The intervals are whole minutes: '1 minute', '15 minutes', '1 hour', '6 hours'
	- A task is named after its script ('exportPendingJobs'), or by the last argument of every()/daily() when one script
	  is scheduled twice. The name must be unique: it names the lock & output files & the events
**/

final class Schedule
{
	private array $tasks = [];		// name => Task

	public function __construct(public readonly string $baseDir)
	{}

	/**
	 * Runs $script each time $interval has passed, e.g. '5 minutes' or '1 hour'.
	 * @throws \InvalidArgumentException for an invalid interval or a name already used
	 */
	public function every(string $interval, string $script, array $arguments = [], ?string $name = null) : Task
	{	return $this->add(new Task($this->taskName($script, $name), $this->path($script), $arguments, self::parseInterval($interval), null));	}

	/**
	 * Runs $script once a day at $time ('HH:MM', local time of the Scheduler).
	 * @throws \InvalidArgumentException for an invalid time or a name already used
	 */
	public function daily(string $time, string $script, array $arguments = [], ?string $name = null) : Task
	{
		if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time))
		{	throw new \InvalidArgumentException("Schedule: invalid daily time '$time', expected HH:MM (00:00 to 23:59)");	}
		return $this->add(new Task($this->taskName($script, $name), $this->path($script), $arguments, null, $time));
	}

	// The tasks, name => Task, in the order they were added
	public function tasks() : array
	{	return $this->tasks;	}

	public function task(string $name) : ?Task
	{	return $this->tasks[$name] ?? null;	}

	// '1 minute', '15 minutes', '1 hour', '2 hours' (also min, mins, h) as seconds
	public static function parseInterval(string $interval) : int
	{
		if(!preg_match('/^\s*(\d+)\s*(minutes?|mins?|hours?|h)\s*$/i', $interval, $m) || (int)$m[1] < 1)
		{	throw new \InvalidArgumentException("Schedule: invalid interval '$interval', expected e.g. '1 minute', '15 minutes' or '2 hours'");	}
		return (int)$m[1] * (str_starts_with(strtolower($m[2]), 'h') ? 3600 : 60);
	}

	private function add(Task $task) : Task
	{
		if(isset($this->tasks[$task->name]))
		{	throw new \InvalidArgumentException("Schedule: the task name '{$task->name}' is used twice, give one of them another name (last argument)");	}
		$this->tasks[$task->name] = $task;
		return $task;
	}

	private function taskName(string $script, ?string $name) : string
	{
		$name ??= pathinfo($script, PATHINFO_FILENAME);
		if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name))		// Part of the lock & output file names
		{	throw new \InvalidArgumentException("Schedule: invalid task name '$name': 1 to 64 letters, digits, _ or -");	}
		return $name;
	}

	private function path(string $script) : string
	{
		$isAbsolute = preg_match('#^([A-Za-z]:)?[/\\\\]#', $script) === 1;
		return $isAbsolute ? $script : rtrim($this->baseDir, '/\\').DIRECTORY_SEPARATOR.$script;
	}
}
