<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The lists that used to load every row and filter in PHP.
 *
 * Two things have to stay true now that the money and stock figures exist
 * twice — once as PHP over loaded relations, once as SQL: they must agree,
 * and the pages must stay bounded no matter how much data there is.
 */
class ListQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, array{0: float, 1: array<int, float>, 2: array<int, float>}>
     */
    public static function moneyProvider(): array
    {
        return [
            'no discount, part paid' => [0, [1000, 500], [300]],
            'discount, unpaid' => [200, [1000], []],
            'discount, fully paid' => [250, [1000, 250], [1000]],
            'overpaid is not possible but must still agree' => [0, [100], [100]],
            'fractional amounts' => [0.55, [10.10, 20.20], [5.05]],
            'no items at all' => [0, [], []],
        ];
    }

    #[DataProvider('moneyProvider')]
    public function test_the_sql_money_figures_match_the_php_ones(float $discount, array $amounts, array $payments): void
    {
        $invoice = Invoice::factory()->issued()->create(['discount_amount' => $discount]);

        foreach ($amounts as $amount) {
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);
        }
        foreach ($payments as $amount) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => $amount]);
        }

        $fromPhp = $invoice->fresh()->load(['items', 'payments']);
        $fromSql = Invoice::withMoney()->whereKey($invoice->id)->first();

        $this->assertSame($fromPhp->subtotal(), round((float) $fromSql->subtotal_sum, 2), 'subtotal');
        $this->assertSame($fromPhp->total(), round((float) $fromSql->total_sum, 2), 'total');
        $this->assertSame($fromPhp->amountPaid(), round((float) $fromSql->amount_paid_sum, 2), 'amount paid');
        $this->assertSame($fromPhp->balance(), round((float) $fromSql->balance_sum, 2), 'balance');
        $this->assertSame($fromPhp->isPaid(), $fromSql->toListArray()['is_paid'], 'is_paid');
    }

    public function test_the_outstanding_scope_matches_the_php_definition(): void
    {
        $this->actingUser();

        $unpaid = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $unpaid->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $unpaid->id, 'amount' => 400]);

        $settled = Invoice::factory()->issued()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $settled->id, 'amount' => 500]);
        Payment::factory()->create(['invoice_id' => $settled->id, 'amount' => 500]);

        // A draft with a balance is not outstanding — it has not been issued.
        $draft = Invoice::factory()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $draft->id, 'amount' => 999]);

        // Nor is a void one.
        $void = Invoice::factory()->void()->create(['discount_amount' => 0]);
        InvoiceItem::factory()->create(['invoice_id' => $void->id, 'amount' => 999]);

        $this->assertSame([$unpaid->id], Invoice::outstanding()->pluck('invoices.id')->all());
        $this->assertSame([$settled->id], Invoice::settled()->pluck('invoices.id')->all());
        $this->assertSame(600.0, round((float) Invoice::outstanding()->sum(DB::raw(Invoice::balanceSql())), 2));
    }

    public function test_the_dashboard_and_reports_agree_on_outstanding(): void
    {
        $this->actingUser();

        $invoice = Invoice::factory()->issued()->create(['discount_amount' => 100]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250]);

        $dashboard = $this->get(route('dashboard'))->viewData('page')['props']['outstanding'];
        $reports = $this->get(route('reports.index'))->viewData('page')['props']['revenue']['outstanding'];

        $this->assertSame(650.0, $dashboard['total']);
        $this->assertSame(1, $dashboard['count']);
        $this->assertSame($dashboard['total'], $reports['total']);
        $this->assertSame($dashboard['count'], $reports['count']);
    }

    public function test_the_invoice_list_is_paginated(): void
    {
        $this->actingUser();
        Invoice::factory()->count(30)->create();

        $this->get(route('invoices.index'))->assertInertia(fn ($page) => $page
            ->has('invoices.data', 25)
            ->where('invoices.total', 30)
            ->where('summary.count', 30)
        );
    }

    /**
     * The strip above the list answers "how much is outstanding", which
     * must mean across the whole filtered set, not across this page.
     */
    public function test_the_invoice_summary_covers_every_page(): void
    {
        $this->actingUser();

        foreach (range(1, 30) as $i) {
            $invoice = Invoice::factory()->issued()->create(['discount_amount' => 0]);
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);
        }

        $this->get(route('invoices.index'))->assertInertia(fn ($page) => $page
            ->has('invoices.data', 25)
            ->where('summary.outstanding', 3000)
        );
    }

    public function test_invoices_can_be_searched_by_patient_and_number(): void
    {
        $this->actingUser();
        $patient = Patient::factory()->create(['first_name' => 'Angela', 'last_name' => 'Reyes']);
        $target = Invoice::factory()->create(['patient_id' => $patient->id]);
        Invoice::factory()->count(3)->create();

        foreach (['Angela', 'reyes', 'Angela Reyes', $target->number(), (string) $target->id] as $term) {
            $this->get(route('invoices.index', ['search' => $term]))
                ->assertInertia(fn ($page) => $page
                    ->has('invoices.data', 1)
                    ->where('invoices.data.0.id', $target->id),
                );
        }
    }

    public function test_the_inventory_list_is_paginated(): void
    {
        $this->actingUser();
        InventoryItem::factory()->count(40)->create();

        $this->get(route('inventory.index'))->assertInertia(fn ($page) => $page
            ->has('items.data', 30)
            ->where('items.total', 40)
        );
    }

    /**
     * The low-stock and expiring filters are now WHERE clauses over a
     * correlated subquery rather than a PHP filter over every ledger.
     */
    public function test_the_inventory_filters_still_bucket_correctly(): void
    {
        $this->actingUser();

        $low = InventoryItem::factory()->create(['reorder_threshold' => 10]);
        StockMovement::factory()->create(['inventory_item_id' => $low->id, 'type' => 'received', 'quantity' => 5]);

        $healthy = InventoryItem::factory()->create(['reorder_threshold' => 2]);
        StockMovement::factory()->create(['inventory_item_id' => $healthy->id, 'type' => 'received', 'quantity' => 50]);

        // Exactly at the threshold counts as low.
        $atThreshold = InventoryItem::factory()->create(['reorder_threshold' => 7]);
        StockMovement::factory()->create(['inventory_item_id' => $atThreshold->id, 'type' => 'received', 'quantity' => 7]);

        // An item with no movements at all is at zero, which is low.
        $empty = InventoryItem::factory()->create(['reorder_threshold' => 1]);

        $lowIds = InventoryItem::lowStock()->pluck('inventory_items.id')->sort()->values()->all();

        $this->assertSame(
            collect([$low->id, $atThreshold->id, $empty->id])->sort()->values()->all(),
            $lowIds,
        );
        $this->assertNotContains($healthy->id, $lowIds);
    }

    public function test_an_archived_item_is_never_low_or_expiring(): void
    {
        $this->actingUser();

        $archived = InventoryItem::factory()->create([
            'reorder_threshold' => 10,
            'expiry_date' => now()->addDays(3)->toDateString(),
            'active' => false,
        ]);

        $this->assertNotContains($archived->id, InventoryItem::lowStock()->pluck('inventory_items.id')->all());
        $this->assertNotContains($archived->id, InventoryItem::expiringSoon()->pluck('inventory_items.id')->all());
    }

    /**
     * dueForRecall() ran on every dashboard load and used to read every
     * patient and every cleaning they had ever had. It is now one joined
     * query — so it must issue a bounded number of them regardless of how
     * many patients exist.
     */
    public function test_due_for_recall_does_not_scale_its_query_count_with_patients(): void
    {
        Patient::factory()->count(20)->create()->each(function (Patient $patient) {
            Appointment::factory()->create([
                'patient_id' => $patient->id,
                'type' => 'cleaning',
                'status' => 'completed',
                'start_time' => now()->subMonths(9),
                'end_time' => now()->subMonths(9)->addMinutes(30),
            ]);
        });

        DB::enableQueryLog();
        $due = Patient::dueForRecall();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $due);
        $this->assertSame(1, $queries, 'dueForRecall() should be a single query');
    }

    public function test_the_dashboard_issues_a_bounded_number_of_queries(): void
    {
        $this->actingUser();

        Patient::factory()->count(25)->create()->each(function (Patient $patient) {
            $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id, 'discount_amount' => 0]);
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);
            InventoryItem::factory()->create();
        });

        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Generous, but far below the per-row reads this used to do; the
        // point is that it does not grow with the number of patients,
        // invoices, or inventory items.
        $this->assertLessThan(25, $queries, "dashboard issued {$queries} queries");
    }
}
