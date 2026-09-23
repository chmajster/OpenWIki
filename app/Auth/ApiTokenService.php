<?php

declare(strict_types=1);

namespace OpenWiki\Auth;

use OpenWiki\Core\Database;

final class ApiTokenService
{
    public const SCOPES = [
        'spaces:read',
        'spaces:write',
        'pages:read',
        'pages:write',
        'comments:read',
        'comments:write',
        'search:read',
        'users:read',
        'users:write',
        'groups:read',
        'groups:write',
        'roles:read',
        'roles:write',
        'tags:read',
        'attachments:read',
        'attachments:write',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $userId, string $name, array $scopes, ?string $expiresAt): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Token name is required and may contain at most 191 characters.');
        }

        $scopes = array_values(array_unique(array_map('strval', $scopes)));
        if ($scopes === [] || array_diff($scopes, self::SCOPES) !== []) {
            throw new \InvalidArgumentException('Select at least one valid API scope.');
        }

        $expiry = null;
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false || $timestamp <= time()) {
                throw new \InvalidArgumentException('Token expiry must be in the future.');
            }
            $expiry = gmdate('Y-m-d H:i:s', $timestamp);
        }

        $token = 'owk_' . bin2hex(random_bytes(32));
        $id = $this->database->insert(
            'INSERT INTO api_tokens
             (user_id, name, token_hash, scopes_json, expires_at, last_used_at, created_at, revoked_at)
             VALUES (:user_id, :name, :token_hash, :scopes_json, :expires_at, NULL, UTC_TIMESTAMP(), NULL)',
            [
                'user_id' => $userId,
                'name' => $name,
                'token_hash' => hash('sha256', $token),
                'scopes_json' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'expires_at' => $expiry,
            ]
        );

        return ['id' => $id, 'token' => $token, 'scopes' => $scopes, 'expires_at' => $expiry];
    }

    public function authenticate(?string $authorizationHeader): ?array
    {
        if (!is_string($authorizationHeader)
            || !preg_match('/^Bearer\s+(owk_[A-Fa-f0-9]{64})$/', trim($authorizationHeader), $match)
        ) {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT t.id AS token_id, t.user_id, t.scopes_json, t.expires_at,
                    u.username, u.email, u.first_name, u.last_name, u.status
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id AND u.deleted_at IS NULL
             WHERE t.token_hash = :token_hash
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())
             LIMIT 1',
            ['token_hash' => hash('sha256', $match[1])]
        );

        if ($row === null || $row['status'] !== 'active') {
            return null;
        }

        $scopes = json_decode((string) $row['scopes_json'], true);
        if (!is_array($scopes)) {
            return null;
        }

        $permissions = $this->database->fetchAll(
            'SELECT DISTINCT p.name
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id',
            ['user_id' => (int) $row['user_id']]
        );

        $this->database->execute(
            'UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => (int) $row['token_id']]
        );

        return [
            'token_id' => (int) $row['token_id'],
            'user' => [
                'id' => (int) $row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
            ],
            'scopes' => array_values(array_map('strval', $scopes)),
            'permissions' => array_values(array_map('strval', array_column($permissions, 'name'))),
        ];
    }

    public function hasScope(array $context, string $scope): bool
    {
        return in_array($scope, $context['scopes'] ?? [], true);
    }

    public function hasPermission(array $context, string $permission): bool
    {
        $permissions = $context['permissions'] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function listForUser(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, name, scopes_json, expires_at, last_used_at, created_at, revoked_at
             FROM api_tokens
             WHERE user_id = :user_id
             ORDER BY created_at DESC',
            ['user_id' => $userId]
        );

        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['scopes_json'], true);
            $row['scopes'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);

        return $rows;
    }

    public function revoke(int $userId, int $tokenId): bool
    {
        return $this->database->execute(
            'UPDATE api_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE id = :id AND user_id = :user_id',
            ['id' => $tokenId, 'user_id' => $userId]
        ) === 1;
    }
}
