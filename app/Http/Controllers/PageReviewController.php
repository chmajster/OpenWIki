<?php
declare(strict_types=1);
namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\PageReviewService;

final class PageReviewController extends Controller
{
    public function show(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        $repo = new PageRepository($this->app->database());
        $page = $space === null ? null : $repo->findBySlug((int) $space['id'], $slug);
        $acl = new PageAclService($this->app);
        if ($page === null || !$acl->canReadWorking($page, $space)) return $this->render('errors/404', ['title' => 'Nie znaleziono dokumentu'], 404);
        $head = (new ContentService())->safeProjection($repo->working($page));
        $head['content_html'] = (new \OpenWiki\Wiki\RevisionAccessService($this->app->database()))->renderedHtml($head['content_html'], (int) $page['id'], (int) $page['version']);
        $revision = $repo->revision((int) $page['id'], (int) $page['version']);
        $decisionsPage = max(1, min(1000000, (int) $request->query('decisions_page', 1)));
        $reviews = new PageReviewService($this->app->database());
        return $this->render('pages/review', ['title' => 'Obieg: ' . $head['title'], 'space' => $space, 'page' => $page, 'head' => $head,
            'decisions' => $reviews->decisions((int) $page['id'], 50, ($decisionsPage - 1) * 50), 'decisionsPage' => $decisionsPage, 'decisionsTotal' => $reviews->decisionCount((int) $page['id']),
            'canSubmit' => $acl->canEdit($page, $space), 'canReview' => $acl->can($page, $space, 'page.review'),
            'canApprove' => (bool) $space['allow_self_review'] || (int) ($revision['author_id'] ?? 0) !== (int) $this->app->auth()->user()['id'],
            'canPublish' => $acl->can($page, $space, 'page.publish'), 'canVerify' => $acl->can($page, $space, 'page.verify')]);
    }

    public function decide(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        $page = $space === null ? null : (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) return $this->render('errors/404', ['title' => 'Nie znaleziono dokumentu'], 404);
        try {
            (new PageReviewService($this->app->database()))->transition((int) $page['id'], (array) $request->input(), (int) $this->app->auth()->user()['id'], $request);
            Session::flash('success', 'Zapisano decyzję dotyczącą wskazanej rewizji.');
            return Response::redirect('/spaces/' . rawurlencode($spaceKey) . '/pages/' . rawurlencode($page['slug']) . '/review');
        } catch (\Throwable $error) {
            $status = $error instanceof \DomainException ? 403 : ($error instanceof \InvalidArgumentException ? 422 : ($error instanceof \OutOfBoundsException ? 404 : ($error->getMessage() === 'EDIT_CONFLICT' ? 409 : 500)));
            $message = $error->getMessage() === 'EDIT_CONFLICT' ? 'Rewizja zmieniła się. Otwórz dokument ponownie; decyzja nie została zastosowana.' : ($status === 500 ? 'Nie zapisano decyzji. Spróbuj ponownie.' : $error->getMessage());
            return $this->render('pages/review-error', ['title' => 'Nie zapisano decyzji', 'message' => $message, 'back' => '/spaces/' . rawurlencode($spaceKey) . '/pages/' . rawurlencode($page['slug']) . '/review'], $status);
        }
    }

    public function overdue(Request $request): Response
    {
        if (($failure=$this->requireAuth($request))!==null) return $failure;
        return Response::redirect('/?type=freshness&deadline=overdue');
    }
    public function queue(Request $request): Response
    {
        if (($failure=$this->requireAuth($request))!==null) return $failure;
        return Response::redirect('/?type=review');
    }
}
