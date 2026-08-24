<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InquiryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Inquiries/Index', [
            'inquiries' => Inquiry::latest()->get(),
        ]);
    }

    public function update(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $request->validate(['read' => ['required', 'boolean']]);

        $inquiry->update(['read_at' => $request->boolean('read') ? now() : null]);

        return back();
    }
}
