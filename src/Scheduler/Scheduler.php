<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Scheduler;

use HugeBear42\Utils\Core\ApplicationException;
use HugeBear42\Utils\Core\EventLog;
use HugeBear42\Utils\Core\Logger;
/**
	2026-10-27	v1.00	frank.a.hubert@gmail.com: Runs the due tasks of a Schedule, each as a separate PHP process.
	                    Meant to be started every minute by the system (one Windows Task Scheduler entry or one cron line):

		$scheduler = new Scheduler($schedule, ['stateDir' => 'C:/myapp/var/scheduler', 'timezone' => 'Europe/Zurich']);
		$results = $scheduler->run();                    // the due tasks
		$results = $scheduler->run('cleanupEvents');     // one task now, due or not (testing, a manual run)

	- Each task runs as its own PHP process (the PHP running the scheduler), so a crash or a fatal error in one task
	  does not stop the others. The due tasks run at the same time, the scheduler waits for all of them
	- A task still running from an earlier minute is skipped (one lock file per task), the other tasks run as usual.
	  So the system must be allowed to start the scheduler while a previous run is still going
	  (Windows Task Scheduler: "If the task is already running: Run a new instance in parallel")
	- A run longer than its timeout is stopped
	- The state (last run & result of each task) is a JSON file in 'stateDir', next to the lock files &
	  the output of the last run of each task (<task>.out.txt & <task>.err.txt)
	- A failed run (exit code not 0, timeout, missing script) is recorded as an EventLog error (source scheduler)
	  when the failure starts or changes, then once per 'repeatMinutes' while it goes on (a task failing every minute
	  would fill the events list). A successful run is only logged at the 'fine' level, its return after failures at 'info'
**/

final class Scheduler
{
	public const OK = 'ok';
	public const FAILED = 'failed';				// Exit code not 0
	public const TIMEOUT = 'timeout';			// Stopped after its timeout
	public const RUNNING = 'running';			// Skipped: still running from an earlier start
	public const MISSING = 'missing';			// The script does not exist
	public const NOT_STARTED = 'not_started';	// The PHP process could not be started

	private const STATE_FILE = 'scheduler-state.json';
	private const OUTPUT_TAIL_BYTES = 2000;		// The end of the output kept in a failure event
	private const POLL_MICROSECONDS = 200000;

	private string $stateDir;
	private \DateTimeZone $timezone;
	private int $defaultTimeout;
	private bool $recordEvents;
	private int $repeatSeconds;
	private string $phpBinary;
	private ?int $now;

	/**
	 * Options:
	 *   'stateDir'  => folder of the state, lock & output files, created if missing (required)
	 *   'timezone'  => the time zone of the daily times (default: UTC)
	 *   'timeout'   => seconds before a run is stopped, unless the task sets its own (default: 3600)
	 *   'events'    => true to record the failures with EventLog, which must be configured (default: true),
	 *                  false to only log them
	 *   'repeatMinutes' => a failure that goes on is reported again after this many minutes (default: 60)
	 *   'phpBinary' => the PHP running the tasks (default: the one running the scheduler)
	 *   'now'       => a Unix time instead of the current time, for the tests
	 * @throws \InvalidArgumentException for a missing stateDir or an unknown time zone
	 */
	public function __construct(private readonly Schedule $schedule, array $options)
	{
		$stateDir = (string)($options['stateDir'] ?? '');
		if($stateDir === '')
		{	throw new \InvalidArgumentException("Scheduler: the 'stateDir' option is required");	}
		$this->stateDir = rtrim($stateDir, '/\\');
		try
		{	$this->timezone = new \DateTimeZone((string)($options['timezone'] ?? 'UTC'));	}
		catch(\Exception $e)
		{	throw new \InvalidArgumentException("Scheduler: unknown time zone '{$options['timezone']}'");	}
		$this->defaultTimeout = max(1, (int)($options['timeout'] ?? 3600));
		$this->recordEvents = (bool)($options['events'] ?? true);
		$this->repeatSeconds = max(1, (int)($options['repeatMinutes'] ?? 60)) * 60;
		$this->phpBinary = (string)($options['phpBinary'] ?? PHP_BINARY);
		$this->now = isset($options['now']) ? (int)$options['now'] : null;
	}

