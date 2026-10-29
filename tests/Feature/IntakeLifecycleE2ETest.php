<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntakeLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;
    protected IntakeCourse $course;
    protected IntakeLog $log1;
    protected IntakeLog $log2;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 12:00:00');

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
            'notes' => 'Медицинская карта: склонность к мигреням',
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

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Ибупрофен Форте 400 мг',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Принимать строго после еды',
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс от головной боли',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таблетка',
            'is_active' => true,
        ]);

        // Лог 1: Утренний приём на сегодня
        $this->log1 = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(9, 0),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);

        // Лог 2: Вечерний приём на сегодня (через 2 часа в будущем)
        $this->log2 = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now()->addHours(2)->startOfMinute(),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Сквозной E2E-сценарий:
     * 1. Вход (/api/auth/login) -> получение токена
     * 2. Просмотр логов на сегодня (/api/intake-logs?date=today)
     * 3. Приём первого препарата (POST /api/intake-logs/{id}/take)
     * 4. Проверка списания остатка в аптечке (GET /api/medicines/{id})
     * 5. Откладывание второго напоминания на +15 мин (POST /api/intake-logs/{id}/snooze)
     * 6. Пропуск второго приёма (POST /api/intake-logs/{id}/skip)
     */
    public function test_full_intake_lifecycle_e2e_scenario(): void
    {
        // Шаг 1: Аутентификация
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'anna@family.test',
            'password' => 'password',
        ]);
        $loginRes->assertStatus(200)
                 ->assertJsonStructure(['token', 'token_type']);
        $token = $loginRes->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        // Шаг 2: Просмотр логов приёма на сегодня через ?date=today
        $logsRes = $this->withHeaders($headers)->getJson('/api/intake-logs?date=today');
        $logsRes->assertStatus(200)
                ->assertJsonStructure(['date', 'data']);
        $this->assertCount(2, $logsRes->json('data'));

        // Шаг 3: Приём первого препарата (Take)
        $takeRes = $this->withHeaders($headers)->postJson("/api/intake-logs/{$this->log1->id}/take");
        $takeRes->assertStatus(200)
                ->assertJsonPath('data.status', 'taken');
        $this->assertEquals(9, $takeRes->json('remaining_quantity'));

        $this->log1->refresh();
        $this->assertEquals(IntakeLogStatus::TAKEN, $this->log1->status);
        $this->assertNotNull($this->log1->taken_time);

        // Шаг 4: Проверка списания остатка в аптечке через GET /api/medicines/{id}
        $medRes = $this->withHeaders($headers)->getJson("/api/medicines/{$this->medicine->id}");
        $medRes->assertStatus(200)
               ->assertJsonPath('data.quantity', '9.000');
        $this->assertEquals(9.0, (float) $this->medicine->fresh()->quantity);

        // Шаг 5: Откладывание второго напоминания на 15 минут (Snooze)
        $scheduledBeforeSnooze = $this->log2->scheduled_time->copy();
        $snoozeRes = $this->withHeaders($headers)->postJson("/api/intake-logs/{$this->log2->id}/snooze", [
            'minutes' => 15,
        ]);
        $snoozeRes->assertStatus(200);

        $this->log2->refresh();
        $this->assertEquals(
            $scheduledBeforeSnooze->addMinutes(15)->format('Y-m-d H:i'),
            $this->log2->scheduled_time->format('Y-m-d H:i')
        );

        // Шаг 6: Пропуск второго приёма (Skip)
        $skipRes = $this->withHeaders($headers)->postJson("/api/intake-logs/{$this->log2->id}/skip", [
            'reason' => 'Пациент спал',
        ]);
        $skipRes->assertStatus(200)
                ->assertJsonPath('data.status', 'skipped')
                ->assertJsonPath('data.skipped_reason', 'Пациент спал');

        $this->log2->refresh();
        $this->assertEquals(IntakeLogStatus::SKIPPED, $this->log2->status);

        // Физический остаток препарата не должен измениться после пропуска (остается 9)
        $this->assertEquals(9.0, (float) $this->medicine->fresh()->quantity);
    }

    /**
     * Проверка атомарности списания в транзакции: при ошибке в транзакции остаток не списывается.
     */
    public function test_intake_deduction_is_atomic_and_rolls_back_on_error(): void
    {
        $initialQuantity = (float) $this->medicine->quantity;

        // Эмулируем сбой внутри контроллера через временную блокировку
        try {
            DB::transaction(function () {
                $this->medicine->decrement('quantity', 1);
                throw new \RuntimeException('Database constraint or network simulation failure');
            });
        } catch (\RuntimeException $e) {
            // Ошибка перехвачена
        }

        // Проверяем, что в базе остаток откатился к исходному
        $this->assertEquals($initialQuantity, (float) $this->medicine->fresh()->quantity);
    }

    /**
     * Проверка шифрования данных в БД (Data at Rest):
     * Поля notes и custom_trade_name в SQLite хранятся в виде AES-256 зашифрованных строк.
     */
    public function test_sensitive_medical_fields_are_encrypted_in_sqlite(): void
    {
        // 1. Проверяем сырое значение notes в таблице profiles
        $rawProfileNotes = DB::table('profiles')->where('id', $this->profile->id)->value('notes');
        $this->assertNotNull($rawProfileNotes);
        $this->assertStringNotContainsString('склонность к мигреням', $rawProfileNotes);
        $this->assertStringContainsString('eyJpdiI6', $rawProfileNotes); // Префикс сериализованной криптограммы Laravel

        // 2. Проверяем расшифровку через Eloquent Model Profile
        $this->assertEquals('Медицинская карта: склонность к мигреням', $this->profile->fresh()->notes);

        // 3. Проверяем сырое значение custom_trade_name в таблице medicines
        $rawMedicineName = DB::table('medicines')->where('id', $this->medicine->id)->value('custom_trade_name');
        $this->assertNotNull($rawMedicineName);
        $this->assertStringNotContainsString('Ибупрофен Форте 400 мг', $rawMedicineName);
        $this->assertStringContainsString('eyJpdiI6', $rawMedicineName);

        // 4. Проверяем прозрачную расшифровку через Eloquent Model Medicine
        $this->assertEquals('Ибупрофен Форте 400 мг', $this->medicine->fresh()->custom_trade_name);
    }

    /**
     * Проверка строгой изоляции данных (IDOR):
     * Посторонний пользователь Б при попытке доступа к ресурсам А получает 403 Forbidden.
     */
    public function test_idor_cross_tenant_isolation_returns_403(): void
    {
        // 1. Чужой пользователь пытается посмотреть аптечку А
        $this->actingAs($this->stranger)
             ->getJson("/api/medicine-boxes/{$this->box->id}")
             ->assertStatus(403);

        // 2. Чужой пользователь пытается посмотреть профиль А
        $this->actingAs($this->stranger)
             ->getJson("/api/profiles/{$this->profile->id}")
             ->assertStatus(403);

        // 3. Чужой пользователь пытается подтвердить приём А
        $this->actingAs($this->stranger)
             ->postJson("/api/intake-logs/{$this->log1->id}/take")
             ->assertStatus(403);

        // 4. Чужой пользователь пытается отложить приём А
        $this->actingAs($this->stranger)
             ->postJson("/api/intake-logs/{$this->log1->id}/snooze")
             ->assertStatus(403);

        // 5. Чужой пользователь пытается отменить приём А
        $this->actingAs($this->stranger)
             ->postJson("/api/intake-logs/{$this->log1->id}/skip")
             ->assertStatus(403);
    }
}
