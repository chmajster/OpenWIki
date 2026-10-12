<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Database\MigrationStep;
use OpenWiki\Tests\Support\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class MigrationRecoveryTest extends TestCase
{
    private function plan(array $steps, callable $callback): void
    {
        $directory = sys_get_temp_dir() . '/openwiki-plan-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/001.php', '<?php return ' . var_export(['version' => 'test_recovery', 'up' => $steps], true) . ';');
        try { $callback($directory); }
        finally { unlink($directory . '/001.php'); rmdir($directory); }
    }

    public function testDdlReceiptGapAndVersionReceiptGapDoNotRepeatDataChanges(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            $step = MigrationStep::column('recovery_data', 'label', 'VARCHAR(30) NULL');
            $this->plan(['CREATE TABLE IF NOT EXISTS recovery_data (id INT PRIMARY KEY, count INT NOT NULL)', $step,
                'UPDATE recovery_data SET count = count + 1'], function ($path) use ($db, $step): void {
                $runner = new MigrationRunner($db, $path);
                self::assertFalse($runner->schemaState()['ready']);
                self::assertSame([], $db->fetchAll('SHOW TABLES'), 'Status must not create a receipt table.');
                // Execute real MySQL DDL, as a process killed before the checkpoint would.
                $db->pdo()->exec('CREATE TABLE recovery_data (id INT PRIMARY KEY, count INT NOT NULL)');
                $db->pdo()->exec($step['sql']);
                $db->execute('INSERT INTO recovery_data VALUES (1, 0, "preserved")');
                self::assertSame(['test_recovery'], $runner->migrate());
                self::assertSame(1, (int) $db->fetchOne('SELECT count FROM recovery_data')['count']);
                $db->execute('DELETE FROM schema_migrations');
                $db->execute('UPDATE schema_migration_steps SET state = "running" WHERE step_index = 1');
                self::assertSame(['test_recovery'], $runner->migrate());
                self::assertSame(1, (int) $db->fetchOne('SELECT count FROM recovery_data')['count']);
                self::assertSame('preserved', $db->fetchOne('SELECT label FROM recovery_data')['label']);
                self::assertSame([], $runner->migrate());
                self::assertTrue($runner->schemaState()['ready']);
                $db->execute('INSERT INTO schema_migrations VALUES ("future_incompatible", UTC_TIMESTAMP())');
                self::assertFalse($runner->schemaState()['ready']);
                self::assertTrue($runner->schemaState(['future_incompatible'])['ready']);
            });
        });
    }

    public function testParallelConnectionCannotMigrateAndOuterTransactionIsNotCommitted(): void
    {
        IsolatedDatabase::run(function (Database $db, array $config): void {
            $this->plan(['CREATE TABLE IF NOT EXISTS recovery_data (id INT PRIMARY KEY)'], function ($path) use ($db, $config): void {
                $other = Database::connect($config);
                $name = 'openwiki:migrate:' . substr(hash('sha256', $config['database']), 0, 40);
                self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0) AS locked', [$name])['locked']);
                try {
                    (new MigrationRunner($db, $path))->migrate();
                    self::fail('A concurrent connection must not run DDL.');
                } catch (\RuntimeException $error) { self::assertStringContainsString('lock', $error->getMessage()); }
                self::assertSame([], $db->fetchAll('SHOW TABLES'));
                $other->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
                $db->pdo()->beginTransaction();
                try {
                    (new MigrationRunner($db, $path))->migrate();
                    self::fail('DDL must not commit the caller transaction.');
                } catch (\LogicException) { self::assertTrue($db->pdo()->inTransaction()); }
                finally { $db->pdo()->rollBack(); }
                self::assertSame(['test_recovery'], (new MigrationRunner($db, $path))->migrate());
            });
        });
    }

    public function testFailedDmlResumesOriginalPlanOnceItsCauseIsFixed(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            $this->plan(['CREATE TABLE IF NOT EXISTS recovery_data (id INT PRIMARY KEY)', 'INSERT INTO missing_dependency VALUES (1)'], function ($path) use ($db): void {
                $runner = new MigrationRunner($db, $path);
                try { $runner->migrate(); self::fail('Failure must propagate.'); }
                catch (\RuntimeException) { self::assertFalse($runner->schemaState()['ready']); }
                self::assertSame('failed', $db->fetchOne('SELECT state FROM schema_migration_steps WHERE step_index = 1')['state']);
                $db->pdo()->exec('CREATE TABLE missing_dependency (id INT PRIMARY KEY)');
                self::assertSame(['test_recovery'], $runner->migrate());
                self::assertSame([], $runner->migrate());
                self::assertCount(1, $db->fetchAll('SELECT * FROM missing_dependency'));
            });
        });
    }
}
