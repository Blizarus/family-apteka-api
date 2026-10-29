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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;
    private MedicineBox $box;
    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'anna_quick@family.test']);
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
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
            'custom_trade_name' => 'Но-шпа',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);
    }

    /**
     * 1. Позитивный сценарий: разовый приём лекарства и списание остатка.
     */
    public function test_user_can_record_quick_intake_and_decrement_stock(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 2.0,
            'symptom' => 'Боль в животе',
            'taken_at' => Carbon::now()->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Разовый приём успешно зафиксирован, остаток списан.',
            'remaining_quantity' => 8.0,
        ]);

        // В таблице medicines остаток уменьшился ровно на 2
        $this->assertEquals(8.0, $this->medicine->fresh()->quantity);

        // В intake_courses создана запись с типом По требованию (PRN)
        $this->assertDatabaseHas('intake_courses', [
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'dosage_instruction' => 'По требованию (PRN)',
            'is_active' => true,
        ]);

        // В intake_logs создан лог со статусом taken, taken_time != null и skipped_reason = "Боль в животе"
        $log = IntakeLog::where('medicine_id', $this->medicine->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(IntakeLogStatus::TAKEN->value, $log->status->value);
        $this->assertNotNull($log->taken_time);
        $this->assertEquals('Боль в животе', $log->skipped_reason);
        $this->assertEquals(2.0, (float) $log->quantity_taken);
    }

    /**
     * 2. Тест безопасности и IDOR: запрет приёма для чужого профиля или лекарства из чужой коробки.
     */
    public function test_cannot_record_intake_for_foreign_profile_or_medicine(): void
    {
        Sanctum::actingAs($this->user);

        $stranger = User::factory()->create();
        $foreignProfile = Profile::create([
            'user_id' => $stranger->id,
            'name' => 'Чужой профиль',
        ]);

        // Попытка отправить profile_id чужого пользователя -> 403 Forbidden
        $responseProfile = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $foreignProfile->id,
            'quantity' => 1.0,
        ]);
        $responseProfile->assertStatus(403);
        $responseProfile->assertJson(['message' => 'Доступ к указанному профилю запрещен.']);

        // Попытка отправить medicine_id из чужой аптечки -> 403 Forbidden
        $strangerBox = MedicineBox::create([
            'name' => 'Чужая аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $strangerBox->id,
            'user_id' => $stranger->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
        $strangerMedicine = Medicine::create([
            'medicine_box_id' => $strangerBox->id,
            'custom_trade_name' => 'Чужое лекарство',
            'quantity' => 5.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $responseMed = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $strangerMedicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 1.0,
        ]);
        $responseMed->assertStatus(403);
        $responseMed->assertJson(['message' => 'Доступ к аптечке запрещен.']);
    }

    /**
     * 3. Валидация входных данных.
     */
    public function test_quick_intake_validation(): void
    {
        Sanctum::actingAs($this->user);

        // Отправка без medicine_id или profile_id -> 422 Unprocessable Entity
        $responseEmpty = $this->postJson('/api/intake-logs/quick', []);
        $responseEmpty->assertStatus(422);
        $responseEmpty->assertJsonValidationErrors(['medicine_id', 'profile_id']);

        // Отправка отрицательного quantity (-1) -> 422
        $responseNegative = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => -1,
        ]);
        $responseNegative->assertStatus(422);
        $responseNegative->assertJsonValidationErrors(['quantity']);

        // Отправка превышающего лимит (> 10) -> 422
        $responseExcess = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 15,
        ]);
        $responseExcess->assertStatus(422);
        $responseExcess->assertJsonValidationErrors(['quantity']);
    }

    /**
     * 4. Граничный случай списания остатка в ноль (не уходит в минус).
     */
    public function test_medicine_quantity_does_not_become_negative(): void
    {
        Sanctum::actingAs($this->user);

        // Если остаток был 1 табл., а запросили 2 — проверить, что остаток становится 0 (не уходит в минус)
        $this->medicine->update(['quantity' => 1.0]);

        $response = $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 2.0,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['remaining_quantity' => 0.0]);

        $fresh = $this->medicine->fresh();
        $this->assertEquals(0.0, $fresh->quantity);
        $this->assertEquals(MedicineStatus::FINISHED, $fresh->status);
    }

    /**
     * 5. Повторные быстрые приёмы переиспользуют существующий PRN-курс.
     */
    public function test_subsequent_quick_takes_reuse_existing_prn_course(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
        ])->assertStatus(201);

        $this->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
        ])->assertStatus(201);

        // Курс должен быть только один
        $this->assertEquals(1, IntakeCourse::where('profile_id', $this->profile->id)->where('medicine_id', $this->medicine->id)->count());

        // Лога должно быть два
        $this->assertEquals(2, IntakeLog::where('medicine_id', $this->medicine->id)->count());
        $this->assertEquals(8.0, $this->medicine->fresh()->quantity);
    }
}
