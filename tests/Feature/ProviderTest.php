<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    public function test_provider_can_be_created(): void
    {
        $this->actingUser();

        $response = $this->post(route('providers.store'), [
            'name' => 'Dr. Santos',
            'specialty' => 'Orthodontics',
            'active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('providers', [
            'name' => 'Dr. Santos',
            'specialty' => 'Orthodontics',
            'active' => true,
        ]);
    }

    public function test_provider_name_is_required(): void
    {
        $this->actingUser();

        $response = $this->post(route('providers.store'), [
            'specialty' => 'Orthodontics',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_provider_can_be_updated(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create(['active' => true]);

        $response = $this->put(route('providers.update', $provider), [
            'name' => $provider->name,
            'specialty' => $provider->specialty,
            'active' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => false]);
    }

    public function test_provider_can_be_deleted(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();

        $response = $this->delete(route('providers.destroy', $provider));

        $response->assertRedirect();
        $this->assertDatabaseMissing('providers', ['id' => $provider->id]);
    }

    public function test_provider_with_appointments_cannot_be_deleted(): void
    {
        $this->actingUser();
        $provider = Provider::factory()->create();
        Appointment::factory()->create(['provider_id' => $provider->id]);

        $response = $this->delete(route('providers.destroy', $provider));

        $response->assertSessionHasErrors('provider');
        $this->assertDatabaseHas('providers', ['id' => $provider->id]);
    }

    public function test_guest_cannot_list_providers(): void
    {
        $response = $this->get(route('providers.index'));

        $response->assertRedirect(route('login'));
    }
}
