<?php

declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Admin\AdminActor;
use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Auth\LdapService;
use OpenWiki\Auth\MfaService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class AdministrativeDelegationTest extends TestCase
{
    private Database $db;
    private DirectoryAdminService $root;
    private DirectoryAdminService $delegate;
    private int $rootId;
    private int $delegateId;
    private int $rootRole;
    private string $suffix;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) self::markTestSkipped('Dedicated database required.');
        $this->db = Database::connect(['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => getenv('OPENWIKI_TEST_DB_DATABASE'), 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')]);
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->db->pdo()->beginTransaction();
        $this->suffix = bin2hex(random_bytes(5));
        $console = new DirectoryAdminService($this->db, AdminActor::localConsole());
        $this->rootRole = $console->saveRole(null, ['name' => 'Root ' . $this->suffix, 'permission_ids' => [$this->permission('*')]]);
        $this->rootId = $console->createUser($this->input('root', [$this->rootRole]));
        $this->root = new DirectoryAdminService($this->db, AdminActor::user($this->rootId));
        $role = $this->root->saveRole(null, ['name' => 'Delegate ' . $this->suffix,
            'permission_ids' => array_map($this->permission(...), ['page.view', 'user.manage', 'role.manage', 'group.manage', 'settings.manage'])]);
        $this->delegateId = $this->root->createUser($this->input('delegate', [$role]));
        $this->delegate = new DirectoryAdminService($this->db, AdminActor::user($this->delegateId));
    }

    protected function tearDown(): void { if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack(); }

    public function testDelegateCannotGrantWildcardOrModifyProtectedAccountsThroughAnyMutation(): void
    {
        $ordinaryRole = $this->delegate->saveRole(null, ['name' => 'Ordinary ' . $this->suffix, 'permission_ids' => [$this->permission('page.view')]]);
        $ordinary = $this->delegate->createUser($this->input('ordinary', [$ordinaryRole]));
        $operations = [
            fn () => $this->delegate->createUser($this->input('escalation', [$this->rootRole])),
            fn () => $this->delegate->updateUser($ordinary, $this->input('ordinary', [$this->rootRole])),
            fn () => $this->delegate->saveRole(null, ['name' => 'Escalation ' . $this->suffix, 'permission_ids' => [$this->permission('*')]]),
            fn () => $this->delegate->saveRole($ordinaryRole, ['name' => 'Ordinary ' . $this->suffix, 'permission_ids' => [$this->permission('*')]]),
            fn () => $this->delegate->saveRole($this->rootRole, ['name' => 'Stripped root', 'permission_ids' => []]),
            fn () => $this->delegate->deleteRole($this->rootRole),
            fn () => $this->delegate->updateUser($this->rootId, $this->input('root', [])),
            fn () => $this->delegate->disableUser($this->rootId),
            fn () => $this->delegate->softDeleteUser($this->rootId),
            fn () => $this->delegate->resetPassword($this->rootId),
            fn () => $this->delegate->resetMfa($this->rootId),
            fn () => $this->delegate->saveGroup(null, ['name' => 'Protected ' . $this->suffix, 'user_ids' => [$this->rootId]]),
        ];
        foreach ($operations as $operation) $this->denied($operation);
        self::assertSame('active', $this->root->user($this->rootId)['status']);
        self::assertSame([$this->rootRole], $this->root->user($this->rootId)['role_ids']);
        self::assertSame([$ordinaryRole], $this->root->user($ordinary)['role_ids']);
        self::assertNotEmpty($this->delegate->resetPassword($ordinary));
    }

    public function testLastSuperAccountAndCustomWildcardRoleRemainIntact(): void
    {
        foreach ([fn () => $this->root->disableUser($this->rootId), fn () => $this->root->softDeleteUser($this->rootId),
            fn () => $this->root->updateUser($this->rootId, $this->input('root', [])),
            fn () => $this->root->saveRole($this->rootRole, ['name' => 'Root ' . $this->suffix, 'permission_ids' => []]),
            fn () => $this->root->deleteRole($this->rootRole)] as $operation) $this->denied($operation);
        self::assertSame('active', $this->root->user($this->rootId)['status']);
        $second = $this->root->createUser($this->input('second-root', [$this->rootRole]));
        self::assertTrue($this->root->disableUser($second));
    }

    public function testSettingsCannotBecomeAnAlternateProtectedAccountTakeover(): void
    {
        $this->denied(fn () => (new LdapService($this->db))->saveConfig([], $this->delegateId));
        $this->denied(fn () => (new LdapService($this->db))->testConfig([], $this->delegateId));
        $this->denied(fn () => (new MfaService($this->db))->updatePolicy(false, [], $this->delegateId));
    }

    public function testMissingActorReaderAndEditorCannotAdministerAccounts(): void
    {
        $this->denied(fn () => (new DirectoryAdminService($this->db))->resetPassword($this->rootId));
        foreach (['page.view', 'page.edit'] as $permission) {
            $role = $this->root->saveRole(null, ['name' => $permission . $this->suffix, 'permission_ids' => [$this->permission($permission)]]);
            $id = $this->root->createUser($this->input(str_replace('.', '-', $permission), [$role]));
            $this->denied(fn () => (new DirectoryAdminService($this->db, AdminActor::user($id)))->resetPassword($this->delegateId));
        }
    }

    public function testConcurrentRequestsCannotDisableBothRemainingSuperAccountsWithOldSnapshots(): void
    {
        if (!str_ends_with((string) getenv('OPENWIKI_TEST_DB_DATABASE'), '_test')) self::markTestSkipped('Committed race fixtures require a dedicated test database.');
        $second = $this->root->createUser($this->input('race-root', [$this->rootRole]));
        $delegateRoles = $this->root->user($this->delegateId)['role_ids'];
        $this->db->pdo()->commit();
        $workers = [];
        try {
            foreach ([$this->rootId, $second] as $id) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/admin-concurrency.php', (string) $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            sort($results); self::assertSame(['denied', 'saved'], $results);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE id IN (?, ?) AND status = "active"', [$this->rootId, $second])['total']);
        } finally {
            foreach ([$this->rootId, $second, $this->delegateId] as $id) $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
            foreach ([$this->rootRole, ...$delegateRoles] as $id) $this->db->execute('DELETE FROM roles WHERE id = ?', [$id]);
        }
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail('Unauthorized operation succeeded.'); }
        catch (\DomainException $exception) { self::assertNotEmpty($exception->getMessage()); }
    }

    private function permission(string $name): int
    {
        $this->db->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$name, $name]);
        return (int) $this->db->fetchOne('SELECT id FROM permissions WHERE name = ?', [$name])['id'];
    }

    private function input(string $name, array $roles): array
    {
        return ['username' => $name . '-' . $this->suffix, 'email' => $name . '-' . $this->suffix . '@example.test',
            'password' => 'DelegationRegression!42', 'status' => 'active', 'role_ids' => $roles];
    }
}