	/**
	 * Runs the due tasks, or only $taskName now (due or not, its schedule is not moved), & waits until they have finished.
	 * Returns one result per task started or skipped: name => ['status', 'exitCode', 'seconds', 'output'].
	 * @throws \InvalidArgumentException for an unknown $taskName
	 * @throws ApplicationException if the state folder cannot be created or written
	 */
	public function run(?string $taskName = null) : array
	{
		if($taskName !== null && $this->schedule->task($taskName) === null)
		{	throw new \InvalidArgumentException("Scheduler: no task '$taskName', the tasks are: ".implode(', ', array_keys($this->schedule->tasks())));	}
		$tick = $this->tick();
		$results = [];
		$toStart = [];		// name => [Task, lock handle]

	// 1. Which tasks are due & free: decided under the state lock, so two schedulers started together do not both run a task
		$this->withState(function(array &$state) use ($taskName, $tick, &$results, &$toStart)
		{
			foreach($this->schedule->tasks() as $name => $task)
			{
				$forced = $taskName !== null;
				if(($forced && $name !== $taskName) || (!$forced && !$task->isDue($state[$name]['lastRun'] ?? null, $tick, $this->timezone)))
				{	continue;	}
				$lock = $this->lockTask($name);
				if($lock === null)
				{
					Logger::fine("Scheduler: task $name skipped, it is still running");
					$results[$name] = $this->result(self::RUNNING);
					continue;
				}
				if(!$forced)
				{	$state[$name]['lastRun'] = $tick;	}
				$toStart[$name] = [$task, $lock];
			}
		});

	// 2. Start them all, then wait for all of them
		$running = [];
		foreach($toStart as $name => [$task, $lock])
		{
			$started = $this->start($task);
			if(is_array($started))
			{	$running[$name] = $started + ['task' => $task, 'lock' => $lock];	}
			else
			{
				$results[$name] = $this->result($started);
				$this->unlockTask($lock);
			}
		}
		while(!empty($running))
		{
			foreach($running as $name => $run)
			{
				$finished = $this->poll($run);
				if($finished !== null)
				{
					$results[$name] = $finished;
					$this->unlockTask($run['lock']);
					unset($running[$name]);
				}
			}
			if(!empty($running))
			{	usleep(self::POLL_MICROSECONDS);	}
		}

	// 3. Keep & report the results. A failure is reported when it starts or changes, then once per 'repeatMinutes' while
	//    it lasts: a task failing every minute must not fill the events list
		$reports = [];		// name => 'failure' | 'repeat' | 'recovered' | 'ok'
		$this->withState(function(array &$state) use ($results, &$reports)
		{
			$now = $this->now ?? time();
			foreach($results as $name => $result)
			{
				if($result['status'] === self::RUNNING)
				{	continue;	}
				$state[$name]['lastStatus'] = $result['status'];
				$state[$name]['lastExitCode'] = $result['exitCode'];
				$state[$name]['lastSeconds'] = $result['seconds'];
				$state[$name]['lastFinished'] = $now;
				$previousFailure = $state[$name]['failure'] ?? null;
				if($result['status'] === self::OK)
				{
					$reports[$name] = $previousFailure !== null ? 'recovered' : 'ok';
					unset($state[$name]['failure'], $state[$name]['failureReported']);
					continue;
				}
				$failure = $result['status'].($result['exitCode'] !== null ? ' '.$result['exitCode'] : '');
				if($failure !== $previousFailure || $now - (int)($state[$name]['failureReported'] ?? 0) >= $this->repeatSeconds)
				{
					$reports[$name] = 'failure';
					$state[$name]['failure'] = $failure;
					$state[$name]['failureReported'] = $now;
				}
				else
				{	$reports[$name] = 'repeat';	}
			}
		});
		foreach($reports as $name => $report)
		{	$this->report($this->schedule->task($name), $results[$name], $report);	}
		return $results;
	}

	/**
	 * Every task with its schedule & state, e.g. for a 'list' command:
	 * name => ['task' => Task, 'running' => bool, 'nextRun' => Unix time, 'lastRun', 'lastStatus', 'lastExitCode', 'lastSeconds', 'lastFinished' (null if never)]
	 */
	public function status() : array
	{
		$state = $this->readState();
		$tick = $this->tick();
		$list = [];
		foreach($this->schedule->tasks() as $name => $task)
		{
			$lock = $this->lockTask($name);
			if($lock !== null)
			{	$this->unlockTask($lock);	}
			$taskState = $state[$name] ?? [];
			$list[$name] = [
				'task'         => $task,
				'running'      => $lock === null,
				'nextRun'      => $task->nextRun($taskState['lastRun'] ?? null, $tick, $this->timezone),
				'lastRun'      => $taskState['lastRun'] ?? null,
				'lastStatus'   => $taskState['lastStatus'] ?? null,
				'lastExitCode' => $taskState['lastExitCode'] ?? null,
				'lastSeconds'  => $taskState['lastSeconds'] ?? null,
				'lastFinished' => $taskState['lastFinished'] ?? null,
			];
		}
		return $list;
	}

	// --- Processes ---

