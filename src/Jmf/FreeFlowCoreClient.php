<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Jmf;

/**
	2026-10-28	v1.00	frank.a.hubert@gmail.com: Queries Xerox FreeFlow Core over JMF (CIP4 Job Messaging Format):
						the known messages, the queue, the status of one job & the QueueStatus subscriptions. From FFCoreJMF/ffcore_jmf.php.

	- FreeFlow Core listens for JMF on port 7751 (HTTP) & 7759 (HTTPS): http(s)://<host>:<port>/FreeFlowCore
	- Every method sends one request & throws JmfException if it fails (no connection, HTTP error, not XML, ReturnCode <> 0)
	- lastResponse() keeps the raw answer of the last request, also of a failed one, for the log or debugging
	- Options (all optional):
		senderId		'HugeBear42Utils'	the JMF SenderID, shown by FreeFlow Core
		connectTimeout	10					seconds to connect
		timeout			60					seconds for the whole request
		caFile			null				CA certificate file for HTTPS with a private CA (port 7759)
		proxy			null				null: curl's default (the http_proxy/https_proxy variables), '': no proxy, else the proxy URL
		verbose			false				curl writes the HTTP conversation to STDERR
		curl			[]					further CURLOPT_* => value, they override the above
	- $transport replaces curl, for tests: function(string $url, string $body, array $headers) : array [int $httpStatus, string $body],
	  throws JmfException when there is no answer
**/

final class FreeFlowCoreClient
{
	public const CONTENT_TYPE = 'application/vnd.cip4-jmf+xml';

	private array $options;
	private ?string $lastResponse = null;

	/**
	 * @param string $endpoint e.g. 'http://ffcore01:7751/FreeFlowCore' or 'https://ffcore01:7759/FreeFlowCore'
	 */
	public function __construct(
		private readonly string $endpoint,
		array $options = [],
		private readonly ?\Closure $transport = null,
	)
	{
		if(!preg_match('#^https?://#i', $endpoint))
		{	throw new \InvalidArgumentException("FreeFlowCoreClient: the endpoint must be an http(s) URL, '$endpoint' given");	}
		$this->options = $options + ['senderId' => 'HugeBear42Utils', 'connectTimeout' => 10, 'timeout' => 60,
			'caFile' => null, 'proxy' => null, 'verbose' => false, 'curl' => []];
	}

	// The JMF messages this FreeFlow Core supports: ['QueueStatus' => ['Query', 'Persistent'], ...]
	public function knownMessages() : array
	{
		$xml = $this->send($this->query('KnownMessages', '<KnownMsgQuParams ListCommands="true" ListQueries="true" ListSignals="true"/>'));
		return JmfParser::messageServices($xml);
	}

	/**
	 * All the queue entries (jobs), see JmfParser::QUEUE_ENTRY_ATTRIBUTES.
	 * $status: only the entries with this status (JmfParser::WAITING, RUNNING, COMPLETED...), ignoring the case
	 */
	public function queueStatus(?string $status = null) : array
	{
		$entries = JmfParser::queueEntries($this->send($this->query('QueueStatus', '<QueueFilter QueueEntryDetails="Brief"/>')));
		if($status !== null && $status !== '')
		{	$entries = array_values(array_filter($entries, fn($entry) => strcasecmp($entry['Status'], $status) === 0));	}
		return $entries;
	}

	/**
	 * The status of one job, by its QueueEntryID and/or its JobID: ['queueEntries' => [...], 'jobPhases' => [...]]
	 */
	public function jobStatus(?string $queueEntryId = null, ?string $jobId = null) : array
	{
		if(($queueEntryId ?? '') === '' && ($jobId ?? '') === '')
		{	throw new \InvalidArgumentException('FreeFlowCoreClient::jobStatus(): a QueueEntryID or a JobID is required');	}
		$attributes = ' JobDetails="Brief" QueueInfo="true"';
		if(($queueEntryId ?? '') !== '')
		{	$attributes .= ' QueueEntryID="'.self::escape($queueEntryId).'"';	}
		if(($jobId ?? '') !== '')
		{	$attributes .= ' JobID="'.self::escape($jobId).'"';	}
		$xml = $this->send($this->query('Status', "<StatusQuParams$attributes/>"));
		return ['queueEntries' => JmfParser::queueEntries($xml), 'jobPhases' => JmfParser::jobPhases($xml)];
	}

