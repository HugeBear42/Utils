<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Core\EventLog;
use HugeBear42\Utils\Core\Logger;
use HugeBear42\Utils\Core\Database;


// Every test runs on its own empty SQLite database in memory & logs to its own temporary folder
final class EventLogTest extends TestCase
{
	private string $logDir='';

	protected function setUp(): void
	{
		$this->logDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventlogtest_'.uniqid();
		Logger::configure(['dir'=>$this->logDir, 'application'=>'EventLogTest']);
		EventLog::configure(['db'=>['type'=>'sqlite', 'connection'=>['path'=>':memory:']]]);
		EventLog::createTable();
		unset($_SERVER['REMOTE_ADDR']);
	}

	protected function tearDown(): void
	{
		Logger::configure([]);
		unset($_SERVER['REMOTE_ADDR']);
		foreach(glob($this->logDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file)
		{	unlink($file);	}
		if(is_dir($this->logDir))
		{	rmdir($this->logDir);	}
	}

	private function logLines(): array
	{
		$file=Logger::getLogFile();
		return $file!==null && is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
	}

	// Inserts an event with a chosen creation date (UTC), bypassing record()
	private function insertAt(string $createdAt, string $level='info', ?string $reference=null): void
	{
		EventLog::database()->query('INSERT INTO events (created_at, level, event_type, source, reference, message)
			VALUES (:created_at, :level, :type, :source, :reference, :message)',
			['created_at'=>$createdAt, 'level'=>$level, 'type'=>'test', 'source'=>'test', 'reference'=>$reference, 'message'=>"created $createdAt"]);
	}

	// The reads, then a write on another connection to the same SQLite file (as a job insert after a check): it must not be blocked
	public function testAnotherConnectionCanWriteAfterTheReads(): void
	{
		$file=sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventlogtest_'.uniqid().'.sqlite';
		try
		{
			$settings=['type'=>'sqlite', 'connection'=>['path'=>$file]];
			EventLog::configure(['db'=>$settings]);
			EventLog::createTable();
			EventLog::info('test', 'test', 'first', '1');
			$other=new Database($settings);
			$other->connection->exec('PRAGMA busy_timeout = 0');		// Fail at once instead of waiting 5 s
			$other->connection->exec('CREATE TABLE IF NOT EXISTS jobs (id INTEGER)');

			$this->assertTrue(EventLog::recentlyRecorded('test', null, 60));
			$this->assertSame(0, EventLog::countOpen());
			EventLog::countSince(0);
			EventLog::find(EventLog::maxId());
			$other->query('INSERT INTO jobs (id) VALUES (1)');
			$this->assertSame(1, $other->rowCount());
		}
		finally
		{
			EventLog::configure(['db'=>['type'=>'sqlite', 'connection'=>['path'=>':memory:']]]);		// Releases the file
			unset($other);
			gc_collect_cycles();
			@unlink($file);
		}
	}

	// --- Recording ---

	public function testRecordAndFind(): void
	{
		$_SERVER['REMOTE_ADDR']='10.0.0.7';
		$this->assertTrue(EventLog::error('job_rejected', 'api', 'Job 100001 rejected', '100001', "line 1\nline 2", '<job/>'));
		$event=EventLog::find(EventLog::maxId());
		$this->assertSame('error', $event['level']);
		$this->assertSame('job_rejected', $event['event_type']);
		$this->assertSame('api', $event['source']);
		$this->assertSame('100001', $event['reference']);
		$this->assertSame('Job 100001 rejected', $event['message']);
		$this->assertSame("line 1\nline 2", $event['details']);
		$this->assertSame('<job/>', $event['payload']);
		$this->assertSame('10.0.0.7', $event['remote_ip']);
		$this->assertNull($event['acknowledged_at']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $event['created_at']);
		$this->assertLessThan(5, abs(strtotime($event['created_at'].' UTC') - time()), 'created_at is the current UTC time');
	}

	public function testShortcutsAndUnknownLevel(): void
	{
		EventLog::info('a', 'test', 'info');
		EventLog::warning('b', 'test', 'warning');
		EventLog::record('fatal', 'c', 'test', 'unknown level');
		$levels=array_column(EventLog::listEvents([], 'created_at', 'ASC'), 'level');
		$this->assertSame(['info', 'warning', 'error'], $levels);
	}

	public function testFromTheCommandLineThereIsNoAddress(): void
	{
		EventLog::info('a', 'test', 'no address');
		$this->assertNull(EventLog::find(1)['remote_ip']);
	}

	public function testValuesAreCutToTheColumnSizes(): void
	{
		EventLog::info(str_repeat('t', 40), str_repeat('s', 40), str_repeat('é', 600), str_repeat('r', 80), null, str_repeat('ü', 40000));
		$event=EventLog::find(1);
		$this->assertSame(32, mb_strlen($event['event_type']));
		$this->assertSame(32, mb_strlen($event['source']));
		$this->assertSame(512, mb_strlen($event['message']));
		$this->assertSame(64, mb_strlen($event['reference']));
		$this->assertLessThanOrEqual(65535, strlen($event['payload']));
		$this->assertTrue(mb_check_encoding($event['payload'], 'UTF-8'), 'no accented character split');
		$this->assertStringEndsWith('[... cut, the original was 80000 bytes]', $event['payload']);
	}

	public function testInvalidUtf8IsReplaced(): void
	{
		$this->assertTrue(EventLog::error('job_rejected', 'api', "M\xFCller", null, null, "<job>\xFC\xE9</job>"));
		$event=EventLog::find(1);
		$this->assertSame('M?ller', $event['message']);
		$this->assertSame('<job>??</job>', $event['payload']);
	}

	public function testRecordNeverThrows(): void
	{
		EventLog::configure(['db'=>['type'=>'sqlite', 'connection'=>['path'=>$this->logDir.'/missing/folder/x.sqlite']]]);
		$this->assertFalse(EventLog::error('job_rejected', 'api', 'cannot be stored'));
		$this->assertStringContainsString('EventLog: could not record [error] job_rejected: cannot be stored', implode("\n", $this->logLines()));
	}

	public function testNotConfigured(): void
	{
		EventLog::configure([]);
		$this->assertFalse(EventLog::info('a', 'test', 'nowhere to go'));
		$this->assertNull(EventLog::countOpen());
	}

	// --- Logger mirroring ---

	public function testEventsAreWrittenToTheLogger(): void
	{
		EventLog::error('job_rejected', 'api', 'Job 100001 rejected', '100001', null, '<job>secret</job>');
		EventLog::info('job_exported', 'export_cli', 'Job 100001 exported');
		$lines=$this->logLines();
		$this->assertCount(2, $lines);
		$this->assertStringContainsString('[EventLogTest] ERROR   Event job_rejected: Job 100001 rejected', $lines[0]);
		$this->assertStringEndsWith('{"source":"api","reference":"100001"}', $lines[0]);
		$this->assertStringNotContainsString('secret', $lines[0], 'the payload is not logged');
		$this->assertStringContainsString('INFO    Event job_exported: Job 100001 exported', $lines[1]);
		$this->assertStringEndsWith('{"source":"export_cli"}', $lines[1]);
	}

	public function testLoggerMirroringCanBeSwitchedOff(): void
	{
		EventLog::configure(['db'=>EventLog::database(), 'logger'=>false]);
		EventLog::error('job_rejected', 'api', 'not mirrored');
		$this->assertSame([], $this->logLines());
		$this->assertSame(1, EventLog::maxId());
	}

	// --- Repeated failures ---

	public function testRecentlyRecorded(): void
	{
		$this->assertFalse(EventLog::recentlyRecorded('auth_failed', '10.0.0.7', 10));
		$_SERVER['REMOTE_ADDR']='10.0.0.7';
		EventLog::warning('auth_failed', 'api', 'wrong token');
		$this->assertTrue(EventLog::recentlyRecorded('auth_failed', '10.0.0.7', 10));
		$this->assertFalse(EventLog::recentlyRecorded('auth_failed', '10.0.0.8', 10), 'another address');
		$this->assertTrue(EventLog::recentlyRecorded('auth_failed', null, 10), 'any address');
		$this->assertFalse(EventLog::recentlyRecorded('other_type', null, 10));
	}

	public function testRecentlyRecordedPerReference(): void
	{
		EventLog::error('export_failed', 'export_cli', 'folder missing', '100004');
		$this->assertTrue(EventLog::recentlyRecorded('export_failed', null, 60, '100004'));
		$this->assertFalse(EventLog::recentlyRecorded('export_failed', null, 60, '100006'));
	}

	public function testRecentlyRecordedIgnoresOldEvents(): void
	{
		$this->insertAt(gmdate('Y-m-d H:i:s', time()-3600));	// 1 hour ago
		$this->assertFalse(EventLog::recentlyRecorded('test', null, 10));
		$this->assertTrue(EventLog::recentlyRecorded('test', null, 120));
	}

	// --- Reading ---

	public function testCountOpenAndAcknowledge(): void
	{
		EventLog::error('a', 'test', 'error 1');
		EventLog::error('b', 'test', 'error 2');
		EventLog::warning('c', 'test', 'warning');
		$this->assertSame(2, EventLog::countOpen());
		$this->assertSame(1, EventLog::countOpen(EventLog::WARNING));
		$this->assertSame(2, EventLog::acknowledge([1, 3], 'operator'));
		$this->assertSame(0, EventLog::acknowledge([1, 3], 'operator'), 'already acknowledged');
		$this->assertSame(1, EventLog::countOpen());
		$event=EventLog::find(1);
		$this->assertSame('operator', $event['acknowledged_by']);
		$this->assertNotNull($event['acknowledged_at']);
	}

	public function testListFilters(): void
	{
		EventLog::error('a', 'test', 'error for 100001', '100001');
		EventLog::info('b', 'test', 'info for 100001', '100001');
		EventLog::warning('c', 'test', 'warning for 100002', '100002');
		EventLog::acknowledge([3], 'operator');
		$this->assertCount(3, EventLog::listEvents());
		$this->assertSame(['error for 100001'], array_column(EventLog::listEvents(['level'=>'error']), 'message'));
		$this->assertCount(2, EventLog::listEvents(['reference'=>'100001']));
		$this->assertCount(2, EventLog::listEvents(['openOnly'=>true]));
		$this->assertCount(1, EventLog::listEvents(['reference'=>'100001', 'level'=>'info']));
		$this->assertCount(2, EventLog::listEvents([], 'created_at', 'DESC', 2), 'limit');
	}

	public function testListHasPayloadFlagButNotThePayload(): void
	{
		EventLog::error('a', 'test', 'with payload', null, null, '<job/>');
		EventLog::info('b', 'test', 'without');
		$rows=EventLog::listEvents([], 'created_at', 'ASC');
		$this->assertArrayNotHasKey('payload', $rows[0]);
		$this->assertSame([1, 0], array_map('intval', array_column($rows, 'has_payload')));
	}

	public function testListSortingAndInvalidColumn(): void
	{
		EventLog::info('b_type', 'test', 'second');
		EventLog::info('a_type', 'test', 'first');
		$this->assertSame(['a_type', 'b_type'], array_column(EventLog::listEvents([], 'event_type', 'ASC'), 'event_type'));
		$this->assertSame(['b_type', 'a_type'], array_column(EventLog::listEvents([], 'event_type', 'DESC'), 'event_type'));
		$rows=EventLog::listEvents([], 'id; DROP TABLE events', 'sideways');		// Falls back to created_at DESC
		$this->assertCount(2, $rows);
		$this->assertSame(2, EventLog::maxId(), 'the table is still there');
	}

	public function testDateFilterUsesTheLocalDay(): void
	{
		$this->insertAt('2026-09-30 21:59:59');	// 23:59:59 in Zurich (UTC+2) on 30.09
		$this->insertAt('2026-09-30 22:00:00');	// 00:00:00 in Zurich on 01.10
		$this->insertAt('2026-10-01 21:59:59');	// 23:59:59 in Zurich on 01.10
		$this->insertAt('2026-10-01 22:00:00');	// 00:00:00 in Zurich on 02.10
		$messages=array_column(EventLog::listEvents(['date'=>'2026-10-01', 'timezone'=>'Europe/Zurich'], 'created_at', 'ASC'), 'message');
		$this->assertSame(['created 2026-09-30 22:00:00', 'created 2026-10-01 21:59:59'], $messages);
		$this->assertCount(2, EventLog::listEvents(['date'=>'2026-09-30']), 'UTC by default: 21:59:59 & 22:00:00 are both on 30.09 in UTC');
	}

	public function testCountSinceAndMaxId(): void
	{
		$this->assertSame(0, EventLog::maxId());
		EventLog::info('a', 'test', 'before');
		$since=EventLog::maxId();
		EventLog::error('b', 'test', 'after 1', '100001');
		EventLog::info('c', 'test', 'after 2');
		$this->assertSame(['new'=>2, 'newErrors'=>1], EventLog::countSince($since));
		$this->assertSame(['new'=>1, 'newErrors'=>1], EventLog::countSince($since, ['level'=>'error']));
		$this->assertSame(['new'=>0, 'newErrors'=>0], EventLog::countSince(EventLog::maxId()));
	}

	public function testFindUnknownEvent(): void
	{
		$this->assertSame([], EventLog::find(999));
	}

	// --- Cleanup & table ---

	public function testPurgeOlderThan(): void
	{
		$this->insertAt(gmdate('Y-m-d H:i:s', strtotime('-40 days')));
		$this->insertAt(gmdate('Y-m-d H:i:s', strtotime('-31 days')));
		$this->insertAt(gmdate('Y-m-d H:i:s', strtotime('-29 days')));
		EventLog::info('a', 'test', 'today');
		$this->assertSame(2, EventLog::purgeOlderThan(30));
		$this->assertCount(2, EventLog::listEvents());
		$this->assertSame(0, EventLog::purgeOlderThan(30), 'nothing left to purge');
	}

	public function testPurgeNeedsAtLeastOneDay(): void
	{
		$this->expectException(InvalidArgumentException::class);
		EventLog::purgeOlderThan(0);
	}

	public function testCreateTableTwiceAndCustomTableName(): void
	{
		EventLog::createTable();		// Already there: nothing happens
		$db=new Database(['type'=>'sqlite', 'connection'=>['path'=>':memory:']]);
		EventLog::configure(['db'=>$db, 'table'=>'operator_events']);
		EventLog::createTable();
		EventLog::info('a', 'test', 'in the custom table');
		$this->assertSame(1, (int)$db->query('SELECT COUNT(*) AS n FROM operator_events')->find()['n']);
		$this->assertSame('operator_events', EventLog::table());
	}

	public function testInvalidTableNameIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		EventLog::configure(['db'=>[], 'table'=>'events; DROP TABLE users']);
	}

	public function testSchemaForEachDatabase(): void
	{
		foreach(['mysql', 'sqlsrv', 'sqlite'] as $type)
		{
			$sql=implode("\n", EventLog::schema($type, 'my_events'));
			$this->assertStringContainsString('my_events', $sql, $type);
			$this->assertStringContainsString('reference', $sql, $type);
			$this->assertStringContainsString("'info', 'warning', 'error'", $sql, $type);
		}
		$this->expectException(InvalidArgumentException::class);
		EventLog::schema('oracle');
	}
}
