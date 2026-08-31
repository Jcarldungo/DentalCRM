<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reading the audit log. There is no write path here — entries are
 * recorded by the actions they describe (see AuditLog::record()) — and no
 * edit or delete path anywhere, which is the point of keeping one.
 *
 * Behind `auth` + `verified` like every other staff page. The app has no
 * roles, so every staff member can read it; a clinic that wants the log
 * restricted to a practice manager needs the roles work that is deferred
 * across the whole product.
 */
class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['nullable', Rule::in(array_keys(AuditLog::ACTIONS))],
        ]);
        $action = $validated['action'] ?? null;

        $entries = AuditLog::query()
            ->when($action, fn ($query) => $query->where('action', $action))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Activity/Index', [
            'entries' => $entries->through(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'action_label' => $entry->actionLabel(),
                'actor_name' => $entry->actor_name,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'subject_label' => $entry->subject_label,
                'context' => $entry->context,
                'created_at' => $entry->created_at->toIso8601String(),
            ]),
            // Only the actions that have actually happened, so the filter
            // never offers a choice that returns nothing.
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (string $key) => [
                    'value' => $key,
                    'label' => AuditLog::ACTIONS[$key] ?? $key,
                ])
                ->values(),
            'filters' => ['action' => $action],
        ]);
    }
}
