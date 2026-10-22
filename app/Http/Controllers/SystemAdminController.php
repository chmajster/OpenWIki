<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\System\SystemInfoService;

final class SystemAdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/system/index', [
            'title' => 'System information',
            'system' => (new SystemInfoService($this->app))->snapshot(),
            'searchIndex' => (new \OpenWiki\Search\SearchIndexer($this->app->database()))->status(),
        ]);
    }

    public function rebuildSearch(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        (new \OpenWiki\Search\SearchIndexer($this->app->database()))->enqueue();
        \OpenWiki\Core\Session::flash('success', 'Search index rebuild queued. The worker processes pages in batches.');
        return Response::redirect('/admin/system');
    }
}
