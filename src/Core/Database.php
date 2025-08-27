<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;

use PDO;
use PDOStatement;

class Database
{
    public PDO $connection;
    public PDOStatement $statement;
    private array $optionsArray = [];
    private ?string $type = null;


    /**
     * @throws ApplicationException
     */
    public function __construct(array $config)
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];    // Throw exception on error, return assoc arrays!
        $dsn = '';
        if ($config['type'] === 'sqlsrv') {
            $dsn = $config['type'] . ":Server={$config['connection']['host']}" . (array_key_exists("port", $config['connection']) ? ",{$config['connection']['port']}" : '') . ";Database={$config['connection']['dbname']}";
            if (defined('PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE')) {
                $this->optionsArray = [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE => PDO::SQLSRV_CURSOR_BUFFERED];
            }
        } else if ($config['type'] === 'mysql') {
            $dsn = $config['type'] . ':' . http_build_query($config['connection'], '', ';');
        } else {
            throw new ApplicationException("Unsupported database type {$config['type']}");
        }

        $this->connection = new PDO($dsn, $config['user'], $config['pass'], $options);
        $this->type = $config['type'];
    }

    public function query(string $query, array $params = []): Database
    {
        $this->statement = $this->connection->prepare($query, $this->optionsArray);
        $this->statement->execute($params);
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function lastInsertId(): bool|string
    {
        return $this->connection->lastInsertId();
    }

    public function getStatement(): PDOStatement
    {
        return $this->statement;
    }

    public function __toString(): string
    {
        return print_r($this->connection, true) . ", " . print_r($this->statement, true);
    }

    public function findOrFail()
    {
        $result = $this->find();
        if (!$result) {
            exit(-1);
        }
        return $result;
    }

    public function find()
    {
        return $this->statement->fetch();
    }

    public function get(int $mode = PDO::FETCH_ASSOC): array    // The default mode
    {
        return $this->statement->fetchAll($mode);
    }

    public function errorInfo(): array
    {
        return $this->statement->errorInfo();
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

}