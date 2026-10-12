<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
if (!str_ends_with((string) getenv('DB_DATABASE'), '_test')) throw new RuntimeException('Dedicated database required.');
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$app = OpenWiki\Core\Application::boot(dirname(__DIR__, 2), false); $db = $app->database();
$db->pdo()->beginTransaction(); $db->fetchOne('SELECT COUNT(*) FROM pages');
echo "ready\n"; flush(); fgets(STDIN);
try {
    $request = new OpenWiki\Core\Request('POST', '/', [], [], [], []);
    if ($input['mode'] === 'move') (new OpenWiki\Spaces\StructureService($app))->execute($input['page'], $input['input'], $input['user'], $request);
    else (new OpenWiki\Spaces\SpaceService($app))->rule($input['space'], [], $input['user'], $request, $input['rule']);
    $db->pdo()->commit(); echo "saved\n";
} catch (OpenWiki\Spaces\SpaceConflict | DomainException | InvalidArgumentException $error) {
    $db->pdo()->rollBack(); echo "denied\n";
}
