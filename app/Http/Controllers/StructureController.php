<?php
declare(strict_types=1);
namespace OpenWiki\Http\Controllers;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Spaces\StructureService;

final class StructureController extends Controller
{
    public function destinations(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (!$this->app->auth()->can('page.move') && !$this->app->auth()->can('page.create')) return Response::json(['error' => ['message' => 'Brak uprawnienia do operacji struktury.']], 403);
        $query = $request->query('q', ''); if (!is_string($query)) return Response::json(['error' => ['message' => 'Nieprawidłowe wyszukiwanie.']], 422);
        $access = new \OpenWiki\Permissions\SpaceAccessService($this->app);
        $spaces = (new \OpenWiki\Repositories\SpaceRepository($this->app->database()))->visibleFor((int) $this->app->auth()->user()['id'], $this->app->auth()->can('*'));
        $result = [];
        foreach ($spaces as $space) if ($access->canEdit($space) && ($query === '' || mb_stripos($space['name'], mb_substr($query, 0, 100)) !== false)) $result[] = ['id' => (int) $space['id'], 'name' => $space['name'], 'key' => $space['space_key'], 'visibility' => $space['visibility']];
        return Response::json(['data' => array_slice($result, 0, 50)]);
    }

    public function preview(Request $request, string $id): Response { return $this->operation($request, $id, true); }
    public function commit(Request $request, string $id): Response { return $this->operation($request, $id, false); }

    private function operation(Request $request, string $id, bool $preview): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            if (!ctype_digit($id) || (int) $id < 1) throw new \OutOfBoundsException('Nie znaleziono strony.');
            $service = new StructureService($this->app); $input = (array) $request->input(); $user = (int) $this->app->auth()->user()['id'];
            return Response::json(['data' => $preview ? $service->preview((int) $id, $input, $user) : $service->execute((int) $id, $input, $user, $request)]);
        } catch (\Throwable $error) {
            $status = $error instanceof \OpenWiki\Spaces\SpaceConflict ? 409 : ($error instanceof \DomainException ? 403 : ($error instanceof \InvalidArgumentException ? 422 : ($error instanceof \OutOfBoundsException ? 404 : 500)));
            if ($status === 500) error_log('[OpenWiki structure] ' . $error->getMessage());
            return Response::json(['error' => ['message' => $status === 500 ? 'Operacja nie została zapisana. Spróbuj ponownie.' : $error->getMessage()]], $status);
        }
    }
}
