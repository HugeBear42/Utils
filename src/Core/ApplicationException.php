<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;

use Exception;

class ApplicationException extends Exception
{
    public static function getDetailedMessage(Exception $ex): string
    {
        return "Error on line {$ex->getLine()} in {$ex->getFile()}: {$ex->getMessage()}";
    }

    public function errorMessage(): string
    {
        return 'Error on line ' . $this->getLine() . ' in ' . $this->getFile() . ': ' . $this->getMessage();
    }
}
