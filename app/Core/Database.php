<?php

declare(strict_types=1);

namespace OpenWiki\Core;

use PDO;
use PDOException;

final class Database
{
    private int $savepointCounter = 0;
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromEnvironment(bool $withDatabase = true): self
    {
        return self::connect([
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => Env::get('DB_PORT', '3306'),
            'database' => Env::get('DB_DATABASE', 'openwiki'),
            'username' => Env::get('DB_USERNAME', 'openwiki'),
            'password' => Env::get('DB_PASSWORD', ''),
            'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        ], $withDatabase);
    }

    public static function connect(array $config, bool $withDatabase = true): self
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $database = (string) ($config['database'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        if ($host === '' || preg_match('/[;\s\x00-\x1f\x7f]/', $host)
            || ($withDatabase && !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database))) {
            throw new \InvalidArgumentException('Invalid database host or name.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
            throw new \InvalidArgumentException('Invalid database charset.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
        if ($withDatabase) {
            $dsn .= ';dbname=' . $database;
        }

        try {
            $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        return new self($pdo);
    }

    public function pdo(): PDO { return $this->pdo; }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'openwiki_transaction_' . ++$this->savepointCounter;
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback($this);
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }
}
