<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Application;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Permissions\PageAclService;
use PHPUnit\Framework\TestCase;

final class PageAclServiceTest extends TestCase
{
    private Application $app;
    private array $oldEnv = [];
    private string $lockPath;
    private string|false $oldLock;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $basePath = dirname(__DIR__, 2);
        $this->lockPath = $basePath . '/storage/installed.lock';
        $this->oldLock = is_file($this->lockPath) ? file_get_contents($this->lockPath) : false;

        foreach ([
            'APP_KEY' => str_repeat('f', 64),
            'DB_HOST' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'DB_PORT' => (string) getenv('OPENWIKI_TEST_DB_PORT'),
            'DB_DATABASE' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'DB_USERNAME' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'DB_CHARSET' => 'utf8mb4',
        ] as $key => $value) {
            $this->oldEnv[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        if (!is_dir(dirname($this->lockPath))) {
            mkdir(dirname($this->lockPath), 0770, true);
        }
        file_put_contents($this->lockPath, '{"test":true}' . PHP_EOL);

        $this->app = Application::boot($basePath);
        (new MigrationRunner($this->app->database(), $basePath . '/database/migrations'))->migrate();
        $this->app->database()->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app->database()->pdo()->inTransaction()) {
            $this->app->database()->pdo()->rollBack();
        }

        foreach ($this->oldEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        if (isset($this->lockPath)) {
            if ($this->oldLock === false) {
                @unlink($this->lockPath);
            } else {
                file_put_contents($this->lockPath, $this->oldLock);
            }
        }
    }

    public function testDirectAllowRuleCreatesPageViewAllowlistAndDenyWins(): void
    {
        [$ownerId, $allowedId, $otherId] = $this->createUsers();
        $space = $this->createPublicSpace($ownerId);
        $page = $this->createPage((int) $space['id'], $ownerId, null);

        $service = new PageAclService($this->app);
        $service->addRule((int) $page['id'], 'user', $allowedId, 'page.view', 'allow', true);

        self::assertTrue($service->canViewFor($page, $space, $allowedId, false, true));
        self::assertFalse($service->canViewFor($page, $space, $otherId, false, true));
        self::assertFalse($service->canViewFor($page, $space, null, false, false));

        $roleId = $this->createRoleWithPermission('acl-viewer-' . bin2hex(random_bytes(3)), 'page.view');
        $this->app->database()->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $allowedId, 'role_id' => $roleId]
        );
        $service->addRule((int) $page['id'], 'role', $roleId, 'page.view', 'allow', true);
        $service->addRule((int) $page['id'], 'user', $allowedId, 'page.view', 'deny', true);

        self::assertFalse($service->canViewFor($page, $space, $allowedId, false, true));
    }

    public function testInheritedAclCanBeDisabledAndOnlyPropagatingRulesApply(): void
    {
        [$ownerId, $allowedId, $otherId] = $this->createUsers();
        $space = $this->createPublicSpace($ownerId);
        $parent = $this->createPage((int) $space['id'], $ownerId, null);
        $child = $this->createPage((int) $space['id'], $ownerId, (int) $parent['id']);

        $service = new PageAclService($this->app);
        $service->addRule((int) $parent['id'], 'user', $allowedId, 'page.view', 'allow', true);

        self::assertTrue($service->canViewFor($child, $space, $allowedId, false, true));
        self::assertFalse($service->canViewFor($child, $space, $otherId, false, true));

        $service->setInheritance((int) $child['id'], false);
        $child = $this->page((int) $child['id']);

        self::assertTrue($service->canViewFor($child, $space, $otherId, false, true));

        $service->setInheritance((int) $child['id'], true);
        $child = $this->page((int) $child['id']);
        $service->addRule((int) $parent['id'], 'user', $allowedId, 'page.view', 'allow', false);

        self::assertTrue($service->canViewFor($child, $space, $otherId, false, true));
    }

    public function testGroupAndRolePrincipalsAreEvaluatedForExplicitApiUserContext(): void
    {
        [$ownerId, $memberId, $otherId] = $this->createUsers();
        $space = $this->createPublicSpace($ownerId);
        $page = $this->createPage((int) $space['id'], $ownerId, null);
        $service = new PageAclService($this->app);

        $groupId = $this->app->database()->insert(
            'INSERT INTO user_groups
             (name, slug, description, source, external_id, created_at, updated_at)
             VALUES (:name, :slug, NULL, "local", NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['name' => 'ACL group', 'slug' => 'acl-group-' . bin2hex(random_bytes(3))]
        );
        $this->app->database()->execute(
            'INSERT INTO group_users (group_id, user_id) VALUES (:group_id, :user_id)',
            ['group_id' => $groupId, 'user_id' => $memberId]
        );

        $service->addRule((int) $page['id'], 'group', $groupId, 'page.view', 'allow', true);

        self::assertTrue($service->canViewFor($page, $space, $memberId, false, true));
        self::assertFalse($service->canViewFor($page, $space, $otherId, false, true));
    }

    private function createUsers(): array
    {
        $suffix = bin2hex(random_bytes(4));

        return [
            $this->createUser('owner-' . $suffix),
            $this->createUser('allowed-' . $suffix),
            $this->createUser('other-' . $suffix),
        ];
    }

    private function createUser(string $username): int
    {
        return $this->app->database()->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username,
                'email' => $username . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }

    private function createPublicSpace(int $ownerId): array
    {
        $id = $this->app->database()->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at, deleted_at)
             VALUES (:name, :space_key, NULL, NULL, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'name' => 'ACL Space',
                'space_key' => 'ACL-' . strtoupper(bin2hex(random_bytes(3))),
                'owner_id' => $ownerId,
            ]
        );

        return $this->app->database()->fetchOne('SELECT * FROM spaces WHERE id = :id', ['id' => $id]);
    }

    private function createPage(int $spaceId, int $ownerId, ?int $parentId): array
    {
        $suffix = bin2hex(random_bytes(4));
        $id = $this->app->database()->insert(
            'INSERT INTO pages
             (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
              author_id, owner_id, order_index, inherit_acl, status, version, published_at, created_at, updated_at, deleted_at)
             VALUES
             (:space_id, :parent_id, :title, :slug, "<p>ACL</p>", NULL, "ACL", "visual",
              :author_id, :owner_id, 0, 1, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'space_id' => $spaceId,
                'parent_id' => $parentId,
                'title' => 'ACL ' . $suffix,
                'slug' => 'acl-' . $suffix,
                'author_id' => $ownerId,
                'owner_id' => $ownerId,
            ]
        );

        return $this->page($id);
    }

    private function page(int $id): array
    {
        return $this->app->database()->fetchOne(
            'SELECT * FROM pages WHERE id = :id',
            ['id' => $id]
        );
    }

    private function createRoleWithPermission(string $slug, string $permission): int
    {
        $this->app->database()->execute(
            'INSERT IGNORE INTO permissions (name, description, created_at)
             VALUES (:name, NULL, UTC_TIMESTAMP())',
            ['name' => $permission]
        );
        $permissionRow = $this->app->database()->fetchOne(
            'SELECT id FROM permissions WHERE name = :name',
            ['name' => $permission]
        );

        $roleId = $this->app->database()->insert(
            'INSERT INTO roles (name, slug, description, is_system, created_at, updated_at)
             VALUES (:name, :slug, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['name' => strtoupper($slug), 'slug' => $slug]
        );
        $this->app->database()->execute(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
            ['role_id' => $roleId, 'permission_id' => (int) $permissionRow['id']]
        );

        return $roleId;
    }
}
