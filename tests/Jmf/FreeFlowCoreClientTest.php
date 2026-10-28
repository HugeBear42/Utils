<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Jmf\FreeFlowCoreClient;
use HugeBear42\Utils\Jmf\JmfException;
use HugeBear42\Utils\Jmf\JmfParser;


// The JMF requests & the reading of the answers, with a fake transport: no FreeFlow Core is needed.
// The answers are written after the JMF 1.3 specification, to be replaced by answers recorded from FreeFlow Core
final class FreeFlowCoreClientTest extends TestCase
{
	private const QUEUE = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<JMF xmlns="http://www.CIP4.org/JDFSchema_1_1" SenderID="FreeFlowCore" TimeStamp="2026-10-28T14:32:05+01:00" Version="1.3">
		  <Response ID="R1" Type="QueueStatus" ReturnCode="0" refID="Q1">
		    <Queue Status="Running">
		      <QueueEntry QueueEntryID="qe-1" JobID="31313001" DescriptiveName="job_31313001.csv" Status="Completed"
		                  SubmissionTime="2026-10-28T14:30:00+01:00" StartTime="2026-10-28T14:30:02+01:00" EndTime="2026-10-28T14:31:10+01:00"/>
		      <QueueEntry QueueEntryID="qe-2" JobID="31313002" Status="Running" Priority="50"/>
		    </Queue>
		  </Response>
		</JMF>
		XML;

	private array $requests = [];

	// A client whose requests are kept in $this->requests & answered with $status & $answer
	private function client(string $answer, int $status = 200) : FreeFlowCoreClient
	{
		return new FreeFlowCoreClient('http://ffcore01:7751/FreeFlowCore', ['senderId' => 'Dashboard & Co'],
			function(string $url, string $body, array $headers) use ($answer, $status) : array
			{
				$this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
				return [$status, $answer];
			});
	}

	public function testQueueStatus() : void
	{
		$client = $this->client(self::QUEUE);
		$entries = $client->queueStatus();

		$this->assertCount(2, $entries);
		$this->assertSame(JmfParser::QUEUE_ENTRY_ATTRIBUTES, array_keys($entries[0]));
		$this->assertSame('qe-1', $entries[0]['QueueEntryID']);
		$this->assertSame('31313001', $entries[0]['JobID']);
		$this->assertSame(JmfParser::COMPLETED, $entries[0]['Status']);
		$this->assertSame('2026-10-28T14:31:10+01:00', $entries[0]['EndTime']);
		$this->assertSame('', $entries[1]['EndTime']);		// Missing: '' rather than no key

		// The request: one JMF QueueStatus query, posted with the JMF content type & the sender escaped
		$request = $this->requests[0];
		$this->assertSame('http://ffcore01:7751/FreeFlowCore', $request['url']);
		$this->assertContains('Content-Type: application/vnd.cip4-jmf+xml', $request['headers']);
		$sent = JmfParser::parse($request['body']);
		$this->assertSame('Dashboard & Co', (string)$sent['SenderID']);
		$query = $sent->xpath('//*[local-name()="Query"]')[0];
		$this->assertSame('QueueStatus', (string)$query['Type']);
		$this->assertCount(1, $sent->xpath('//*[local-name()="QueueFilter"]'));
		$this->assertSame(self::QUEUE, $client->lastResponse());
	}

	public function testQueueStatusFilter() : void
	{
		$entries = $this->client(self::QUEUE)->queueStatus('running');
		$this->assertCount(1, $entries);
		$this->assertSame('qe-2', $entries[0]['QueueEntryID']);
		$this->assertSame([], $this->client(self::QUEUE)->queueStatus(JmfParser::ABORTED));
	}

	public function testJobStatus() : void
	{
		$answer = <<<'XML'
			<JMF xmlns="http://www.CIP4.org/JDFSchema_1_1" Version="1.3">
			  <Response ID="R2" Type="Status" ReturnCode="0" refID="Q2">
			    <DeviceInfo DeviceStatus="Running">
			      <JobPhase JobID="31313002" QueueEntryID="qe-2" Status="InProgress" PercentCompleted="40" Amount="400" TotalAmount="1000"/>
			    </DeviceInfo>
			    <Queue><QueueEntry QueueEntryID="qe-2" JobID="31313002" Status="Running"/></Queue>
			  </Response>
			</JMF>
			XML;
		$status = $this->client($answer)->jobStatus(jobId: '31313002"<x>');

		$this->assertSame('Running', $status['queueEntries'][0]['Status']);
		$this->assertSame('40', $status['jobPhases'][0]['PercentCompleted']);
		$parameters = JmfParser::parse($this->requests[0]['body'])->xpath('//*[local-name()="StatusQuParams"]')[0];
		$this->assertSame('31313002"<x>', (string)$parameters['JobID']);		// Escaped in the request, read back intact
		$this->assertFalse(isset($parameters['QueueEntryID']));
	}

