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

    public function book(Request $request): Response
    {
        return Inertia::render('Public/Book', [
            'initialService' => $request->query('service'),
            'closedDays' => array_values(config('clinic.closed_days')),
            // The same lists BookingController validates against, so the
            // <select> and the Rule::in() cannot drift apart.
            'bookableServices' => array_values(config('clinic.bookable_services')),
            'bookableDentists' => array_values(config('clinic.bookable_dentists')),
            'maxDaysAhead' => (int) config('clinic.max_booking_days_ahead'),
        ]);
    }
}
