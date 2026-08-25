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
        $inquiries = Inquiry::latest()->get()->map(fn (Inquiry $inquiry) => [
            'id' => $inquiry->id,
            'name' => $inquiry->name,
            'email' => $inquiry->email,
            'phone' => $inquiry->phone,
            'service_interest' => $inquiry->service_interest,
            'message' => $inquiry->message,
            'read_at' => $inquiry->read_at,
            'created_at' => $inquiry->created_at->toDateString(),
        ]);

        return Inertia::render('Admin/Inquiries/Index', [
            'inquiries' => $inquiries,
        ]);
    }

    public function update(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $request->validate(['read' => ['required', 'boolean']]);

        $inquiry->update(['read_at' => $request->boolean('read') ? now() : null]);

        return back();
    }
}
