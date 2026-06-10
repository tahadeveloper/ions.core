<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends BaseController
{
    public function index(Request $request): Response
    {
        return new Response($this->twig->render('home.twig', [
            'app_name' => config('app.name', 'Ions'),
        ]));
    }
}