	/**
	 * A persistent channel: FreeFlow Core posts QueueStatus signals to $signalUrl every $repeatSeconds and/or on a change,
	 * until unsubscribe(). $signalUrl must be reachable from FreeFlow Core. Read the signals with JmfParser::parse() & queueEntries().
	 * Returns ['returnCode', 'channelId' (the ID of the subscribing query, for unsubscribe()), 'comment']
	 */
	public function subscribeQueueStatus(string $signalUrl, int $repeatSeconds = 60) : array
	{
		if($repeatSeconds < 1)
		{	throw new \InvalidArgumentException("FreeFlowCoreClient::subscribeQueueStatus(): the repeat time must be at least 1 second, $repeatSeconds given");	}
		$xml = $this->send($this->query('QueueStatus', '<Subscription URL="'.self::escape($signalUrl).'" RepeatTime="'.$repeatSeconds.'"/>'
			.'<QueueFilter QueueEntryDetails="Brief"/>'));
		$response = JmfParser::response($xml);
		return ['returnCode' => $response['returnCode'] ?? null, 'channelId' => $response['refId'] ?? null, 'comment' => JmfParser::comment($xml)];
	}

	// Ends a persistent channel of subscribeQueueStatus(). Returns ['returnCode', 'comment']
	public function unsubscribe(string $channelId, string $signalUrl) : array
	{
		$xml = $this->send($this->message('Command', 'StopPersistentChannel',
			'<StopPersChParams ChannelID="'.self::escape($channelId).'" URL="'.self::escape($signalUrl).'"/>'));
		return ['returnCode' => JmfParser::response($xml)['returnCode'] ?? null, 'comment' => JmfParser::comment($xml)];
	}

	// The raw answer of the last request, null before the first one or when there was no answer
	public function lastResponse() : ?string
	{	return $this->lastResponse;	}

	// --- Request ---

	private function query(string $type, string $parameters) : string
	{	return $this->message('Query', $type, $parameters);	}

	// A JMF message with one Query or Command ($family)
	private function message(string $family, string $type, string $parameters) : string
	{
		$id = $family[0].date('YmdHis').'_'.bin2hex(random_bytes(3));
		return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
			.'<JMF xmlns="'.JmfParser::NAMESPACE.'" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
			.' SenderID="'.self::escape((string)$this->options['senderId']).'" TimeStamp="'.date('c').'" Version="1.3">'
			."<$family ID=\"$id\" Type=\"$type\" xsi:type=\"$family$type\">$parameters</$family></JMF>";
	}

	private function send(string $jmf) : \SimpleXMLElement
	{
		$this->lastResponse = null;
		$headers = ['Content-Type: '.self::CONTENT_TYPE, 'Accept: '.self::CONTENT_TYPE.', text/xml'];
		[$httpStatus, $raw] = $this->transport !== null
			? ($this->transport)($this->endpoint, $jmf, $headers)
			: $this->post($jmf, $headers);
		$this->lastResponse = $raw;
		if($httpStatus >= 400)
		{	throw new JmfException("FreeFlow Core answered HTTP $httpStatus: ".substr($raw, 0, 500), httpStatus: $httpStatus, response: $raw);	}

		$xml = JmfParser::parse($raw);
		$response = JmfParser::response($xml);
		if($response !== null && $response['returnCode'] !== 0)
		{
			throw new JmfException("FreeFlow Core answered the {$response['type']} query with JMF ReturnCode {$response['returnCode']}: ".JmfParser::comment($xml),
				returnCode: $response['returnCode'], response: $raw);
		}
		return $xml;
	}

	private function post(string $body, array $headers) : array
	{
		if(!function_exists('curl_init'))
		{	throw new JmfException('FreeFlowCoreClient needs the PHP curl extension (ext-curl)');	}
		$curlOptions = [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => (int)$this->options['connectTimeout'],
			CURLOPT_TIMEOUT        => (int)$this->options['timeout'],
			CURLOPT_VERBOSE        => (bool)$this->options['verbose'],
		];
		if($this->options['caFile'] !== null)
		{	$curlOptions[CURLOPT_CAINFO] = $this->options['caFile'];	}
		if($this->options['proxy'] !== null)
		{	$curlOptions[CURLOPT_PROXY] = $this->options['proxy'];	}
		$curlOptions = $this->options['curl'] + $curlOptions;

		$handle = curl_init($this->endpoint);
		curl_setopt_array($handle, $curlOptions);
		$raw = curl_exec($handle);
		$httpStatus = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$error = curl_error($handle);
		if($raw === false)
		{	throw new JmfException("No answer from FreeFlow Core at {$this->endpoint}: $error");	}
		return [$httpStatus, (string)$raw];
	}

	private static function escape(string $value) : string
	{	return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');	}
}
