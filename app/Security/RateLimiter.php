<?php

declare(strict_types=1);

namespace OpenWiki\Security;

use OpenWiki\Core\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $database)
    {
    }

    public function tooManyAttempts(string $bucket, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $hash = hash('sha256', $key);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);

        $row = $this->database->fetchOne(
            'SELECT COUNT(*) AS attempts
             FROM rate_limit_events
             WHERE bucket = :bucket AND key_hash = :key_hash AND created_at >= :cutoff',
            ['bucket' => $bucket, 'key_hash' => $hash, 'cutoff' => $cutoff]
        );

        return (int) ($row['attempts'] ?? 0) >= $maxAttempts;
    }

    public function hit(string $bucket, string $key): void
    {
        $this->database->execute(
            'INSERT INTO rate_limit_events (bucket, key_hash, created_at)
             VALUES (:bucket, :key_hash, UTC_TIMESTAMP())',
            ['bucket' => $bucket, 'key_hash' => hash('sha256', $key)]
        );
    }

    public function clear(string $bucket, string $key): void
    {
        $this->database->execute(
            'DELETE FROM rate_limit_events WHERE bucket = :bucket AND key_hash = :key_hash',
            ['bucket' => $bucket, 'key_hash' => hash('sha256', $key)]
        );
    }

    public function prune(int $olderThanSeconds = 86400): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $olderThanSeconds);
        $this->database->execute('DELETE FROM rate_limit_events WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
    }
}
