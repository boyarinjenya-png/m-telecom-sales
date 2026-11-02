<?php
declare(strict_types=1);

namespace MTelecom\Sales\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function connect(Config $config): self
    {
        $driver = (string) $config->get('db.driver', 'mysql');
        if ($driver === 'sqlite') {
            $path = (string) $config->get('db.sqlite_path');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            return new self($pdo);
        }

        $host = (string) $config->get('db.host');
        $port = (string) $config->get('db.port');
        $name = (string) $config->get('db.name');
        $user = (string) $config->get('db.user');
        $pass = (string) $config->get('db.pass');
        $charset = (string) $config->get('db.charset', 'utf8mb4');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Не удалось подключиться к базе данных.');
        }
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    /** @param array<string,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with((string) $k, ':') ? (string) $k : ':' . $k);
            $stmt->bindValue($key, $v);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $fields = implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $cols));
        $placeholders = implode(', ', array_map(fn ($c) => ':' . $c, $cols));
        $this->query("INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})", $data);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public function update(string $table, array $data, array $where): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = $this->quoteIdent($k) . ' = :s_' . $k;
            $params['s_' . $k] = $v;
        }
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = $this->quoteIdent($k) . ' = :w_' . $k;
            $params['w_' . $k] = $v;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conds);
        return $this->query($sql, $params)->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    public function quoteIdent(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Invalid identifier');
        }
        return $this->isSqlite() ? '"' . $name . '"' : '`' . $name . '`';
    }
}
