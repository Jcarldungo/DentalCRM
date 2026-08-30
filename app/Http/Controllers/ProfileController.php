<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\DentalRecord;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\StockMovement;
use App\Models\ToothCondition;
use App\Models\TreatmentPlanItem;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Every table that carries a `created_by` pointing at users.id. All
     * eight are NOT NULL with a restricting foreign key, so deleting an
     * author throws a QueryException rather than cascading — which is the
     * right protection, but it has to be checked before anything
     * irreversible happens.
     *
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const AUTHORED_TABLES = [
        DentalRecord::class,
        ToothCondition::class,
        TreatmentPlanItem::class,
        Prescription::class,
        Invoice::class,
        Payment::class,
        InventoryItem::class,
        StockMovement::class,
    ];

    /**
     * Delete the user's account.
     *
     * Order matters: the guard runs first, then the delete, then the
     * session teardown. Logging out before the delete meant a user who had
     * authored a payment (or any of the five owners this used to miss) was
     * logged out, saw a 500, and still had an intact account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        foreach (self::AUTHORED_TABLES as $model) {
            if ($model::where('created_by', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'password' => 'This account has authored clinical or billing records and cannot be deleted.',
                ]);
            }
        }

        DB::transaction(fn () => $user->delete());

        // SessionGuard::logout() cycles the remember token for any user
        // that still has one, and saving a model whose row is gone
        // re-inserts it — silently undoing the delete above. Clearing it on
        // the in-memory instance skips that branch, which is also what we
        // want semantically: a deleted account gets no new token.
        $user->setRememberToken(null);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
