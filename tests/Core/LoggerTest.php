<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Core\Logger;


final class LoggerTest extends TestCase
{
	 public string $logFile='C:/xampp/php/logs/php.log';
	 private string $tmpDir='';		// The log folder of the configure() tests, deleted after each test

	// The Logger keeps its settings in static properties: every test starts from the defaults
	protected function setUp(): void
	{
		Logger::configure([]);
		$this->tmpDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'loggertest_'.uniqid();
	}

	protected function tearDown(): void
	{
		Logger::configure([]);
		foreach(glob($this->tmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file)
		{	unlink($file);	}
		if(is_dir($this->tmpDir))
		{	rmdir($this->tmpDir);	}
	}

	// The lines written to the log file of the current month
	private function logLines(): array
	{
		$file=Logger::getLogFile();
		return $file!==null && is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
	}

	// --- Without configure(): PHP's error_log, as before v1.10 ---

	public function testSetDebug(): void
    {
        $orgLength=filesize( $this->logFile);
        Logger::fine("message generated with fine()");
        clearstatcache();
        $newLength=filesize( $this->logFile);
        $this->assertTrue($newLength > $orgLength, $orgLength.":".$newLength);

        Logger::setDebug(false);
        $orgLength=$newLength;
        Logger::fine("message generated with fine(), should not display");
        clearstatcache();
        $newLength=filesize( $this->logFile);
        $this->assertTrue($newLength == $orgLength, $orgLength.":".$newLength);

        $orgLength=$newLength;
        Logger::error("message generated with error(), should display");
        clearstatcache();
        $newLength=filesize( $this->logFile);
        $this->assertTrue($newLength > $orgLength, $orgLength.":".$newLength);

        $orgLength=$newLength;
        Logger::info("message generated with info(), should  display");
        clearstatcache();
        $newLength=filesize( $this->logFile);
        $this->assertTrue($newLength > $orgLength, $orgLength.":".$newLength);

        $orgLength=$newLength;
        Logger::warning("message generated with warning(), should  display");
        clearstatcache();
        $newLength=filesize( $this->logFile);
        $this->assertTrue($newLength > $orgLength, $orgLength.":".$newLength);

    }

	public function testNoLogFileWithoutConfigure(): void
	{
		$this->assertNull(Logger::getLogFile());
		$this->assertTrue(Logger::isDebug());
	}

	// --- With configure(): monthly log files ---

	public function testWritesToMonthlyFileNamedAfterApplication(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::info('hello');
		$this->assertSame($this->tmpDir.DIRECTORY_SEPARATOR.'TestApp-'.date('Y-m').'.log', Logger::getLogFile());
		$lines=$this->logLines();
		$this->assertCount(1, $lines);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \[TestApp\] INFO    hello \(cli: /', $lines[0]);
	}

	public function testFolderIsCreated(): void
	{
		$this->assertDirectoryDoesNotExist($this->tmpDir);
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::error('creates the folder');
		$this->assertDirectoryExists($this->tmpDir);
		$this->assertCount(1, $this->logLines());
	}

	public function testApplicationNameIsCleanedForTheFileName(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'XMPie Dashboard!']);
		Logger::info('name with a space');
		$this->assertStringEndsWith(DIRECTORY_SEPARATOR.'XMPieDashboard-'.date('Y-m').'.log', Logger::getLogFile());
		$this->assertStringContainsString('[XMPie Dashboard!] INFO', $this->logLines()[0]);	// The line keeps the name as given
	}

	public function testWithoutApplicationNameFileIsCalledApp(): void
	{
		Logger::configure(['dir'=>$this->tmpDir]);
		Logger::info('no application name');
		$this->assertStringEndsWith(DIRECTORY_SEPARATOR.'app-'.date('Y-m').'.log', Logger::getLogFile());
		$this->assertStringNotContainsString('[', $this->logLines()[0]);
	}

	public function testLevelFiltersLowerMessages(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp', 'level'=>'warning']);
		Logger::fine('not logged');
		Logger::info('not logged');
		Logger::warning('logged');
		Logger::error('logged too');
		$lines=$this->logLines();
		$this->assertCount(2, $lines);
		$this->assertStringContainsString('WARNING logged', $lines[0]);
		$this->assertStringContainsString('ERROR   logged too', $lines[1]);
		$this->assertFalse(Logger::isDebug());
	}

	public function testSetDebugAfterConfigure(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::setDebug(false);
		Logger::fine('not logged');
		Logger::info('logged');
		$this->assertCount(1, $this->logLines());
	}

	public function testMultiLineMessageIsOneLine(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::error("<job>\r\n  <n_up>3</n_up>\n</job>");
		$lines=$this->logLines();
		$this->assertCount(1, $lines);
		$this->assertStringContainsString('<job>\n  <n_up>3</n_up>\n</job>', $lines[0]);
	}

	public function testContextIsWrittenAsJson(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::info('Job registered', ['id'=>100001, 'user'=>'Anna Müller', 'url'=>'https://example.com/a.pdf']);
		$this->assertStringEndsWith(' {"id":100001,"user":"Anna Müller","url":"https://example.com/a.pdf"}', $this->logLines()[0]);
	}

	public function testAnyValueCanBeLogged(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp']);
		Logger::info(42);
		Logger::info(null);
		Logger::info(false);
		Logger::info(['a'=>1]);
		$lines=$this->logLines();
		$this->assertCount(4, $lines);
		$this->assertStringContainsString('INFO    42 ', $lines[0]);
		$this->assertStringContainsString('INFO    null ', $lines[1]);
		$this->assertStringContainsString('INFO    false ', $lines[2]);
		$this->assertStringContainsString('Array\n(\n    [a] => 1\n)', $lines[3]);
	}

	public function testConfigureWithEmptyArrayRestoresDefaults(): void
	{
		Logger::configure(['dir'=>$this->tmpDir, 'application'=>'TestApp', 'level'=>'error']);
		Logger::configure([]);
		$this->assertNull(Logger::getLogFile());
		$this->assertTrue(Logger::isDebug());
	}
}
