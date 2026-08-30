<?php

namespace Tests\Feature;

use App\Models\DentalRecord;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\StockMovement;
use App\Models\ToothCondition;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    /**
     * Every table with a NOT NULL `created_by` restricting foreign key to
     * users.id. Three of these eight used to be guarded; the other five
     * threw a QueryException from a delete that ran *after* the user had
     * already been logged out, leaving them signed out with an intact
     * account and an un-rotated session.
     *
     * @return array<string, array{0: callable(User): \Illuminate\Database\Eloquent\Model}>
     */
    public static function authoredRecordProvider(): array
    {
        return [
            'dental record' => [fn (User $u) => DentalRecord::factory()->create(['created_by' => $u->id])],
            'tooth condition' => [fn (User $u) => ToothCondition::factory()->create(['created_by' => $u->id])],
            'treatment plan item' => [fn (User $u) => TreatmentPlanItem::factory()->create(['created_by' => $u->id])],
            'prescription' => [fn (User $u) => Prescription::factory()->create(['created_by' => $u->id])],
            'invoice' => [fn (User $u) => Invoice::factory()->create(['created_by' => $u->id])],
            'payment' => [fn (User $u) => Payment::factory()->create([
                'invoice_id' => Invoice::factory()->issued()->create(['created_by' => $u->id])->id,
                'created_by' => $u->id,
            ])],
            'inventory item' => [fn (User $u) => InventoryItem::factory()->create(['created_by' => $u->id])],
            'stock movement' => [fn (User $u) => StockMovement::factory()->create(['created_by' => $u->id])],
        ];
    }

    #[DataProvider('authoredRecordProvider')]
    public function test_a_user_who_authored_a_record_cannot_delete_their_account(callable $authorRecord): void
    {
        $user = User::factory()->create();
        $authorRecord($user);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
        // The old order logged out first, so a refused delete signed the
        // user out as a side effect of an error.
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_with_no_dental_records_can_still_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.com',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_correct_current_password_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.com',
                'current_password' => 'password',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('new-address@example.com', $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_wrong_current_password_fails(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.com',
                'current_password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_changing_only_the_name_does_not_require_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'A New Name',
                'email' => $user->email,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('A New Name', $user->fresh()->name);
    }
}
