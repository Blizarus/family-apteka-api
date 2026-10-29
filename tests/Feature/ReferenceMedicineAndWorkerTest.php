<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\NotificationLogType;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\NotificationLog;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ReferenceMedicineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReferenceMedicineAndWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceMedicineSeeder::class);

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    public function test_reference_medicines_index_returns_list(): void
    {
        $response = $this->getJson('/api/reference-medicines');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'query',
                'count',
                'data' => [
                    '*' => ['id', 'trade_name', 'inn', 'barcode', 'status'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(15, $response->json('count'));
    }

    public function test_reference_medicines_search_by_trade_name(): void
    {
        $response = $this->getJson('/api/reference-medicines?search=Нурофен');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertStringContainsString('Нурофен', $data[0]['trade_name']);
    }

    public function test_reference_medicines_search_by_inn(): void
    {
        $response = $this->getJson('/api/reference-medicines?search=Хлоропирамин');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals('Супрастин', $data[0]['trade_name']);
    }

    public function test_reference_medicines_search_by_barcode(): void
    {
        $response = $this->getJson('/api/reference-medicines?search=5000158066121');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals('5000158066121', $data[0]['barcode']);
    }

    public function test_check_expiry_command_scans_and_updates_medicines(): void
    {
        // 1. Создаем нормальное лекарство (годен еще 1 год)
        Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Свежий препарат',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Создаем скоро истекающее лекарство (годен еще 5 дней)
        $expiringMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Истекающий препарат',
            'quantity' => 5,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addDays(5)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 3. Создаем просроченное лекарство (просрочен на 10 дней)
        $expiredMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Просроченный препарат',
            'quantity' => 2,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->subDays(10)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Запуск artisan команды
        $this->artisan('medicines:check-expiry')
            ->expectsOutputToContain('[OK] Scanned 3 medicines. Found 1 expired, 1 expiring.')
            ->assertExitCode(0);

        // Проверяем обновленные статусы в базе
        $expiredMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $expiredMed->status);

        $expiringMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRING, $expiringMed->status);

        // Проверяем запись событий в notification_logs
        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $expiredMed->id,
            'type' => NotificationLogType::EXPIRY_CRITICAL,
            'user_id' => $this->user->id,
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $expiringMed->id,
            'user_id' => $this->user->id,
        ]);
    }
}
