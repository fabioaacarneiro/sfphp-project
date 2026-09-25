<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/*
 * No base class, and none to be had: an action returns a Response, and that is
 * the whole contract. Response is a factory — view(), json(), redirect(),
 * route(), back() — so inheriting a class to shorten those calls would be
 * inheritance paying for nothing.
 */
final class MainController
{
    /**
     * Show the home page.
     *
     * @param Request $request The incoming request
     * @return Response The rendered page
     */
    public function index(Request $request): Response
    {
        return Response::sfht('home', [
            'title' => 'SFPHP - Modern PHP Framework',
        ]);
    }
}
