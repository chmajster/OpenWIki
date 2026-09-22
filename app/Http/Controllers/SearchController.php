<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\SearchRepository;
use OpenWiki\Security\RateLimiter;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $results = [];

        if ($query !== '') {
            if (mb_strlen($query) > 200) {
                return $this->render('search/index', [
                    'title' => 'Search',
                    'query' => $query,
                    'results' => [],
                    'searchError' => 'Search query is too long.',
                ], 422);
            }

            $user = $this->app->auth()->user();
            $key = ($user === null ? 'ip:' . $request->ip() : 'user:' . $user['id']);
            $limiter = new RateLimiter($this->app->database());

            if ($limiter->tooManyAttempts('search', $key, 60, 60)) {
                return $this->render('search/index', [
                    'title' => 'Search',
                    'query' => $query,
                    'results' => [],
                    'searchError' => 'Search rate limit exceeded. Try again shortly.',
                ], 429);
            }

            $limiter->hit('search', $key);
            $pageAccess = new PageAclService($this->app);

            foreach ((new SearchRepository($this->app->database()))->searchPages($query) as $result) {
                $space = [
                    'id' => (int) $result['space_id'],
                    'owner_id' => (int) $result['space_owner_id'],
                    'visibility' => $result['visibility'],
                    'status' => $result['space_status'],
                    'deleted_at' => $result['space_deleted_at'],
                ];

                if (!$pageAccess->canView($result, $space)) {
                    continue;
                }

                $text = trim((string) $result['content_text']);
                $result['snippet'] = mb_strlen($text) > 220 ? mb_substr($text, 0, 217) . '...' : $text;
                $results[] = $result;
            }
        }

        return $this->render('search/index', [
            'title' => 'Search',
            'query' => $query,
            'results' => $results,
        ]);
    }
}
