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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CheckExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * Тест консольной команды php artisan medicines:check-expiry:
     * - Просроченное лекарство меняет статус на 'expired';
     * - Создается соответствующая запись в журнале аудита/уведомлений notification_logs.
     */
    public function test_check_expiry_command_marks_expired_medicines_and_logs_audit_event(): void
    {
        // 1. Создаем просроченное лекарство (срок истёк 7 дней назад)
        $expiredMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Просроченный аспирин',
            'quantity' => 12.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->subDays(7)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Создаем нормальное лекарство (годен ещё 1 год)
        $freshMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Свежий парацетамол',
            'quantity' => 20.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Запускаем консольную команду проверки сроков годности
        $this->artisan('medicines:check-expiry')
            ->assertExitCode(0);

        // Проверяем, что статус просроченного лекарства изменился на 'expired'
        $expiredMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $expiredMed->status);

        // Проверяем, что свежее лекарство сохранило статус 'active'
        $freshMed->refresh();
        $this->assertEquals(MedicineStatus::ACTIVE, $freshMed->status);

        // Проверяем фиксацию записи в логах аудита/уведомлений
        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $expiredMed->id,
            'type' => NotificationLogType::EXPIRY_CRITICAL,
            'user_id' => $this->user->id,
        ]);
    }
}
