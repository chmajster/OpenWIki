<?php
declare(strict_types=1);
namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Search\SearchInput;
use OpenWiki\Search\SearchService;
use OpenWiki\Security\RateLimiter;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        try { $input = SearchInput::fromRequest($request); }
        catch (\InvalidArgumentException $error) { return $this->renderSearch($request, [], $error->getMessage(), 422); }
        if ($input['query'] === '') return $this->renderSearch($request, []);
        if (!$this->rateLimit($request)) return $this->renderSearch($request, [], 'Zbyt wiele wyszukiwań. Spróbuj ponownie za chwilę.', 429);
        $result = (new SearchService($this->app->database()))->search($input, $this->userId(), $this->permissions());
        return $this->renderSearch($request, $result);
    }

    public function suggest(Request $request): Response
    {
        try { $input = SearchInput::fromRequest($request); }
        catch (\InvalidArgumentException $error) { return Response::json(['error' => ['message' => $error->getMessage()]], 422); }
        if (mb_strlen($input['query']) < 2) return Response::json(['data' => []]);
        if (!$this->rateLimit($request, 'search-suggest', 120)) return Response::json(['error' => ['message' => 'Zbyt wiele zapytań.']], 429);
        $input['page'] = 1; $input['per_page'] = 8;
        $result = (new SearchService($this->app->database()))->search($input, $this->userId(), $this->permissions());
        return Response::json(['data' => $result['data']]);
    }

    private function permissions(): array
    {
        return array_values(array_filter(['*', 'page.view', 'page.edit', 'page.review', 'page.publish', 'user.manage'], fn ($permission) => $this->app->auth()->can($permission)));
    }

    private function userId(): ?int { return $this->app->auth()->user()['id'] ?? null; }

    private function rateLimit(Request $request, string $bucket = 'search', int $limit = 60): bool
    {
        $key = $this->userId() === null ? 'ip:' . $request->ip() : 'user:' . $this->userId();
        $limiter = new RateLimiter($this->app->database());
        if ($limiter->tooManyAttempts($bucket, $key, $limit, 60)) return false;
        $limiter->hit($bucket, $key); return true;
    }

    private function renderSearch(Request $request, array $result, ?string $error = null, int $status = 200): Response
    {
        $safeQuery = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $request->query());
        $space = null;
        if (trim($safeQuery['space'] ?? '') !== '' && mb_strlen($safeQuery['space']) <= 191) {
            $candidate = (new \OpenWiki\Repositories\SpaceRepository($this->app->database()))->findByKey(trim($safeQuery['space']));
            if ($candidate !== null && (new \OpenWiki\Permissions\SpaceAccessService($this->app))->canView($candidate)) $space = $candidate;
        }
        return $this->render('search/index', ['title' => 'Search', 'query' => trim($safeQuery['q'] ?? ''),
            'type' => $safeQuery['type'] ?? 'all', 'filters' => $safeQuery,
            'results' => $result['data'] ?? [], 'searchMeta' => $result['meta'] ?? ['page' => 1, 'per_page' => 25, 'total' => 0, 'total_pages' => 0, 'counts' => []],
            'searchError' => $error, 'types' => SearchInput::TYPES, 'space' => $space, 'spaceTab' => 'search']
            + ($space === null ? [] : $this->spaceNavigation($space)), $status);
    }
}
