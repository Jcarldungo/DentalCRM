<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

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
}
