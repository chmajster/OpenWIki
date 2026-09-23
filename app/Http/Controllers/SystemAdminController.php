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
        ]);
    }
}
