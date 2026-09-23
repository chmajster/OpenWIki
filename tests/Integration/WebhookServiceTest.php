<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Security\SecretCipher;
use OpenWiki\Webhooks\WebhookService;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private Database $database;
    private string|false $oldAppKey;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->oldAppKey = getenv('APP_KEY');
        putenv('APP_KEY=' . str_repeat('c', 64));

        $this->database = Database::connect([
            'host' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'username' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'password' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);

        (new MigrationRunner($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->database->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }

        if ($this->oldAppKey === false) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->oldAppKey);
        }
    }

    public function testQueueAndRetryRejectPrivateWebhookTargets(): void
    {
        $secret = (new SecretCipher(str_repeat('c', 64)))->encrypt('0123456789abcdef');
        $webhookId = $this->database->insert(
            'INSERT INTO webhooks
             (name, target_url, secret_ciphertext, events_json, status, created_by, created_at, updated_at)
             VALUES
             ("Local target", "http://127.0.0.1/hook", :secret, :events, "active", NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'secret' => $secret,
                'events' => json_encode(['page.created'], JSON_THROW_ON_ERROR),
            ]
        );

        $service = new WebhookService($this->database);
        self::assertSame(1, $service->queue('page.created', ['id' => 123]));
        self::assertSame(0, $service->queue('page.updated', ['id' => 123]));

        $delivery = $this->database->fetchOne(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = :webhook_id LIMIT 1',
            ['webhook_id' => $webhookId]
        );
        self::assertNotNull($delivery);
        self::assertSame(1, (int) $delivery['attempt_number']);

        $result = $service->processDue(10);
        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['retrying']);
        self::assertSame(0, $result['delivered']);

        $delivery = $this->database->fetchOne(
            'SELECT * FROM webhook_deliveries WHERE id = :id',
            ['id' => (int) $delivery['id']]
        );
        self::assertSame(2, (int) $delivery['attempt_number']);
        self::assertNotNull($delivery['next_attempt_at']);
        self::assertStringContainsString('private or reserved', (string) $delivery['last_error']);
    }

    public function testCreateRejectsLoopbackTargetBeforePersistence(): void
    {
        $service = new WebhookService($this->database);

        $this->expectException(\InvalidArgumentException::class);
        $service->create([
            'name' => 'Invalid',
            'target_url' => 'http://127.0.0.1/hook',
            'secret' => '0123456789abcdef',
            'events' => ['page.created'],
        ], 1);
    }
}
