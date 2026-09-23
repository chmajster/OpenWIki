<?php

declare(strict_types=1);

namespace OpenWiki\Audit;

use OpenWiki\Core\Database;
use OpenWiki\Core\Request;

final class AuditLogger
{
    public function __construct(private readonly Database $database)
    {
    }

    public function log(
        string $action,
        string $objectType,
        ?int $objectId,
        ?int $userId,
        Request $request,
        ?array $before = null,
        ?array $after = null
    ): void {
        $this->database->execute(
            'INSERT INTO audit_logs
             (user_id, action, object_type, object_id, ip_address, user_agent, before_json, after_json, created_at)
             VALUES
             (:user_id, :action, :object_type, :object_id, :ip_address, :user_agent, :before_json, :after_json, UTC_TIMESTAMP())',
            [
                'user_id' => $userId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }
}
