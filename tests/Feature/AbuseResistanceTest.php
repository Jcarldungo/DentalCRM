<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The abuse cases a real user finds by accident and an attacker finds on
 * purpose: unknown ids, one patient's record reached through another
 * patient's URL, a clinical record linked to someone else's appointment,
 * a double-submitted payment, a second discontinue on the same
 * prescription, and hostile query strings.
 *
 * These all behaved correctly when audited; the tests exist so they keep
 * doing so.
 */
class AbuseResistanceTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unknownIdProvider(): array
    {
        return [
            'patient' => ['get', '/patients/999999'],
            'invoice' => ['get', '/invoices/999999'],
            'inventory item' => ['get', '/inventory/999999'],
            'appointment' => ['patch', '/appointments/999999'],
        ];
    }

    #[DataProvider('unknownIdProvider')]
    public function test_an_unknown_id_is_a_404_not_a_crash(string $method, string $uri): void
    {
        $this->actingUser();

        $this->{$method}($uri, ['status' => 'completed'])->assertNotFound();
    }

    /**
     * The nested clinical routes are scoped, so one patient's id in the
     * URL cannot reach a record belonging to another.
     */
    public function test_a_nested_clinical_record_cannot_be_reached_through_another_patients_url(): void
    {
        $user = $this->actingUser();
        $a = Patient::factory()->create();
        $b = Patient::factory()->create();

        $prescription = Prescription::factory()->create(['patient_id' => $b->id, 'created_by' => $user->id]);
        $treatment = TreatmentPlanItem::factory()->create(['patient_id' => $b->id, 'created_by' => $user->id]);

        $this->patch(
            route('prescriptions.update', ['patient' => $a->id, 'prescription' => $prescription->id]),
            ['discontinued_reason' => 'not mine'],
        )->assertNotFound();

        $this->patch(
            route('treatment-plan-items.update', ['patient' => $a->id, 'treatmentPlanItem' => $treatment->id]),
            ['status' => 'completed'],
        )->assertNotFound();

        $this->assertSame('active', $prescription->fresh()->status);
        $this->assertNotSame('completed', $treatment->fresh()->status);
    }

    /**
     * A clinical record, chart entry, or invoice line cannot be attached
     * to another patient's appointment or treatment — which would put one
     * patient's visit on another patient's chart.
     */
    public function test_clinical_content_cannot_link_to_another_patients_rows(): void
    {
        $user = $this->actingUser();
        $a = Patient::factory()->create();
        $b = Patient::factory()->create();

        $appointmentB = Appointment::factory()->create(['patient_id' => $b->id]);
        $treatmentB = TreatmentPlanItem::factory()->create(['patient_id' => $b->id, 'created_by' => $user->id]);

        $this->post(route('dental-records.store', $a->id), [
            'type' => 'consultation',
            'examination' => 'x',
            'appointment_id' => $appointmentB->id,
        ])->assertSessionHasErrors('appointment_id');

        $this->post(route('tooth-conditions.store', $a->id), [
            'tooth_number' => 3,
            'condition' => 'caries',
            'appointment_id' => $appointmentB->id,
        ])->assertSessionHasErrors('appointment_id');

        $this->post(route('invoices.store'), [
            'patient_id' => $a->id,
            'items' => [['description' => 'x', 'amount' => 100, 'treatment_plan_item_id' => $treatmentB->id]],
        ])->assertSessionHasErrors('items.0.treatment_plan_item_id');
    }

    /** A double-submitted payment must not be recorded twice. */
    public function test_the_same_payment_submitted_twice_is_only_taken_once(): void
    {
        $user = $this->actingUser();
        $invoice = Invoice::factory()->issued()->create(['created_by' => $user->id]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'cash']);
        $this->post(route('invoice-payments.store', $invoice), ['amount' => 100, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(100.0, $invoice->fresh()->load(['items', 'payments'])->amountPaid());
    }

    public function test_a_prescription_cannot_be_discontinued_twice(): void
    {
        $user = $this->actingUser();
        $patient = Patient::factory()->create();
        $prescription = Prescription::factory()->create(['patient_id' => $patient->id, 'created_by' => $user->id]);

        $route = route('prescriptions.update', ['patient' => $patient->id, 'prescription' => $prescription->id]);

        $this->patch($route, [])->assertSessionHasNoErrors();
        $this->patch($route, [])->assertForbidden();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function hostileQueryProvider(): array
    {
        return [
            'reports: start after end' => ['reports.index', ['range' => 'custom', 'start' => '2026-12-31', 'end' => '2026-01-01']],
            'reports: span past the cap' => ['reports.index', ['range' => 'custom', 'start' => '2020-01-01', 'end' => '2026-01-01']],
            'patients: overlong search' => ['patients.index', ['search' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
            'invoices: unknown status' => ['invoices.index', ['status' => 'bogus']],
            'workspace: unparseable date' => ['workspace.index', ['date' => 'nope']],
            'inventory: unknown filter' => ['inventory.index', ['filter' => 'bogus']],
        ];
    }

    #[DataProvider('hostileQueryProvider')]
    public function test_a_hostile_query_string_is_a_validation_error(string $routeName, array $query): void
    {
        $this->actingUser();

        $this->get(route($routeName, $query))->assertSessionHasErrors();
    }

    public function test_a_page_beyond_the_last_one_renders_empty_rather_than_failing(): void
    {
        $this->actingUser();
        Patient::factory()->count(3)->create();

        $this->get(route('patients.index', ['page' => 99999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('patients.data', 0));
    }

    public function test_absurd_money_and_stock_values_are_refused(): void
    {
        $user = $this->actingUser();
        $invoice = Invoice::factory()->issued()->create(['created_by' => $user->id]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->post(route('invoice-payments.store', $invoice), ['amount' => -100, 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $item = InventoryItem::factory()->create(['created_by' => $user->id]);
        $this->post(route('inventory-movements.store', $item), ['type' => 'received', 'quantity' => 0])
            ->assertSessionHasErrors('quantity');
    }

    /**
     * The calendar feed is consumed by fetch(), not by Inertia, so a
     * validation failure has to come back as JSON. A 302 to an HTML page
     * would reach FullCalendar as an unparseable body.
     */
    public function test_the_calendar_feed_rejects_a_bad_range_as_json(): void
    {
        $this->actingUser();

        $this->getJson(route('appointments.events', ['start' => '2026-05-05', 'end' => '2026-05-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end');
    }
}
