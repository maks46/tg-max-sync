<?php

declare(strict_types=1);

namespace App;

class Database
{
    private static ?self $instance = null;
    private \PDO $pdo;

    private function __construct()
    {
        $dbPath = '/app/data/sync.db';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->migrate();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS synced_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                source_message_id TEXT NOT NULL,
                target TEXT NOT NULL,
                target_message_id TEXT NOT NULL,
                created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\',\'now\')),
                UNIQUE(source, source_message_id, target)
            );
            CREATE INDEX IF NOT EXISTS idx_source ON synced_messages(source, source_message_id);
            CREATE INDEX IF NOT EXISTS idx_target ON synced_messages(target, target_message_id);
        ');
    }

    /**
     * Check if a message was already synced from source to target.
     */
    public function isSynced(string $source, string $sourceMessageId, string $target): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM synced_messages WHERE source=? AND source_message_id=? AND target=? LIMIT 1'
        );
        $stmt->execute([$source, $sourceMessageId, $target]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record a successfully synced message pair.
     */
    public function markSynced(
        string $source,
        string $sourceMessageId,
        string $target,
        string $targetMessageId
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO synced_messages (source, source_message_id, target, target_message_id)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$source, $sourceMessageId, $target, $targetMessageId]);
    }

    /**
     * Find the target message id for a given source message (for edit/delete propagation).
     */
    public function getTargetMessageId(string $source, string $sourceMessageId, string $target): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT target_message_id FROM synced_messages WHERE source=? AND source_message_id=? AND target=? LIMIT 1'
        );
        $stmt->execute([$source, $sourceMessageId, $target]);
        $row = $stmt->fetchColumn();
        return $row !== false ? (string)$row : null;
    }

    /**
     * Check whether a message arriving on $target was originally sent FROM $source
     * (i.e. it is a mirror — to prevent echo loops).
     */
    public function isEcho(string $target, string $targetMessageId, string $source): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM synced_messages WHERE source=? AND target=? AND target_message_id=? LIMIT 1'
        );
        $stmt->execute([$source, $target, $targetMessageId]);
        return $stmt->fetchColumn() !== false;
    }
}
