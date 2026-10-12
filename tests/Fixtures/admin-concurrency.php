<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
if (!str_ends_with((string) getenv('OPENWIKI_TEST_DB_DATABASE'), '_test')) throw new RuntimeException('Dedicated database required.');
$db = OpenWiki\Core\Database::connect(['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
    'database' => getenv('OPENWIKI_TEST_DB_DATABASE'), 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')]);
$id = (int) $argv[1];
$db->pdo()->beginTransaction();
// Both requests establish an old repeatable-read snapshot before either changes roles/status.
$db->fetchOne('SELECT COUNT(*) AS total FROM users');
echo "ready\n"; flush(); fgets(STDIN);
try {
    (new OpenWiki\Admin\DirectoryAdminService($db, OpenWiki\Admin\AdminActor::user($id)))->disableUser($id);
    $db->pdo()->commit();
    echo "saved\n";
} catch (DomainException $exception) {
    $db->pdo()->rollBack(); echo "denied\n";
}
