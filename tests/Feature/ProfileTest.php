<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@other.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
            'notes' => 'Владелец профиля',
        ]);
    }

    public function test_user_can_list_own_profiles(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/profiles');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Анна');
    }

    public function test_user_can_create_family_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/profiles', [
                'name' => 'Бабушка Елена',
                'birth_date' => '1955-04-12',
                'avatar_url' => '👵',
                'notes' => 'Хронические заболевания',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Бабушка Елена');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->user->id,
            'name' => 'Бабушка Елена',
        ]);
    }

    public function test_idor_protection_user_cannot_access_stranger_profile(): void
    {
        $response = $this->actingAs($this->stranger)
            ->getJson("/api/profiles/{$this->profile->id}");

        $response->assertStatus(403);
    }

    public function test_idor_protection_user_cannot_update_stranger_profile(): void
    {
        $response = $this->actingAs($this->stranger)
            ->putJson("/api/profiles/{$this->profile->id}", [
                'name' => 'Измененное имя',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_own_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/profiles/{$this->profile->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('profiles', [
            'id' => $this->profile->id,
        ]);
    }
}
