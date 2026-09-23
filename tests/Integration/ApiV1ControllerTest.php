<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Http\Controllers\Api\ApiV1Controller;
use OpenWiki\Security\SecretCipher;
use PHPUnit\Framework\TestCase;

final class ApiV1ControllerTest extends TestCase
{
    private Application $app;
    private array $oldEnv = [];
    private string $lockPath;
    private string|false $oldLock;
    private string $token;
    private int $userId;
    private array $storageKeys = [];

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $basePath = dirname(__DIR__, 2);
        $this->lockPath = $basePath . '/storage/installed.lock';
        $this->oldLock = is_file($this->lockPath)
            ? file_get_contents($this->lockPath)
            : false;

        foreach ([
            'APP_KEY' => str_repeat('6', 64),
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

        file_put_contents($this->lockPath, '{"test":true}' . PHP_EOL);
        $this->app = Application::boot($basePath);
        (new MigrationRunner(
            $this->app->database(),
            $basePath . '/database/migrations'
        ))->migrate();
        $this->app->database()->pdo()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $this->userId = $this->app->database()->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source,
              force_password_change, created_at, updated_at)
             VALUES
             (:username, :email, :password_hash, "active", "local",
              0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'api-e2e-' . $suffix,
                'email' => 'api-e2e-' . $suffix . '@example.test',
                'password_hash' => password_hash(
                    'CorrectHorseBatteryStaple!42',
                    PASSWORD_DEFAULT
                ),
            ]
        );

        $this->app->database()->execute(
            'INSERT IGNORE INTO permissions (name, description, created_at)
             VALUES ("*", "Super administrator", UTC_TIMESTAMP())'
        );
        $permission = $this->app->database()->fetchOne(
            'SELECT id FROM permissions WHERE name = "*" LIMIT 1'
        );
        self::assertNotNull($permission);

        $roleId = $this->app->database()->insert(
            'INSERT INTO roles
             (name, slug, description, is_system, created_at, updated_at)
             VALUES (:name, :slug, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => 'API E2E ' . $suffix,
                'slug' => 'api-e2e-' . $suffix,
            ]
        );
        $this->app->database()->execute(
            'INSERT INTO role_permissions (role_id, permission_id)
             VALUES (:role_id, :permission_id)',
            [
                'role_id' => $roleId,
                'permission_id' => (int) $permission['id'],
            ]
        );
        $this->app->database()->execute(
            'INSERT INTO user_roles (user_id, role_id)
             VALUES (:user_id, :role_id)',
            ['user_id' => $this->userId, 'role_id' => $roleId]
        );

