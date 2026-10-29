<?php

namespace Tests\Feature;

use App\Models\ReferenceMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'email' => 'admin@security.local',
            'password' => Hash::make('AdminPass123!'),
            'is_admin' => true,
            'sync_enabled' => true,
        ]);

        $this->regularUser = User::create([
            'email' => 'patient@family.test',
            'password' => Hash::make('PatientPass123!'),
            'is_admin' => false,
            'sync_enabled' => true,
        ]);
    }

    /**
     * 1. Внешний IP-адрес блокируется сетевым фильтром (403 Forbidden).
     * Проверка требования ЛР 2 (Таблица 1, строка 5): строгая IP-фильтрация.
     */
    public function test_external_ip_is_blocked_by_ip_filter(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Имитация запроса из внешней сети Интернет (публичный IP)
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.42',
        ])->getJson('/api/admin/users');

        $response->assertStatus(403)
            ->assertJsonPath('client_ip', '198.51.100.42');
        
        $this->assertStringContainsString('IP-фильтрация', $response->json('message'));
    }

    /**
     * 2. Обычный пользователь (не администратор) блокируется даже при запросе из доверенной сети.
     */
    public function test_non_admin_user_from_internal_ip_is_forbidden(): void
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->getJson('/api/admin/users');

        $response->assertStatus(403);
        $this->assertStringContainsString('требуются права администратора', $response->json('message'));
    }

    /**
     * 3. Администратор из доверенной внутренней сети успешно получает список учетных записей.
     */
    public function test_admin_from_internal_ip_can_view_users(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'email', 'is_admin', 'is_blocked'],
                ],
            ]);
    }

    /**
     * 4. Администратор может заблокировать пользователя, и заблокированный пользователь не может войти.
     */
    public function test_admin_can_block_user_and_blocked_user_cannot_login(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Блокировка пользователя администратором
        $blockResponse = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->postJson("/api/admin/users/{$this->regularUser->id}/block", [
            'block' => true,
        ]);

        $blockResponse->assertStatus(200);
        $this->assertTrue($blockResponse->json('user.is_blocked'));
        $this->assertDatabaseHas('users', [
            'id' => $this->regularUser->id,
            'is_blocked' => true,
        ]);

        // Попытка входа заблокированного пользователя
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'patient@family.test',
            'password' => 'PatientPass123!',
        ]);

        $loginResponse->assertStatus(403);
        $this->assertStringContainsString('заблокирована администратором', $loginResponse->json('message'));

        // Разблокировка пользователя администратором
        $unblockResponse = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->postJson("/api/admin/users/{$this->regularUser->id}/block", [
            'block' => false,
        ]);

        $unblockResponse->assertStatus(200);
        $this->assertFalse($unblockResponse->json('user.is_blocked'));

        // Повторный вход после разблокировки
        $secondLogin = $this->postJson('/api/auth/login', [
            'email' => 'patient@family.test',
            'password' => 'PatientPass123!',
        ]);

        $secondLogin->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    /**
     * 5. Администратор не может заблокировать свою собственную учетную запись.
     */
    public function test_admin_cannot_block_self(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->postJson("/api/admin/users/{$this->adminUser->id}/block", [
            'block' => true,
        ]);

        $response->assertStatus(422);
    }

    /**
     * 6. Администратор может создавать, редактировать и удалять препараты в эталонном справочнике.
     */
    public function test_admin_can_manage_reference_medicines_catalog(): void
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Создание препарата
        $createRes = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->postJson('/api/admin/reference-medicines', [
            'trade_name' => 'Ибупрофен Форте Референс',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'Капсулы',
            'dosage' => '400 мг',
            'pack_size' => 20,
            'manufacturer' => 'Фармстандарт-Лексредства',
            'country' => 'Россия',
            'barcode' => '4601234567890',
            'atc_code' => 'M01AE01',
            'is_prescription' => false,
        ]);

        $createRes->assertStatus(201);
        $medId = $createRes->json('data.id');
        $this->assertDatabaseHas('reference_medicines', [
            'id' => $medId,
            'trade_name' => 'Ибупрофен Форте Референс',
        ]);

        // 2. Редактирование препарата
        $updateRes = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->putJson("/api/admin/reference-medicines/{$medId}", [
            'trade_name' => 'Ибупрофен Макс Референс',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'Капсулы',
            'dosage' => '400 мг',
            'pack_size' => 30,
            'manufacturer' => 'Фармстандарт-Лексредства',
            'country' => 'Россия',
            'barcode' => '4601234567890',
        ]);

        $updateRes->assertStatus(200);
        $this->assertEquals('Ибупрофен Макс Референс', $updateRes->json('data.trade_name'));

        // 3. Удаление препарата
        $deleteRes = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->deleteJson("/api/admin/reference-medicines/{$medId}");

        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('reference_medicines', ['id' => $medId]);
    }
}
