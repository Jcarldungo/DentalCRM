<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            // Constrained to the canonical lists rather than 255 characters
            // of anything: a booking against a known patient's email
            // appends to that patient's record, and both of these fields
            // are rendered back to them on their own signed lookup page.
            'service_interest' => ['required', Rule::in(config('clinic.bookable_services'))],
            'dentist_preference' => ['nullable', Rule::in(config('clinic.bookable_dentists'))],
            // 'bail' matters here: without it the closed-day closure still runs
            // after 'date' fails, and Carbon::parse() throws on unparseable input.
            'preferred_date' => [
                'bail',
                'required',
                'date',
                'after_or_equal:today',
                'before_or_equal:'.now()->addDays((int) config('clinic.max_booking_days_ahead'))->toDateString(),
                $this->clinicIsOpen(),
            ],
            'preferred_time_of_day' => ['required', Rule::in(Appointment::TIMES_OF_DAY)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assertSlotHasCapacity($validated['preferred_date'], $validated['preferred_time_of_day']);

        $patient = $this->findOrCreatePatient(
            $validated['name'],
            $validated['email'],
            $validated['phone'],
        );

        // A request carries the guest's preference only. The real schedule —
        // start/end time, provider, type — is set by staff on confirmation.
        Appointment::create([
            'patient_id' => $patient->id,
            'provider_id' => null,
            'start_time' => null,
            'end_time' => null,
            'type' => null,
            'status' => 'requested',
            'service_interest' => $validated['service_interest'],
            'dentist_preference' => $validated['dentist_preference'] ?? null,
            'preferred_date' => $validated['preferred_date'],
            'preferred_time_of_day' => $validated['preferred_time_of_day'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back();
    }

    /**
     * Rejects dates falling on a weekday the clinic is closed.
     */
    private function clinicIsOpen(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (in_array(Carbon::parse($value)->dayOfWeek, config('clinic.closed_days'), true)) {
                $fail('The clinic is closed on that day. Please choose another date.');
            }
        };
    }

    /**
     * A closed-day date can still be a fully booked one. Checked separately
     * from validation because it needs both the date and the time-of-day
     * together, unlike the single-field closure rules above.
     */
    private function assertSlotHasCapacity(string $preferredDate, string $preferredTimeOfDay): void
    {
        $booked = Appointment::countBookedForSlot(Carbon::parse($preferredDate), $preferredTimeOfDay);

        if ($booked >= config('clinic.max_requests_per_slot')) {
            throw ValidationException::withMessages([
                'preferred_time_of_day' => 'That day and time are fully booked. Please choose another date or time.',
            ]);
        }
    }

    /**
     * The lookup and the insert used to be a plain check-then-act, so two
     * concurrent bookings for the same new email created two patient rows —
     * after which ->first() silently returned the lower id and the signed
     * lookup page omitted the other row's appointments entirely.
     *
     * firstOrCreate closes the window at the application level and the
     * unique index on patients.email closes it at the schema level; the
     * retry covers the case where the other request won the race between
     * our SELECT and our INSERT.
     *
     * An existing patient's name, phone, and date of birth are deliberately
     * never overwritten from a guest booking.
     */
    private function findOrCreatePatient(string $name, string $email, string $phone): Patient
    {
        $email = Str::lower(trim($email));

        // Compared with an explicit LOWER() rather than relying on the column's
        // collation happening to be case-insensitive.
        $existing = Patient::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            return $existing;
        }

        [$firstName, $lastName] = $this->splitName($name);

        try {
            return Patient::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Patient::whereRaw('LOWER(email) = ?', [$email])->firstOrFail();
        }
    }

    /**
     * The form takes one "Name" field but Patient stores first and last
     * separately. Split on the first space; a single-word name becomes the
     * first name with an empty last name.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $position = strpos($name, ' ');

        if ($position === false) {
            return [$name, ''];
        }

        return [substr($name, 0, $position), substr($name, $position + 1)];
    }
}
