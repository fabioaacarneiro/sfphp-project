<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

class MainController extends BaseController
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
