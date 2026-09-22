<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Controller;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/*
 * Extends the framework's Controller, which is optional and which lives in the
 * package — so this line works the same in a clone and in a project that ran
 * `composer require`. The base class used to live in this directory, which is
 * not shipped, and that made $this->view() a fatal for anyone who copied it.
 */
final class MainController extends Controller
{
    /**
     * Show the home page.
     *
     * @param Request $request The incoming request
     * @return Response The rendered page
     */
    public function index(Request $request): Response
    {
        return $this->view('home', [
            'title' => 'SFPHP - Modern PHP Framework',
        ]);
    }
}
