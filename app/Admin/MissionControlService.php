<?php

declare(strict_types=1);

namespace OpenWiki\Admin;

use OpenWiki\Core\Database;

final class MissionControlService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function siteOverview(int $systemIssues, bool $canReadPages, bool $canSeeRecentPages): array
    {
        $content = $this->database->fetchOne(
            'SELECT
                COALESCE(SUM(status = "published"), 0) AS published_pages,
                COALESCE(SUM(status = "draft"), 0) AS draft_pages,
                COALESCE(SUM(status = "published" AND review_due_at IS NOT NULL AND review_due_at < UTC_DATE()), 0) AS overdue_reviews,
                COALESCE(SUM(status = "published" AND content_owner_id IS NULL), 0) AS ownerless_pages,
                COALESCE(SUM(status = "published" AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)), 0) AS stale_pages
             FROM pages
             WHERE deleted_at IS NULL'
        ) ?? [];

        $access = $this->database->fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM users WHERE status = "active" AND deleted_at IS NULL) AS active_users,
                (SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = "active" AND u.deleted_at IS NULL AND r.slug IN ("admin", "super-admin")) AS global_admins,
                (SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = "active" AND u.deleted_at IS NULL AND r.slug = "guest") AS guest_accounts,
                (SELECT COUNT(*) FROM spaces WHERE deleted_at IS NULL AND status = "active" AND visibility = "public") AS public_spaces'
        ) ?? [];

        $activity = $this->database->fetchOne(
            'SELECT COUNT(*) AS total
             FROM page_revisions r
             INNER JOIN pages p ON p.id = r.page_id AND p.deleted_at IS NULL
             WHERE r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)'
        ) ?? [];
        $tasks = $this->database->fetchOne(
            'SELECT
                COALESCE(SUM(t.status NOT IN ("done", "cancelled")), 0) AS open_tasks,
                COALESCE(SUM(t.status NOT IN ("done", "cancelled") AND t.due_date IS NOT NULL AND t.due_date < UTC_DATE()), 0) AS overdue_tasks
             FROM page_tasks t
             INNER JOIN pages p ON p.id = t.page_id AND p.deleted_at IS NULL'
        ) ?? [];

        $counts = [
            'published_pages' => (int) ($content['published_pages'] ?? 0),
            'draft_pages' => (int) ($content['draft_pages'] ?? 0),
            'overdue_reviews' => (int) ($content['overdue_reviews'] ?? 0),
            'ownerless_pages' => (int) ($content['ownerless_pages'] ?? 0),
            'stale_pages' => (int) ($content['stale_pages'] ?? 0),
            'active_users' => (int) ($access['active_users'] ?? 0),
            'global_admins' => (int) ($access['global_admins'] ?? 0),
            'guest_accounts' => (int) ($access['guest_accounts'] ?? 0),
            'public_spaces' => (int) ($access['public_spaces'] ?? 0),
            'recent_edits' => (int) ($activity['total'] ?? 0),
            'open_tasks' => (int) ($tasks['open_tasks'] ?? 0),
            'overdue_tasks' => (int) ($tasks['overdue_tasks'] ?? 0),
            'broken_links' => (int) (($this->database->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM page_links l
                 INNER JOIN pages p ON p.id = l.source_page_id
                 WHERE l.is_broken = 1 AND p.deleted_at IS NULL'
            )['total'] ?? 0)),
        ];

        $alerts = [];
        if ($systemIssues > 0) $alerts[] = ['key' => 'system', 'count' => $systemIssues, 'priority' => 'critical', 'href' => '/admin/system'];
        if ($counts['broken_links'] > 0) $alerts[] = ['key' => 'broken_links', 'count' => $counts['broken_links'], 'priority' => 'warning', 'href' => '/admin/broken-links'];
        if ($counts['overdue_reviews'] > 0) $alerts[] = ['key' => 'overdue_reviews', 'count' => $counts['overdue_reviews'], 'priority' => 'warning', 'href' => $canReadPages ? '/?view=work&attention=1&type=freshness' : null];
        if ($counts['overdue_tasks'] > 0) $alerts[] = ['key' => 'overdue_tasks', 'count' => $counts['overdue_tasks'], 'priority' => 'warning', 'href' => $canReadPages ? '/?view=work&attention=1&type=task&deadline=overdue' : null];
        if ($counts['stale_pages'] > 0) $alerts[] = ['key' => 'stale_pages', 'count' => $counts['stale_pages'], 'priority' => 'notice', 'href' => $canReadPages ? '/?view=work&attention=1&type=freshness' : null];
        if ($counts['ownerless_pages'] > 0) $alerts[] = ['key' => 'ownerless_pages', 'count' => $counts['ownerless_pages'], 'priority' => 'notice', 'href' => $canReadPages ? '/?view=work&attention=1&type=ownership' : null];

        $recentPages = $canSeeRecentPages ? $this->database->fetchAll(
            'SELECT r.page_id, r.revision_number, r.title, r.status, r.created_at,
                    u.username, p.slug, s.space_key, s.name AS space_name
             FROM page_revisions r
             INNER JOIN pages p ON p.id = r.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 8'
        ) : [];

        return [
            'counts' => $counts,
            'alerts' => $alerts,
            'recent_pages' => $recentPages,
        ];
    }

    public function spaceOverview(array $space, array $visiblePages): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map(
            static fn (array $page): int => (int) ($page['id'] ?? 0),
            $visiblePages
        ), static fn (int $id): bool => $id > 0)));
        if ($pageIds === []) {
            return ['counts' => array_fill_keys([
                'pages', 'published_pages', 'draft_pages', 'overdue_reviews', 'ownerless_pages',
                'stale_pages', 'broken_links', 'open_tasks', 'overdue_tasks', 'recent_edits',
            ], 0), 'recent_pages' => []];
        }

        $placeholders = implode(', ', array_fill(0, count($pageIds), '?'));
        $content = $this->database->fetchOne(
            'SELECT COUNT(*) AS pages,
                    COALESCE(SUM(p.status = "published"), 0) AS published_pages,
                    COALESCE(SUM(p.status = "draft"), 0) AS draft_pages,
                    COALESCE(SUM(p.status = "published" AND p.review_due_at IS NOT NULL AND p.review_due_at < UTC_DATE()), 0) AS overdue_reviews,
                    COALESCE(SUM(p.status = "published" AND p.content_owner_id IS NULL), 0) AS ownerless_pages,
                    COALESCE(SUM(p.status = "published" AND p.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)), 0) AS stale_pages
             FROM pages p
             WHERE p.space_id = ? AND p.deleted_at IS NULL AND p.id IN (' . $placeholders . ')',
            [(int) $space['id'], ...$pageIds]
        ) ?? [];
        $tasks = $this->database->fetchOne(
            'SELECT COALESCE(SUM(status NOT IN ("done", "cancelled")), 0) AS open_tasks,
                    COALESCE(SUM(status NOT IN ("done", "cancelled") AND due_date IS NOT NULL AND due_date < UTC_DATE()), 0) AS overdue_tasks
             FROM page_tasks WHERE page_id IN (' . $placeholders . ')',
            $pageIds
        ) ?? [];
        $activity = $this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM page_revisions WHERE page_id IN (' . $placeholders . ')
             AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)',
            $pageIds
        ) ?? [];
        $links = $this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM page_links WHERE is_broken = 1 AND source_page_id IN (' . $placeholders . ')',
            $pageIds
        ) ?? [];
        $recentPages = $this->database->fetchAll(
            'SELECT r.page_id, r.revision_number, r.title, r.status, r.created_at,
                    u.username, p.slug, s.space_key
             FROM page_revisions r
             INNER JOIN pages p ON p.id = r.page_id AND p.space_id = ? AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
               AND r.page_id IN (' . $placeholders . ')
             ORDER BY r.created_at DESC, r.id DESC LIMIT 8',
            [(int) $space['id'], ...$pageIds]
        );

        $counts = [
            'pages' => (int) ($content['pages'] ?? 0),
            'published_pages' => (int) ($content['published_pages'] ?? 0),
            'draft_pages' => (int) ($content['draft_pages'] ?? 0),
            'overdue_reviews' => (int) ($content['overdue_reviews'] ?? 0),
            'ownerless_pages' => (int) ($content['ownerless_pages'] ?? 0),
            'stale_pages' => (int) ($content['stale_pages'] ?? 0),
            'broken_links' => (int) ($links['total'] ?? 0),
            'open_tasks' => (int) ($tasks['open_tasks'] ?? 0),
            'overdue_tasks' => (int) ($tasks['overdue_tasks'] ?? 0),
            'recent_edits' => (int) ($activity['total'] ?? 0),
        ];

        return ['counts' => $counts, 'recent_pages' => $recentPages];
    }
}
