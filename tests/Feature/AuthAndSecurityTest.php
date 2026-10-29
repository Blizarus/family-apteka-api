<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
            'timezone' => 'Europe/Moscow',
            'sync_enabled' => true,
        ]);
    }

    public function test_login_successful_returns_sanctum_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'anna@family.test',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'email', 'timezone'],
            ]);

        $this->assertEquals('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'anna@family.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Неверный логин или пароль.',
            ]);
    }

    public function test_argon2id_hashing_driver_is_active(): void
    {
        $this->assertEquals('argon2id', config('hashing.driver'));
        $this->assertStringStartsWith('$argon2id$', $this->user->password);
    }

    public function test_rate_limiter_throttles_after_five_failed_attempts(): void
    {
        // 5 попыток допустимы (возвращают 401)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'anna@family.test',
                'password' => 'wrong-password-' . $i,
            ]);
            $response->assertStatus(401);
        }

        // 6-я попытка должна вернуть 429 Too Many Requests
        $response = $this->postJson('/api/auth/login', [
            'email' => 'anna@family.test',
            'password' => 'wrong-password-6',
        ]);

        $response->assertStatus(429);
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        // Без токена -> 401
        $this->getJson('/api/auth/me')
            ->assertStatus(401);

        // С токеном -> 200
        $this->actingAs($this->user)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJson([
                'id' => $this->user->id,
                'email' => 'anna@family.test',
            ]);
    }

    public function test_logout_revokes_current_access_token(): void
    {
        $token = $this->user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Сессия успешно завершена.']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
        ]);
    }
}
