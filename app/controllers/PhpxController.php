<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

use function SfphpProject\app\components\postcode\PostcodePage;

/**
 * Serves the page that demonstrates .phpx, SFCSS and SFJS together.
 *
 * The page is a component, so the action has nothing to assemble: it asks for
 * the markup and answers with it.
 */
final class PhpxController
{
    /**
     * Show the demonstration.
     *
     * @param Request $request The incoming request
     * @return Response The page
     */
    public function index(Request $request): Response
    {
        return Response::html((string) PostcodePage());
    }
}
