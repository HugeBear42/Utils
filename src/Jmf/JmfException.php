<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Jmf;

use HugeBear42\Utils\Core\ApplicationException;

/**
	2026-10-28	v1.00	frank.a.hubert@gmail.com: A JMF request that failed: no connection, an HTTP error, an answer that is not XML
						or a JMF Response with a ReturnCode other than 0.
**/

final class JmfException extends ApplicationException
{
	public function __construct(
		string $message,
		public readonly ?int $httpStatus = null,		// Set when the server answered with HTTP 4xx/5xx
		public readonly ?int $returnCode = null,		// Set when the JMF Response has a ReturnCode other than 0
		public readonly ?string $response = null,		// The raw answer, if any, for the log
	)
	{	parent::__construct($message, $returnCode ?? $httpStatus ?? 0);	}
}
