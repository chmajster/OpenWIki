<?php

declare(strict_types=1);

namespace OpenWiki\Auth;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;

final class UserSessionService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function registerCurrent(int $userId, string $ipAddress, string $userAgent): void
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            throw new \RuntimeException('PHP session is not active.');
        }

        $expiresAt = gmdate(
            'Y-m-d H:i:s',
            time() + max(300, Env::int('SESSION_LIFETIME', 7200))
        );

        $this->database->execute(
            'INSERT INTO user_sessions
             (session_id, user_id, ip_address, user_agent, last_activity_at, expires_at)
             VALUES
             (:session_id, :user_id, :ip_address, :user_agent, UTC_TIMESTAMP(), :expires_at)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                last_activity_at = UTC_TIMESTAMP(),
                expires_at = VALUES(expires_at)',
            [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'ip_address' => mb_substr($ipAddress, 0, 45),
                'user_agent' => mb_substr($userAgent, 0, 500),
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function validateAndTouchCurrent(int $userId, string $ipAddress, string $userAgent): bool
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return false;
        }

        $row = $this->database->fetchOne(
            'SELECT session_id
             FROM user_sessions
             WHERE session_id = :session_id
               AND user_id = :user_id
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1',
            ['session_id' => $sessionId, 'user_id' => $userId]
        );

        if ($row === null) {
            $this->database->execute(
                'DELETE FROM user_sessions
                 WHERE session_id = :session_id OR expires_at <= UTC_TIMESTAMP()',
                ['session_id' => $sessionId]
            );
            return false;
        }

        $expiresAt = gmdate(
            'Y-m-d H:i:s',
            time() + max(300, Env::int('SESSION_LIFETIME', 7200))
        );

        $this->database->execute(
            'UPDATE user_sessions
             SET ip_address = :ip_address,
                 user_agent = :user_agent,
                 last_activity_at = UTC_TIMESTAMP(),
                 expires_at = :expires_at
             WHERE session_id = :session_id AND user_id = :user_id',
            [
                'ip_address' => mb_substr($ipAddress, 0, 45),
                'user_agent' => mb_substr($userAgent, 0, 500),
                'expires_at' => $expiresAt,
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]
        );

        return true;
    }

    public function replaceAfterRegeneration(
        string $oldSessionId,
        int $userId,
        string $ipAddress,
        string $userAgent
    ): void {
        $this->database->transaction(function (Database $db) use (
            $oldSessionId,
            $userId,
            $ipAddress,
            $userAgent
        ): void {
            if ($oldSessionId !== '') {
                $db->execute(
                    'DELETE FROM user_sessions
                     WHERE session_id = :session_id AND user_id = :user_id',
                    ['session_id' => $oldSessionId, 'user_id' => $userId]
                );
            }

            $this->registerCurrent($userId, $ipAddress, $userAgent);
        });
    }

    public function removeCurrent(?int $userId = null): void
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return;
        }

        if ($userId === null) {
            $this->database->execute(
                'DELETE FROM user_sessions WHERE session_id = :session_id',
                ['session_id' => $sessionId]
            );
            return;
        }

        $this->database->execute(
            'DELETE FROM user_sessions
             WHERE session_id = :session_id AND user_id = :user_id',
            ['session_id' => $sessionId, 'user_id' => $userId]
        );
    }

    public function activeForUser(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT session_id, ip_address, user_agent, last_activity_at, expires_at
             FROM user_sessions
             WHERE user_id = :user_id AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_activity_at DESC',
            ['user_id' => $userId]
        );

        $current = session_id();

        return array_map(static function (array $row) use ($current): array {
            $sessionId = (string) $row['session_id'];
            return [
                'fingerprint' => hash('sha256', $sessionId),
                'label' => substr(hash('sha256', $sessionId), 0, 12),
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'last_activity_at' => $row['last_activity_at'],
                'expires_at' => $row['expires_at'],
                'current' => $sessionId === $current,
            ];
        }, $rows);
    }

    public function revokeByFingerprint(int $userId, string $fingerprint): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            return false;
        }

        $rows = $this->database->fetchAll(
            'SELECT session_id
             FROM user_sessions
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        foreach ($rows as $row) {
            $sessionId = (string) $row['session_id'];
            if (!hash_equals(hash('sha256', $sessionId), $fingerprint)) {
                continue;
            }

            return $this->database->execute(
                'DELETE FROM user_sessions
                 WHERE session_id = :session_id AND user_id = :user_id',
                ['session_id' => $sessionId, 'user_id' => $userId]
            ) === 1;
        }

        return false;
    }

    public function revokeAll(int $userId): int
    {
        return $this->database->execute(
            'DELETE FROM user_sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
    }
}
