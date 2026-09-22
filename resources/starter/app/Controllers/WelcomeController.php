<?php

namespace {NAMESPACE}Controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * The page `sfphp init` leaves behind, so a fresh install has something to show.
 *
 * Delete it once you have your own.
 */
final class WelcomeController
{
    /**
     * Show the welcome page.
     *
     * @param Request $request The incoming request
     * @return Response The page
     */
    public function index(Request $request): Response
    {
        /*
         * An action returns a Response rather than echoing. That is what lets a
         * middleware wrap it, and what makes this testable without a browser.
         */
        return Response::view('welcome', [
            'version' => \SfphpProject\src\Bootstrap::class,
        ]);
    }
}
