<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;

final class HealthController extends Controller
{
    public function show(Request $request): Response
    {
        $state = (new \OpenWiki\System\ReadinessService($this->app))->check();
        return Response::json($state, $state['ready'] ? 200 : 503);
    }
}
