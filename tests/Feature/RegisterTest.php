<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_registration_creates_profile_and_default_box(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'email'],
                'profile' => ['id', 'name'],
                'default_box' => ['id', 'name'],
            ]);

        // 1. Проверяем пользователя и Argon2id хеш
        $user = User::where('email', 'ivan@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));

        // 2. Проверяем автоматически созданный профиль
        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('Иван Иванов', $profile->name);

        // 3. Проверяем автоматически созданную аптечку и роль owner
        $box = MedicineBox::whereHas('users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })->first();

        $this->assertNotNull($box);
        $this->assertEquals('Домашняя аптечка', $box->name);

        $pivot = MedicineBoxUser::where('medicine_box_id', $box->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertEquals('owner', $pivot->role->value ?? (string) $pivot->role);
    }

    public function test_registration_validation_fails_on_duplicate_email(): void
    {
        User::create([
            'email' => 'duplicate@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Тест',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_validation_fails_on_password_mismatch(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Тест',
            'email' => 'mismatch@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different-password',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
