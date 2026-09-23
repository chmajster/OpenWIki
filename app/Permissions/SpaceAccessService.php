<?php

declare(strict_types=1);

namespace OpenWiki\Permissions;

use OpenWiki\Core\Application;

final class SpaceAccessService
{
    public function __construct(private readonly Application $app)
    {
    }

    public function canView(array $space): bool
    {
        $user = $this->app->auth()->user();

        return $this->canViewFor(
            $space,
            $user === null ? null : (int) $user['id'],
            $this->app->auth()->can('*')
        );
    }

    public function canEdit(array $space): bool
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return false;
        }

        return $this->canEditFor($space, (int) $user['id'], $this->app->auth()->can('*'));
    }

    public function canViewFor(array $space, ?int $userId, bool $superAdmin = false): bool
    {
        if (($space['deleted_at'] ?? null) !== null || ($space['status'] ?? 'active') !== 'active') {
            return false;
        }

        if ($userId === null) {
            return ($space['visibility'] ?? 'private') === 'public';
        }

        if ($superAdmin || (int) $space['owner_id'] === $userId) {
            return true;
        }

        $decision = $this->aclDecision((int) $space['id'], $userId, 'space.view');
        if ($decision === false) {
            return false;
        }

        if (($space['visibility'] ?? 'private') === 'public') {
            return true;
        }

        return $decision === true;
    }

    public function canEditFor(array $space, int $userId, bool $superAdmin = false): bool
    {
        if (($space['deleted_at'] ?? null) !== null || ($space['status'] ?? 'active') !== 'active') {
            return false;
        }

        if ($superAdmin || (int) $space['owner_id'] === $userId) {
            return true;
        }

        return $this->aclDecision((int) $space['id'], $userId, 'space.edit') === true;
    }

    private function aclDecision(int $spaceId, int $userId, string $permission): ?bool
    {
        $rows = $this->app->database()->fetchAll(
            'SELECT a.effect
             FROM space_acl a
             WHERE a.space_id = ?
               AND a.permission = ?
               AND (
                    (a.principal_type = "user" AND a.principal_id = ?)
                    OR (a.principal_type = "group" AND EXISTS (
                        SELECT 1 FROM group_users gu
                        WHERE gu.group_id = a.principal_id AND gu.user_id = ?
                    ))
                    OR (a.principal_type = "role" AND EXISTS (
                        SELECT 1 FROM user_roles ur
                        WHERE ur.role_id = a.principal_id AND ur.user_id = ?
                    ))
               )',
            [$spaceId, $permission, $userId, $userId, $userId]
        );

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row['effect'] === 'deny') {
                return false;
            }
        }

        return true;
    }
}
