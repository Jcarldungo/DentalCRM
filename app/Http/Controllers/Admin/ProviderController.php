<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Providers/Index', [
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $provider = Provider::create($validated);

        return back()->with('success', "{$provider->name} was added.");
    }

    public function update(Request $request, Provider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $provider->update($validated);

        return back()->with('success', 'Provider saved.');
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        if ($provider->appointments()->exists()) {
            AuditLog::record('provider.delete_refused', $provider, $provider->name);

            return back()->withErrors(['provider' => 'This provider has appointments and cannot be deleted. Mark them inactive instead.']);
        }

        $name = $provider->name;
        AuditLog::record('provider.deleted', $provider, $name);
        $provider->delete();

        return back()->with('success', "{$name} was removed.");
    }
}
