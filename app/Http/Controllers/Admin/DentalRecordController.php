<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DentalRecord;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DentalRecordController extends Controller
{
    /**
     * Append-only: there is deliberately no update()/destroy() here, no
     * matching routes, and no UI to reach them. A correction is a new
     * record, not an edit to this one.
     */
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(DentalRecord::TYPES)],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'examination' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'procedure' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $hasClinicalContent = collect(['examination', 'diagnosis', 'procedure', 'notes'])
                ->contains(fn (string $field) => trim((string) $request->input($field)) !== '');

            if (! $hasClinicalContent) {
                $validator->errors()->add(
                    'clinical_content',
                    'Enter at least one of examination, diagnosis, procedure, or notes.'
                );
            }
        });

        $validated = $validator->validate();

        // created_by is never trusted from the request — and it isn't in
        // $fillable, so mass-assigning it through create() would silently
        // drop it. Set it explicitly via direct property assignment instead.
        $record = $patient->dentalRecords()->make($validated);
        $record->created_by = $request->user()->id;
        $record->save();

        return back();
    }
}
