<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'encryption_test@family.test',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * 1. Чувствительные клинические поля allergies и chronic_conditions
     * шифруются в базе данных алгоритмом AES-256 (Data at Rest, 152-ФЗ).
     */
    public function test_allergies_and_chronic_conditions_are_encrypted_at_rest_in_raw_database(): void
    {
        $profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с секретным диагнозом',
            'allergies' => ['penicillin', 'citrus'],
            'chronic_conditions' => ['hypertension', 'gastritis'],
        ]);

        // Читаем сырые данные напрямую из БД в обход Eloquent-дешифрования
        $rawProfile = DB::table('profiles')->where('id', $profile->id)->first();

        // 1) В сырой БД не должно быть открытого текста клинических диагнозов и аллергенов!
        $this->assertStringNotContainsString('penicillin', (string) $rawProfile->allergies, 'Сырое поле allergies в БД не должно содержать открытый текст!');
        $this->assertStringNotContainsString('gastritis', (string) $rawProfile->chronic_conditions, 'Сырое поле chronic_conditions в БД не должно содержать открытый текст!');

        // 2) Сырые значения должны успешно расшифровываться через Crypt::decryptString (AES-256)
        $decryptedAllergiesJson = Crypt::decryptString($rawProfile->allergies);
        $this->assertJson($decryptedAllergiesJson);
        $this->assertEquals(['penicillin', 'citrus'], json_decode($decryptedAllergiesJson, true));

        $decryptedConditionsJson = Crypt::decryptString($rawProfile->chronic_conditions);
        $this->assertJson($decryptedConditionsJson);
        $this->assertEquals(['hypertension', 'gastritis'], json_decode($decryptedConditionsJson, true));
    }

    /**
     * 2. При доступе через модель Eloquent данные прозрачно расшифровываются в массив.
     */
    public function test_eloquent_transparently_casts_encrypted_array_fields(): void
    {
        $profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Миша',
            'allergies' => ['citrus', 'lactose'],
            'chronic_conditions' => ['asthma'],
        ]);

        $freshProfile = Profile::find($profile->id);

        $this->assertIsArray($freshProfile->allergies);
        $this->assertEquals(['citrus', 'lactose'], $freshProfile->allergies);

        $this->assertIsArray($freshProfile->chronic_conditions);
        $this->assertEquals(['asthma'], $freshProfile->chronic_conditions);
    }

    /**
     * 3. API эндпоинты возвращают расшифрованные данные в JSON-ответе клиенту.
     */
    public function test_api_endpoints_work_seamlessly_with_encrypted_fields(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/profiles', [
            'name' => 'Дедушка Иван',
            'allergies' => ['aspirin'],
            'chronic_conditions' => ['diabetes'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.allergies', ['aspirin'])
            ->assertJsonPath('data.chronic_conditions', ['diabetes']);

        $profileId = $response->json('data.id');

        // Проверяем сырое шифрование в БД
        $raw = DB::table('profiles')->where('id', $profileId)->first();
        $this->assertStringNotContainsString('aspirin', (string) $raw->allergies);
        $this->assertStringNotContainsString('diabetes', (string) $raw->chronic_conditions);
    }
}
