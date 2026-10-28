<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Jmf;

/**
	2026-10-28	v1.00	frank.a.hubert@gmail.com: Reads JMF messages (CIP4 Job Messaging Format): queue entries, job phases,
						known messages & the Response. Used by FreeFlowCoreClient & by a receiver of JMF signals.

	- The elements are found by their local name, so a message with or without the JDF namespace (or another prefix) is read
	- Attributes missing from an element are returned as '' (every key is always there)
	- Times are returned as sent (ISO 8601, e.g. 2026-10-28T14:32:05+01:00)
**/

final class JmfParser
{
	public const NAMESPACE = 'http://www.CIP4.org/JDFSchema_1_1';

	// QueueEntry/@Status (JDF 1.3)
	public const WAITING = 'Waiting';
	public const RUNNING = 'Running';
	public const HELD = 'Held';
	public const SUSPENDED = 'Suspended';
	public const PENDING_RETURN = 'PendingReturn';
	public const COMPLETED = 'Completed';
	public const ABORTED = 'Aborted';
	public const REMOVED = 'Removed';

	public const QUEUE_ENTRY_ATTRIBUTES = ['QueueEntryID', 'JobID', 'JobPartID', 'Status', 'StatusDetails', 'Priority',
		'DescriptiveName', 'SubmissionTime', 'StartTime', 'EndTime', 'DeviceID'];
	public const JOB_PHASE_ATTRIBUTES = ['JobID', 'JobPartID', 'QueueEntryID', 'Status', 'StatusDetails',
		'PercentCompleted', 'Amount', 'TotalAmount', 'StartTime'];

	// The XML of a JMF message. Throws JmfException if it is not XML. No network access (external entities are not loaded)
	public static function parse(string $raw) : \SimpleXMLElement
	{
		$previous = libxml_use_internal_errors(true);
		try
		{
			$xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET);
			if($xml === false)
			{
				$error = libxml_get_last_error();
				throw new JmfException('The JMF answer is not valid XML'.($error ? ': '.trim($error->message) : '').': '.substr($raw, 0, 500), response: $raw);
			}
			return $xml;
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	// The queue entries (jobs) of a QueueStatus or Status answer or signal, one array per entry with QUEUE_ENTRY_ATTRIBUTES
	public static function queueEntries(\SimpleXMLElement $xml) : array
	{	return array_map(fn($node) => self::attributes($node, self::QUEUE_ENTRY_ATTRIBUTES), self::nodes($xml, 'QueueEntry'));	}

	// The job phases of a Status answer or signal, one array per phase with JOB_PHASE_ATTRIBUTES
	public static function jobPhases(\SimpleXMLElement $xml) : array
	{	return array_map(fn($node) => self::attributes($node, self::JOB_PHASE_ATTRIBUTES), self::nodes($xml, 'JobPhase'));	}

	// The messages of a KnownMessages answer: ['QueueStatus' => ['Query', 'Persistent'], ...], sorted by name
	public static function messageServices(\SimpleXMLElement $xml) : array
	{
		$services = [];
		foreach(self::nodes($xml, 'MessageService') as $node)
		{
			$flags = [];
			foreach(['Query', 'Command', 'Signal', 'Persistent'] as $flag)
			{
				if((string)$node[$flag] === 'true')
				{	$flags[] = $flag;	}
			}
			$services[(string)$node['Type']] = $flags;
		}
		ksort($services);
		return $services;
	}

	// The first Response of the message: ['type', 'returnCode', 'refId' (the ID of the Query answered)], null if there is none
	public static function response(\SimpleXMLElement $xml) : ?array
	{
		$node = self::nodes($xml, 'Response')[0] ?? null;
		if($node === null)
		{	return null;	}
		return ['type' => (string)$node['Type'], 'returnCode' => (int)($node['ReturnCode'] ?? 0), 'refId' => (string)$node['refID']];
	}

	// The text of the first Comment (FreeFlow Core explains an error there), '' if there is none
	public static function comment(\SimpleXMLElement $xml) : string
	{
		$node = self::nodes($xml, 'Comment')[0] ?? null;
		return $node === null ? '' : trim((string)$node);
	}

	private static function nodes(\SimpleXMLElement $xml, string $localName) : array
	{	return $xml->xpath('//*[local-name()="'.$localName.'"]') ?: [];	}

	private static function attributes(\SimpleXMLElement $node, array $names) : array
	{
		$values = [];
		foreach($names as $name)
		{	$values[$name] = isset($node[$name]) ? (string)$node[$name] : '';	}
		return $values;
	}
}