        $created = (new ApiTokenService($this->app->database()))->create(
            $this->userId,
            'E2E',
            [
                'spaces:read',
                'spaces:write',
                'pages:read',
                'pages:write',
                'comments:read',
                'comments:write',
                'attachments:read',
                'attachments:write',
                'users:read',
                'users:write',
                'groups:read',
                'groups:write',
                'roles:read',
                'roles:write',
                'tags:read',
                'search:read',
                'templates:read',
                'templates:write',
                'webhooks:read',
                'webhooks:write',
            ],
            null
        );
        $this->token = $created['token'];
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->storageKeys !== []) {
            (new AttachmentService(
                $this->app->database(),
                $this->app->basePath()
            ))->purgeStorageKeys($this->storageKeys);
        }

        if (
            isset($this->app)
            && $this->app->database()->pdo()->inTransaction()
        ) {
            $this->app->database()->pdo()->rollBack();
        }

        foreach ($this->oldEnv as $key => $value) {
            $value === false
                ? putenv($key)
                : putenv($key . '=' . $value);
        }

        if ($this->oldLock === false) {
            @unlink($this->lockPath);
        } else {
            file_put_contents($this->lockPath, $this->oldLock);
        }
    }

    public function testSpacePageCommentAndAttachmentApiFlow(): void
    {
        $controller = new ApiV1Controller($this->app);
        $suffix = bin2hex(random_bytes(3));

        $response = $controller->createSpace($this->request(
            'POST',
            '/api/v1/spaces',
            [],
            [
                'name' => 'API Space ' . $suffix,
                'space_key' => 'API-' . strtoupper($suffix),
                'visibility' => 'private',
                'description' => 'Created by integration test',
            ]
        ));
        self::assertSame(201, $response->status());
        $space = $this->data($response);
        $spaceId = (int) $space['id'];

        $response = $controller->space(
            $this->request('GET', '/api/v1/spaces/' . $spaceId),
            (string) $spaceId
        );
        self::assertSame(200, $response->status());
        self::assertSame($spaceId, (int) $this->data($response)['id']);

        $response = $controller->updateSpace(
            $this->request(
                'PATCH',
                '/api/v1/spaces/' . $spaceId,
                [],
                [
                    'name' => 'API Space Updated ' . $suffix,
                    'visibility' => 'restricted',
                ]
            ),
            (string) $spaceId
        );
        self::assertSame(200, $response->status());
        self::assertSame(
            'restricted',
            $this->data($response)['visibility']
        );

        $response = $controller->createPage($this->request(
            'POST',
            '/api/v1/pages',
            [],
            [
                'space_id' => $spaceId,
                'title' => 'API Page ' . $suffix,
                'content_format' => 'visual',
                'content_html' => '<h2>API</h2><p>Content</p>',
                'status' => 'published',
                'tags' => ['api-tag-' . $suffix],
            ]
        ));
        self::assertSame(201, $response->status());
        $page = $this->data($response);
        $pageId = (int) $page['id'];

        $response = $controller->createComment($this->request(
            'POST',
            '/api/v1/comments',
            [],
            ['page_id' => $pageId, 'body' => 'First API comment']
        ));
        self::assertSame(201, $response->status());
        $comment = $this->data($response);
        $commentId = (int) $comment['id'];

        $response = $controller->updateComment(
            $this->request(
                'PATCH',
                '/api/v1/comments/' . $commentId,
                [],
                ['body' => 'Updated API comment']
            ),
            (string) $commentId
        );
        self::assertSame(200, $response->status());
        self::assertStringContainsString(
            'Updated API comment',
            $this->data($response)['body_html']
        );

        $response = $controller->comments($this->request(
            'GET',
            '/api/v1/comments',
            ['page_id' => $pageId]
        ));
        self::assertSame(200, $response->status());
        $payload = $this->payload($response);
        self::assertSame(1, $payload['meta']['total']);
        self::assertSame($commentId, (int) $payload['data'][0]['id']);

        $source = tempnam(sys_get_temp_dir(), 'openwiki-api-attachment-');
        self::assertNotFalse($source);
        file_put_contents($source, 'attachment-v1');

        try {
            $attachments = new AttachmentService(
                $this->app->database(),
                $this->app->basePath()
            );
            $attachmentId = $attachments->createFromSource(
                $pageId,
                $this->userId,
                $source,
                'api-file.txt'
            );
            $attachment = $attachments->find($attachmentId);
            self::assertNotNull($attachment);
            $this->storageKeys[] = (string) $attachment['storage_key'];

            $response = $controller->attachment(
                $this->request(
                    'GET',
                    '/api/v1/attachments/' . $attachmentId
                ),
                (string) $attachmentId
            );
            self::assertSame(200, $response->status());
            self::assertSame(
                'api-file.txt',
                $this->data($response)['name']
            );

            $response = $controller->renameAttachment(
                $this->request(
                    'PATCH',
                    '/api/v1/attachments/' . $attachmentId,
                    [],
                    ['name' => 'api-renamed.txt']
                ),
                (string) $attachmentId
            );
            self::assertSame(200, $response->status());
            self::assertSame(
                'api-renamed.txt',
                $this->data($response)['name']
            );

            $response = $controller->downloadAttachment(
                $this->request(
                    'GET',
                    '/api/v1/attachments/' . $attachmentId . '/download'
                ),
                (string) $attachmentId
            );
            self::assertSame(200, $response->status());
            self::assertArrayHasKey(
                'Content-Disposition',
                $response->headers()
            );

            $response = $controller->deleteAttachment(
                $this->request(
                    'DELETE',
                    '/api/v1/attachments/' . $attachmentId
                ),
                (string) $attachmentId
            );
            self::assertSame(204, $response->status());
        } finally {
            @unlink($source);
        }

        $tagRow = $this->app->database()->fetchOne(
            'SELECT t.id, t.name
             FROM tags t
             INNER JOIN page_tags pt ON pt.tag_id = t.id
             WHERE pt.page_id = :page_id
             LIMIT 1',
            ['page_id' => $pageId]
        );
        self::assertNotNull($tagRow);

        $tagResponse = $controller->tag(
            $this->request(
                'GET',
                '/api/v1/tags/' . (int) $tagRow['id']
            ),
            (string) $tagRow['id']
        );
        self::assertSame(200, $tagResponse->status());
        self::assertSame(
            1,
            $this->data($tagResponse)['page_count']
        );

        $searchResponse = $controller->search($this->request(
            'GET',
            '/api/v1/search',
            [
                'q' => 'API',
                'type' => 'all',
            ]
        ));
        self::assertSame(200, $searchResponse->status());
        $searchPayload = $this->payload($searchResponse);
        self::assertGreaterThan(0, $searchPayload['meta']['total']);
        $searchTypes = array_values(array_unique(array_column(
            $searchPayload['data'],
            'type'
        )));
        self::assertContains('page', $searchTypes);
        self::assertContains('space', $searchTypes);
        self::assertContains('comment', $searchTypes);

        $response = $controller->deleteComment(
            $this->request(
                'DELETE',
                '/api/v1/comments/' . $commentId
            ),
            (string) $commentId
        );
        self::assertSame(204, $response->status());

        $response = $controller->deleteSpace(
            $this->request(
                'DELETE',
                '/api/v1/spaces/' . $spaceId
            ),
            (string) $spaceId
        );
        self::assertSame(204, $response->status());
    }

    public function testDirectoryAdministrationApiFlow(): void
    {
        $controller = new ApiV1Controller($this->app);
        $suffix = bin2hex(random_bytes(3));

        $permissions = $controller->permissions($this->request(
            'GET',
            '/api/v1/permissions'
        ));
        self::assertSame(200, $permissions->status());
        self::assertNotEmpty($this->payload($permissions)['data']);

        $roleResponse = $controller->createRole($this->request(
            'POST',
            '/api/v1/roles',
            [],
            [
                'name' => 'API Role ' . $suffix,
                'slug' => 'api-role-' . $suffix,
                'description' => 'Created through API',
                'permission_ids' => [],
            ]
        ));
        self::assertSame(201, $roleResponse->status());
        $role = $this->data($roleResponse);
        $roleId = (int) $role['id'];

        $groupResponse = $controller->createGroup($this->request(
            'POST',
            '/api/v1/groups',
            [],
            [
                'name' => 'API Group ' . $suffix,
                'slug' => 'api-group-' . $suffix,
                'description' => 'Created through API',
                'user_ids' => [],
            ]
        ));
        self::assertSame(201, $groupResponse->status());
        $group = $this->data($groupResponse);
        $groupId = (int) $group['id'];

        $userResponse = $controller->createUser($this->request(
            'POST',
            '/api/v1/users',
            [],
            [
                'username' => 'managed-' . $suffix,
                'email' => 'managed-' . $suffix . '@example.test',
                'password' => 'CorrectHorseBatteryStaple!43',
                'first_name' => 'Managed',
                'last_name' => 'User',
                'status' => 'active',
                'force_password_change' => 1,
                'role_ids' => [$roleId],
                'group_ids' => [$groupId],
            ]
        ));
        self::assertSame(201, $userResponse->status());
        $user = $this->data($userResponse);
        $managedUserId = (int) $user['id'];
        self::assertSame([$roleId], $user['role_ids']);
        self::assertSame([$groupId], $user['group_ids']);

        $userResponse = $controller->updateUser(
            $this->request(
                'PATCH',
                '/api/v1/users/' . $managedUserId,
                [],
                ['first_name' => 'Updated']
            ),
            (string) $managedUserId
        );
        self::assertSame(200, $userResponse->status());
        self::assertSame('Updated', $this->data($userResponse)['first_name']);

        $groupResponse = $controller->updateGroup(
            $this->request(
                'PATCH',
                '/api/v1/groups/' . $groupId,
                [],
                [
                    'description' => 'Updated group',
                    'user_ids' => [$managedUserId],
                ]
            ),
            (string) $groupId
        );
        self::assertSame(200, $groupResponse->status());
        self::assertSame(
            [$managedUserId],
            $this->data($groupResponse)['user_ids']
        );

        $roleResponse = $controller->updateRole(
            $this->request(
                'PATCH',
                '/api/v1/roles/' . $roleId,
                [],
                ['description' => 'Updated role']
            ),
            (string) $roleId
        );
        self::assertSame(200, $roleResponse->status());
        self::assertSame(
            'Updated role',
            $this->data($roleResponse)['description']
        );

        $userResponse = $controller->user(
            $this->request('GET', '/api/v1/users/' . $managedUserId),
            (string) $managedUserId
        );
        self::assertSame(200, $userResponse->status());

        $groupResponse = $controller->group(
            $this->request('GET', '/api/v1/groups/' . $groupId),
            (string) $groupId
        );
        self::assertSame(200, $groupResponse->status());

        $roleResponse = $controller->role(
            $this->request('GET', '/api/v1/roles/' . $roleId),
            (string) $roleId
        );
        self::assertSame(200, $roleResponse->status());

        self::assertSame(
            204,
            $controller->deleteUser(
                $this->request('DELETE', '/api/v1/users/' . $managedUserId),
                (string) $managedUserId
            )->status()
        );
        self::assertSame(
            204,
            $controller->deleteGroup(
                $this->request('DELETE', '/api/v1/groups/' . $groupId),
                (string) $groupId
            )->status()
        );
        self::assertSame(
            204,
            $controller->deleteRole(
                $this->request('DELETE', '/api/v1/roles/' . $roleId),
                (string) $roleId
            )->status()
        );
    }

    public function testTemplateAndWebhookAdministrationApiFlow(): void
    {
        $controller = new ApiV1Controller($this->app);
        $suffix = bin2hex(random_bytes(3));

        $createdTemplate = $controller->createTemplate($this->request(
            'POST',
            '/api/v1/templates',
            [],
            [
                'name' => 'API Template ' . $suffix,
                'description' => 'Created by API test',
                'content_format' => 'visual',
                'content_html' => '<h2>Template</h2><script>alert(1)</script><p>Body</p>',
                'content_markdown' => '',
            ]
        ));
        self::assertSame(201, $createdTemplate->status());
        $template = $this->data($createdTemplate);
        $templateId = (int) $template['id'];
        self::assertStringNotContainsString('<script', (string) $template['content_html']);

        $listedTemplates = $controller->templates($this->request('GET', '/api/v1/templates'));
        self::assertSame(200, $listedTemplates->status());
        self::assertNotEmpty($this->payload($listedTemplates)['data']);

        $updatedTemplate = $controller->updateTemplate(
            $this->request(
                'PATCH',
                '/api/v1/templates/' . $templateId,
                [],
                [
                    'name' => 'API Template Updated ' . $suffix,
                    'description' => 'Updated',
                    'content_format' => 'markdown',
                    'content_markdown' => '# Updated',
                ]
            ),
            (string) $templateId
        );
        self::assertSame(200, $updatedTemplate->status());
        self::assertSame('API Template Updated ' . $suffix, $this->data($updatedTemplate)['name']);

        $cipher = new SecretCipher(str_repeat('6', 64));
        $webhookId = $this->app->database()->insert(
            'INSERT INTO webhooks
             (name, target_url, secret_ciphertext, events_json, status, created_by, created_at, updated_at)
             VALUES
             (:name, :target_url, :secret_ciphertext, :events_json, "active", :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => 'API Webhook ' . $suffix,
                'target_url' => 'https://example.com/openwiki',
                'secret_ciphertext' => $cipher->encrypt('0123456789abcdef'),
                'events_json' => json_encode(['page.created'], JSON_THROW_ON_ERROR),
                'created_by' => $this->userId,
            ]
        );

        $webhooks = $controller->webhooks($this->request('GET', '/api/v1/webhooks'));
        self::assertSame(200, $webhooks->status());
        $webhookRows = $this->payload($webhooks)['data'];
        self::assertNotEmpty($webhookRows);
        self::assertArrayNotHasKey('secret_ciphertext', $webhookRows[0]);
        self::assertArrayNotHasKey('signing_secret', $webhookRows[0]);

        $webhook = $controller->webhook(
            $this->request('GET', '/api/v1/webhooks/' . $webhookId),
            (string) $webhookId
        );
        self::assertSame(200, $webhook->status());
        self::assertSame($webhookId, (int) $this->data($webhook)['id']);

        $disabled = $controller->updateWebhook(
            $this->request(
                'PATCH',
                '/api/v1/webhooks/' . $webhookId,
                [],
                ['status' => 'disabled']
            ),
            (string) $webhookId
        );
        self::assertSame(200, $disabled->status());
        self::assertSame('disabled', $this->data($disabled)['status']);

        $deliveries = $controller->webhookDeliveries(
            $this->request('GET', '/api/v1/webhooks/' . $webhookId . '/deliveries'),
            (string) $webhookId
        );
        self::assertSame(200, $deliveries->status());
        self::assertSame([], $this->payload($deliveries)['data']);

        self::assertSame(
            204,
            $controller->deleteWebhook(
                $this->request('DELETE', '/api/v1/webhooks/' . $webhookId),
                (string) $webhookId
            )->status()
        );

        self::assertSame(
            204,
            $controller->deleteTemplate(
                $this->request('DELETE', '/api/v1/templates/' . $templateId),
                (string) $templateId
            )->status()
        );
    }

    public function testMissingWriteScopeIsRejected(): void
    {
        $readOnly = (new ApiTokenService($this->app->database()))->create(
            $this->userId,
            'Read only',
            ['spaces:read'],
            null
        );

        $controller = new ApiV1Controller($this->app);
        $response = $controller->createSpace($this->request(
            'POST',
            '/api/v1/spaces',
            [],
            ['name' => 'Blocked'],
            [],
            $readOnly['token']
        ));

        self::assertSame(403, $response->status());
        $payload = $this->payload($response);
        self::assertSame('insufficient_scope', $payload['error']['code']);
    }

    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $files = [],
        ?string $token = null
    ): Request {
        return new Request(
            $method,
            $path,
            $query,
            $body,
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token),
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'OpenWiki PHPUnit API',
            ],
            $files
        );
    }

    private function payload($response): array
    {
        return json_decode(
            $response->body(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function data($response): array
    {
        return $this->payload($response)['data'];
    }
}