	public function testJobStatusNeedsAnId() : void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->client(self::QUEUE)->jobStatus();
	}

	public function testKnownMessages() : void
	{
		$answer = '<JMF xmlns="http://www.CIP4.org/JDFSchema_1_1"><Response Type="KnownMessages" ReturnCode="0">'
			.'<MessageService Type="Status" Query="true" Persistent="true"/>'
			.'<MessageService Type="QueueStatus" Query="true" Signal="true"/>'
			.'<MessageService Type="AbortQueueEntry" Command="true"/>'
			.'</Response></JMF>';
		$this->assertSame(['AbortQueueEntry' => ['Command'], 'QueueStatus' => ['Query', 'Signal'], 'Status' => ['Query', 'Persistent']],
			$this->client($answer)->knownMessages());
	}

	public function testSubscribeAndUnsubscribe() : void
	{
		$client = $this->client('<JMF xmlns="http://www.CIP4.org/JDFSchema_1_1"><Response Type="QueueStatus" ReturnCode="0" refID="Q20261028_abc123"/></JMF>');
		$subscription = $client->subscribeQueueStatus('https://dashboard/ffcore/signal?a=1&b=2', 30);
		$this->assertSame(['returnCode' => 0, 'channelId' => 'Q20261028_abc123', 'comment' => ''], $subscription);
		$parameters = JmfParser::parse($this->requests[0]['body'])->xpath('//*[local-name()="Subscription"]')[0];
		$this->assertSame('https://dashboard/ffcore/signal?a=1&b=2', (string)$parameters['URL']);
		$this->assertSame('30', (string)$parameters['RepeatTime']);

		$this->assertSame(['returnCode' => 0, 'comment' => ''], $client->unsubscribe('Q20261028_abc123', 'https://dashboard/ffcore/signal?a=1&b=2'));
		$sent = JmfParser::parse($this->requests[1]['body']);
		$command = $sent->xpath('//*[local-name()="Command"]')[0];
		$this->assertSame('StopPersistentChannel', (string)$command['Type']);
		$this->assertStringStartsWith('C', (string)$command['ID']);
		$this->assertSame('Q20261028_abc123', (string)$sent->xpath('//*[local-name()="StopPersChParams"]')[0]['ChannelID']);
	}

	public function testReturnCode() : void
	{
		$answer = '<JMF xmlns="http://www.CIP4.org/JDFSchema_1_1"><Response Type="Status" ReturnCode="105"><Comment> Unknown queue entry </Comment></Response></JMF>';
		try
		{
			$this->client($answer)->jobStatus('qe-9');
			$this->fail('No exception for ReturnCode 105');
		}
		catch(JmfException $e)
		{
			$this->assertSame(105, $e->returnCode);
			$this->assertNull($e->httpStatus);
			$this->assertStringContainsString('ReturnCode 105: Unknown queue entry', $e->getMessage());
			$this->assertSame($answer, $e->response);
		}
	}

	public function testHttpError() : void
	{
		$client = $this->client('<html>Service unavailable</html>', 503);
		try
		{
			$client->queueStatus();
			$this->fail('No exception for HTTP 503');
		}
		catch(JmfException $e)
		{
			$this->assertSame(503, $e->httpStatus);
			$this->assertSame('<html>Service unavailable</html>', $client->lastResponse());
		}
	}

	public function testNotXml() : void
	{
		$this->expectException(JmfException::class);
		$this->expectExceptionMessage('not valid XML');
		$this->client('Moved to /FreeFlowCore/')->queueStatus();
	}

	public function testNoAnswer() : void
	{
		$client = new FreeFlowCoreClient('https://ffcore01:7759/FreeFlowCore', [], function() : array
		{	throw new JmfException('No answer from FreeFlow Core');	});
		try
		{
			$client->queueStatus();
			$this->fail('No exception without an answer');
		}
		catch(JmfException $e)
		{	$this->assertNull($client->lastResponse());	}
	}

	public function testEndpointMustBeHttp() : void
	{
		$this->expectException(\InvalidArgumentException::class);
		new FreeFlowCoreClient('ffcore01:7751/FreeFlowCore');
	}

	// Without a transport, curl is used: a closed port gives a JmfException, not a PHP warning
	public function testCurlWithoutServer() : void
	{
		if(!function_exists('curl_init'))
		{	$this->markTestSkipped('ext-curl is not loaded');	}
		$client = new FreeFlowCoreClient('http://127.0.0.1:1/FreeFlowCore', ['connectTimeout' => 2, 'timeout' => 3, 'proxy' => '']);
		$this->expectException(JmfException::class);
		$this->expectExceptionMessage('No answer from FreeFlow Core');
		$client->knownMessages();
	}

	public function testParserWithoutNamespace() : void
	{
		$xml = JmfParser::parse('<JMF><Signal Type="QueueStatus"><Queue><QueueEntry QueueEntryID="qe-3" Status="Aborted" StatusDetails="Preflight failed"/></Queue></Signal></JMF>');
		$entries = JmfParser::queueEntries($xml);
		$this->assertSame('Aborted', $entries[0]['Status']);
		$this->assertSame('Preflight failed', $entries[0]['StatusDetails']);
		$this->assertNull(JmfParser::response($xml));
		$this->assertSame('', JmfParser::comment($xml));
	}
}