	// Starts the PHP process of a task: ['process', 'started', 'out', 'err'], or MISSING / NOT_STARTED
	private function start(Task $task) : array|string
	{
		if(!is_file($task->script))
		{	return self::MISSING;	}
		$out = $this->outputFile($task->name, 'out');
		$err = $this->outputFile($task->name, 'err');
	// Output to files, not pipes: a task writing more than a pipe holds would wait for ever for someone to read it
		$process = @proc_open(array_merge([$this->phpBinary, $task->script], array_map('strval', $task->arguments)),
			[0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
			$pipes, dirname($task->script));
		if(!is_resource($process))
		{	return self::NOT_STARTED;	}
		fclose($pipes[0]);		// No input
		Logger::fine("Scheduler: task {$task->name} started");
		return ['process' => $process, 'started' => microtime(true), 'out' => $out, 'err' => $err];
	}

	// The result of a finished run, null while it is still running. A run over its timeout is stopped
	private function poll(array $run) : ?array
	{
		$status = proc_get_status($run['process']);
		$seconds = round(microtime(true) - $run['started'], 1);
		if(!$status['running'])
		{
			$exitCode = $status['exitcode'];		// Only valid the first time the process is seen finished
			proc_close($run['process']);
			return $this->result($exitCode === 0 ? self::OK : self::FAILED, $exitCode, $seconds, $this->outputTail($run));
		}
		$timeout = $run['task']->getTimeout() ?? $this->defaultTimeout;
		if($seconds > $timeout)
		{
			proc_terminate($run['process']);
			proc_close($run['process']);
			return $this->result(self::TIMEOUT, null, $seconds, $this->outputTail($run));
		}
		return null;
	}

	private function result(string $status, ?int $exitCode = null, ?float $seconds = null, string $output = '') : array
	{	return ['status' => $status, 'exitCode' => $exitCode, 'seconds' => $seconds, 'output' => $output];	}

	// The end of the error output, or of the normal output if there is none
	private function outputTail(array $run) : string
	{
		foreach([$run['err'], $run['out']] as $file)
		{
			$size = is_file($file) ? (int)filesize($file) : 0;
			if($size > 0)
			{	return trim((string)file_get_contents($file, false, null, max(0, $size - self::OUTPUT_TAIL_BYTES)));	}
		}
		return '';
	}

	/**
	 * $report: 'ok' a fine log line, 'recovered' an info log line, 'repeat' (the same failure, already reported) a fine log line,
	 * 'failure' an event (or an error log line without events)
	 */
	private function report(Task $task, array $result, string $report) : void
	{
		if($report === 'ok')
		{
			Logger::fine("Scheduler: task {$task->name} finished in {$result['seconds']} s");
			return;
		}
		if($report === 'recovered')
		{
			Logger::info("Scheduler: task {$task->name} works again, finished in {$result['seconds']} s");
			return;
		}
		$message = match($result['status'])
		{
			self::FAILED      => "Task {$task->name} failed with exit code {$result['exitCode']} after {$result['seconds']} s",
			self::TIMEOUT     => "Task {$task->name} stopped after {$result['seconds']} s, its timeout",
			self::MISSING     => "Task {$task->name} not run: the script {$task->script} does not exist",
			default           => "Task {$task->name} not run: PHP could not be started ({$this->phpBinary})",
		};
		if($report === 'repeat')
		{
			Logger::fine("Scheduler: $message (still failing, reported at most every ".intdiv($this->repeatSeconds, 60).' minutes)');
			return;
		}
		$details = $result['output'] !== '' ? "End of the output:\n".$result['output'] : null;
		if($this->recordEvents)
		{	EventLog::error($result['status'] === self::TIMEOUT ? 'task_timeout' : 'task_failed', 'scheduler', $message, null, $details);	}
		else
		{	Logger::error("Scheduler: $message".($details !== null ? ' - '.$details : ''));	}
	}

	// --- Files ---

	// The start of the current minute: the scheduler runs once a minute, the due times are whole minutes
	private function tick() : int
	{	return intdiv($this->now ?? time(), 60) * 60;	}

	// Holds the lock of a task (null if it is already held, i.e. the task is running)
	private function lockTask(string $name)
	{
		$lock = fopen($this->stateDir().DIRECTORY_SEPARATOR."$name.lock", 'c');
		if($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
		{
			if($lock !== false)
			{	fclose($lock);	}
			return null;
		}
		return $lock;
	}

	private function unlockTask($lock) : void
	{
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	// Reads, changes & writes the state, under a lock
	private function withState(callable $change) : void
	{
		$file = $this->stateDir().DIRECTORY_SEPARATOR.self::STATE_FILE;
		$handle = fopen($file, 'c+');
		if($handle === false || !flock($handle, LOCK_EX))
		{	throw new ApplicationException("Scheduler: the state file $file cannot be opened");	}
		try
		{
			$state = json_decode((string)stream_get_contents($handle), true);
			$state = is_array($state) ? $state : [];
			$change($state);
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			fflush($handle);
		}
		finally
		{
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private function readState() : array
	{
		$file = $this->stateDir().DIRECTORY_SEPARATOR.self::STATE_FILE;
		$state = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
		return is_array($state) ? $state : [];
	}

	// The output of the last run of a task, overwritten at each run
	private function outputFile(string $name, string $kind) : string
	{	return $this->stateDir().DIRECTORY_SEPARATOR."$name.$kind.txt";	}

	// The state folder, created at the first use
	private function stateDir() : string
	{
		if(!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0775, true) && !is_dir($this->stateDir))
		{	throw new ApplicationException("Scheduler: the state folder {$this->stateDir} cannot be created");	}
		return $this->stateDir;
	}
}
