<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use HugeBear42\Utils\Core\Logger;


final class LoggerTest extends TestCase
{
	 public string $logFile='C:/xampp/php/logs/php.log';
	
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
}
