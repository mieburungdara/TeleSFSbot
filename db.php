<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private PDO $pdo;
    private static ?self $instance = null;

    private function __construct() {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            $this->logError('Database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            // Thread-safe singleton with double-checked locking
            static $lock = null;
            if ($lock === null) {
                $lock = fopen(__FILE__, 'r');
            }
            
            flock($lock, LOCK_EX);
            try {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
            } finally {
                flock($lock, LOCK_UN);
            }
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }

    public function execute(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError('Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql . ' | Params: ' . json_encode($params));
            throw $e;
        }
    }

    public function fetch(string $sql, array $params = []): mixed {
        return $this->execute($sql, $params)->fetch();
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    public function rowCount(string $sql, array $params = []): int {
        return $this->execute($sql, $params)->rowCount();
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed {
        return $this->execute($sql, $params)->fetchColumn($column);
    }

    private function logError(string $message): void {
        $logMessage = date('[Y-m-d H:i:s] ') . '[DB] ' . $message . PHP_EOL;
        
        // Check if log file is writable
        if (!file_exists(LOG_FILE)) {
            if (!touch(LOG_FILE)) {
                return;
            }
        }
        
        if (is_writable(LOG_FILE)) {
            file_put_contents(LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
