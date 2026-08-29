<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the clock mid-month / mid-Q3 / mid-year so fixtures placed at
        // startOfMonth()->addDays(1..5) are never after "today". Laravel's
        // TestCase resets setTestNow() after each test.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_guest_cannot_view_reports(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_it_renders_with_the_default_range(): void
    {
        $this->actingUser();

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('meta.range', 'this_month')
            ->where('meta.start', now()->startOfMonth()->toDateString())
            ->where('meta.end', now()->toDateString())
            ->where('meta.bucket', 'day')
            ->has('revenue')
            ->has('appointments')
            ->has('patients')
        );
    }

    public function test_custom_range_requires_both_dates(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom']))
            ->assertSessionHasErrors('start');
    }

    public function test_custom_range_rejects_end_before_start(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-03-01', 'end' => '2026-02-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_custom_range_rejects_a_span_over_400_days(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-01']))
            ->assertSessionHasErrors('end');
    }

    public function test_bucket_granularity_follows_the_span(): void
    {
        $this->actingUser();

        // Deterministic custom spans — a calendar-relative preset can land
        // on a knife-edge (e.g. this_quarter on the 1st of a quarter).
        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-20']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'day'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'week'));

        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-09-01']))
            ->assertInertia(fn ($page) => $page->where('meta.bucket', 'month'));
    }

    public function test_ytd_label_is_the_year(): void
    {
        $this->actingUser();

        $this->get(route('reports.index', ['range' => 'ytd']))
            ->assertInertia(fn ($page) => $page->where('meta.label', (string) now()->year));
    }

    public function test_collected_revenue_total_and_trend(): void
    {
        $this->actingUser();
        $start = now()->startOfMonth();

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 300, 'paid_on' => $start->clone()->addDays(2)->toDateString()]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 200, 'paid_on' => $start->clone()->addDays(2)->toDateString()]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 999, 'paid_on' => $start->clone()->subMonth()->toDateString()]);

        $response = $this->get(route('reports.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('revenue.collected_total', 500)
            ->where('revenue.collected_trend.bucket', 'day')
        );

        $page = $response->viewData('page')['props'];
        $series = $page['revenue']['collected_trend']['series'];
        $this->assertSame((int) now()->startOfMonth()->diffInDays(now()) + 1, count($series));
        $this->assertSame(500.0, array_sum(array_column($series, 'value')));
    }

    public function test_invoiced_total_is_net_of_discount_and_excludes_void(): void
    {
        $this->actingUser();

        $a = Invoice::factory()->issued()->create(['discount_amount' => 100, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $a->id, 'amount' => 1000]);

        $void = Invoice::factory()->void()->create(['issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 5000]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.invoiced_total', 900)
        );
    }

    public function test_outstanding_ar_matches_the_invoice_balance_basis(): void
    {
        $this->actingUser();

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 400, 'paid_on' => now()->toDateString()]);

        $draft = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 9999]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.outstanding.total', 600)
            ->where('revenue.outstanding.count', 1)
        );
    }

    public function test_revenue_by_provider_splits_and_buckets_unattributed(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create(['name' => 'Dr. Reyes']);

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 700, 'provider_id' => $provider->id]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 300, 'provider_id' => null]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.by_provider', fn ($rows) => collect($rows)->firstWhere('label', 'Dr. Reyes')['value'] === 700
                && collect($rows)->firstWhere('label', 'Unattributed')['value'] === 300)
        );
    }

    public function test_revenue_by_treatment_buckets_unlinked(): void
    {
        $this->actingUser();
        $tpi = TreatmentPlanItem::factory()->create(['treatment' => 'Root Canal']);

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0, 'issued_at' => now()]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 800, 'treatment_plan_item_id' => $tpi->id]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 200, 'treatment_plan_item_id' => null]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.by_treatment', fn ($rows) => collect($rows)->firstWhere('label', 'Root Canal')['value'] === 800
                && collect($rows)->firstWhere('label', 'Ad-hoc / unlinked')['value'] === 200)
        );
    }

    public function test_payment_method_mix_zero_fills_all_methods(): void
    {
        $this->actingUser();
        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 250, 'method' => 'cash', 'paid_on' => now()->toDateString()]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->has('revenue.method_mix', 5)
            ->where('revenue.method_mix', fn ($rows) => collect($rows)->firstWhere('label', 'cash')['value'] === 250
                && collect($rows)->firstWhere('label', 'card')['value'] === 0)
        );
    }

    public function test_appointment_status_breakdown_excludes_requested_and_derives_rates(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(3)->setHour(10);

        foreach (['completed', 'completed', 'cancelled', 'no_show', 'scheduled'] as $status) {
            Appointment::factory()->create(['status' => $status, 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);
        }
        Appointment::factory()->create(['status' => 'requested', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.total', 5)
            ->where('appointments.rates.completion', 0.4)
            ->where('appointments.rates.cancellation', 0.2)
            ->where('appointments.rates.no_show', 0.2)
            ->where('appointments.status_breakdown', fn ($rows) => collect($rows)->firstWhere('label', 'completed')['value'] === 2
                && collect($rows)->firstWhere('label', 'requested') === null)
        );
    }

    public function test_appointments_by_provider_and_type(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(4)->setHour(11);
        $p = Provider::factory()->create(['name' => 'Dr. Lim']);

        Appointment::factory()->create(['provider_id' => $p->id, 'status' => 'completed', 'type' => 'cleaning', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);
        Appointment::factory()->create(['provider_id' => $p->id, 'status' => 'no_show', 'type' => 'checkup', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.by_provider', fn ($rows) => collect($rows)->firstWhere('label', 'Dr. Lim') === ['label' => 'Dr. Lim', 'total' => 2, 'completed' => 1, 'no_show' => 1])
            ->has('appointments.by_type', 4)
            ->where('appointments.by_type', fn ($rows) => collect($rows)->firstWhere('label', 'cleaning')['value'] === 1
                && collect($rows)->firstWhere('label', 'procedure')['value'] === 0)
        );
    }

    public function test_appointment_volume_trend_is_gap_filled(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(2)->setHour(9);
        Appointment::factory()->create(['status' => 'completed', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $page = $this->get(route('reports.index'))->viewData('page')['props'];
        $series = $page['appointments']['volume_trend']['series'];

        $this->assertSame((int) now()->startOfMonth()->diffInDays(now()) + 1, count($series));
        $this->assertSame(1, array_sum(array_column($series, 'value')));
    }

    public function test_rates_are_zero_when_there_are_no_appointments(): void
    {
        $this->actingUser();

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('appointments.total', 0)
            ->where('appointments.rates.completion', 0)
        );
    }

    public function test_new_patients_trend_and_total(): void
    {
        $this->actingUser();
        Patient::factory()->create(['created_at' => now()->startOfMonth()->addDay()]);
        Patient::factory()->create(['created_at' => now()->startOfMonth()->addDays(2)]);
        Patient::factory()->create(['created_at' => now()->startOfMonth()->subMonth()]);

        $response = $this->get(route('reports.index'));
        $response->assertInertia(fn ($page) => $page->where('patients.new_total', 2));

        $series = $response->viewData('page')['props']['patients']['new_trend']['series'];
        $this->assertSame(2, array_sum(array_column($series, 'value')));
    }

    public function test_returning_versus_first_visit(): void
    {
        $this->actingUser();
        $inRange = now()->startOfMonth()->addDays(3)->setHour(10);

        $returning = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $returning->id, 'status' => 'completed', 'start_time' => now()->subMonths(3), 'end_time' => now()->subMonths(3)->addMinutes(30)]);
        Appointment::factory()->create(['patient_id' => $returning->id, 'status' => 'completed', 'start_time' => $inRange, 'end_time' => $inRange->clone()->addMinutes(30)]);

        $firstTimer = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $firstTimer->id, 'status' => 'completed', 'start_time' => $inRange, 'end_time' => $inRange->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('patients.seen.returning', 1)
            ->where('patients.seen.first_visit', 1)
        );
    }

    public function test_week_bucket_keys_align_between_sql_and_php(): void
    {
        $this->actingUser();

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $inv->id, 'amount' => 500, 'paid_on' => '2026-05-13']);

        $response = $this->get(route('reports.index', ['range' => 'custom', 'start' => '2026-04-01', 'end' => '2026-06-29']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('meta.bucket', 'week'));

        $props = $response->viewData('page')['props'];
        $series = $props['revenue']['collected_trend']['series'];
        $nonZero = array_values(array_filter($series, fn ($p) => $p['value'] != 0));

        $this->assertCount(1, $nonZero);
        $this->assertSame(
            Carbon::parse('2026-05-13')->startOfWeek(Carbon::MONDAY)->toDateString(),
            $nonZero[0]['bucket'],
        );
        $this->assertSame('2026-05-11', $nonZero[0]['bucket']);
        $this->assertSame($props['revenue']['collected_total'], array_sum(array_column($series, 'value')));
    }

    public function test_custom_range_of_exactly_400_days_is_allowed(): void
    {
        $this->actingUser();

        // 2025-01-01 + 400 days = 2026-02-05.
        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2025-01-01', 'end' => '2026-02-05']))
            ->assertOk()
            ->assertSessionHasNoErrors();

        // + 401 days = 2026-02-06.
        $this->get(route('reports.index', ['range' => 'custom', 'start' => '2025-01-01', 'end' => '2026-02-06']))
            ->assertSessionHasErrors('end');
    }

    public function test_revenue_by_provider_is_not_inflated_by_line_count(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create(['name' => 'Dr. Uy']);

        $inv = Invoice::factory()->issued()->create(['discount_amount' => 0, 'issued_at' => now()]);
        foreach ([100, 200, 300] as $amount) {
            InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => $amount, 'provider_id' => $provider->id]);
        }

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('revenue.by_provider', fn ($rows) => collect($rows)->where('label', 'Dr. Uy')->count() === 1
                && collect($rows)->firstWhere('label', 'Dr. Uy')['value'] === 600)
        );
    }

    public function test_outstanding_ar_ignores_the_selected_range(): void
    {
        $this->actingUser();

        $inv = Invoice::factory()->issued()->create([
            'discount_amount' => 0,
            'issued_at' => Carbon::now()->subDays(300),
        ]);
        InvoiceItem::factory()->create(['invoice_id' => $inv->id, 'amount' => 1000]);
        Payment::factory()->create([
            'invoice_id' => $inv->id,
            'amount' => 400,
            'paid_on' => Carbon::now()->subDays(300)->toDateString(),
        ]);

        $this->get(route('reports.index', [
            'range' => 'custom',
            'start' => Carbon::now()->subDays(10)->toDateString(),
            'end' => Carbon::now()->toDateString(),
        ]))->assertInertia(fn ($page) => $page
            ->where('revenue.outstanding.total', 600)
            ->where('revenue.outstanding.count', 1)
            ->where('revenue.collected_total', 0)
        );
    }

    public function test_no_show_patients_list_is_capped_and_ordered(): void
    {
        $this->actingUser();
        $when = now()->startOfMonth()->addDays(5)->setHour(9);

        $twice = Patient::factory()->create(['first_name' => 'Nora', 'last_name' => 'Kaye']);
        Appointment::factory()->count(2)->create(['patient_id' => $twice->id, 'status' => 'no_show', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $once = Patient::factory()->create();
        Appointment::factory()->create(['patient_id' => $once->id, 'status' => 'no_show', 'start_time' => $when, 'end_time' => $when->clone()->addMinutes(30)]);

        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('patients.no_show_patients.count', 2)
            ->where('patients.no_show_patients.list.0.name', 'Nora Kaye')
            ->where('patients.no_show_patients.list.0.no_show_count', 2)
        );
    }
}
