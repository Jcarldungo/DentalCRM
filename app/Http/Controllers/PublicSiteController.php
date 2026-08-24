<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicSiteController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Public/Home');
    }

    public function services(): Response
    {
        return Inertia::render('Public/Services');
    }

    public function dentists(): Response
    {
        return Inertia::render('Public/Dentists');
    }

    public function about(): Response
    {
        return Inertia::render('Public/About');
    }

    public function contact(Request $request): Response
    {
        return Inertia::render('Public/Contact', [
            'initialService' => $request->query('service'),
        ]);
    }
}
