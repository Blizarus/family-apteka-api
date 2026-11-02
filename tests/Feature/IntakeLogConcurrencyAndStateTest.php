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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntakeLogConcurrencyAndStateTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $editor;
    protected User $viewer;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;
    protected IntakeCourse $course;
    protected IntakeLog $scheduledLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'email' => 'owner_state@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->editor = User::create([
            'email' => 'editor_state@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->viewer = User::create([
            'email' => 'viewer_state@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->owner->id,
            'name' => 'Пациент Тест',
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Семейная аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->editor->id,
            'role' => MedicineBoxUserRole::EDITOR,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->viewer->id,
            'role' => MedicineBoxUserRole::VIEWER,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Ибупрофен 400',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addMonths(6)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс ибупрофена',
            'dosage_instruction' => 'По 1 таблетке',
            'dosage_quantity' => 1.0,
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        $this->scheduledLog = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(14, 0),
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);
    }

    /**
     * 1. Запрет skip() на уже принятый лог (возврат 409 Conflict).
     */
    public function test_skip_forbidden_on_already_taken_log_returns_409(): void
    {
        // Сначала принимаем лекарство
        $takeRes = $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/take");
        $takeRes->assertStatus(200);

        // Попытка пометить принятый приём как пропущенный должна вернуть 409
        $skipRes = $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/skip", [
                'reason' => 'Забыл выпить',
            ]);

        $skipRes->assertStatus(409);
        $this->scheduledLog->refresh();
        $this->assertEquals(IntakeLogStatus::TAKEN, $this->scheduledLog->status);
    }

    /**
     * 2. Запрет snooze() на принятый или пропущенный лог (возврат 409 Conflict).
     */
    public function test_snooze_forbidden_on_taken_or_skipped_log_returns_409(): void
    {
        // Проверяем на принятом
        $this->scheduledLog->update(['status' => IntakeLogStatus::TAKEN]);
        $res1 = $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/snooze", ['minutes' => 15]);
        $res1->assertStatus(409);

        // Проверяем на пропущенном
        $this->scheduledLog->update(['status' => IntakeLogStatus::SKIPPED]);
        $res2 = $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/snooze", ['minutes' => 15]);
        $res2->assertStatus(409);
    }

    /**
     * 3. Отмена приёма POST /api/intake-logs/{id}/undo:
     * возврат остатка препарату, сброс статуса в SCHEDULED.
     */
    public function test_undo_taken_log_restores_medicine_stock_and_resets_status(): void
    {
        $this->assertEquals(10.0, (float) $this->medicine->quantity);

        // 1) Выполняем приём (остаток уменьшается с 10 до 9)
        $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/take")
            ->assertStatus(200);

        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // 2) Выполняем undo
        $undoRes = $this->actingAs($this->owner)
            ->postJson("/api/intake-logs/{$this->scheduledLog->id}/undo");

        $undoRes->assertStatus(200)
            ->assertJsonPath('data.status', IntakeLogStatus::SCHEDULED->value);
        $this->assertEquals(10.0, (float) $undoRes->json('remaining_quantity'));

        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);

        $this->scheduledLog->refresh();
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $this->scheduledLog->status);
        $this->assertNull($this->scheduledLog->taken_time);
    }

    /**
     * 4. Идемпотентность quickTake() при повторном нажатии.
     */
    public function test_quick_take_is_idempotent_against_rapid_double_clicks(): void
    {
        $this->assertEquals(10.0, (float) $this->medicine->quantity);

        // Первый клик
        $res1 = $this->actingAs($this->owner)->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 1.0,
            'symptom' => 'Головная боль',
            'idempotency_key' => 'double_tap_prn_123',
        ]);
        $res1->assertStatus(201);

        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // Мгновенный второй клик (double-tap)
        $res2 = $this->actingAs($this->owner)->postJson('/api/intake-logs/quick', [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'quantity' => 1.0,
            'symptom' => 'Головная боль',
            'idempotency_key' => 'double_tap_prn_123',
        ]);
        $this->assertTrue(in_array($res2->status(), [200, 201])); // Сохраненный успешный ответ без повторного списания

        $this->medicine->refresh();
        // Остаток не должен стать 8.0!
        $this->assertEquals(9.0, (float) $this->medicine->quantity);
    }

    /**
     * 5. Права удаления препарата: редактор (EDITOR) может удалять лекарство,
     * а наблюдатель (VIEWER) получает 403 Forbidden.
     * Препарат с привязанными курсами защищен от случайного удаления (409 Conflict).
     */
    public function test_medicine_policy_allows_editor_to_delete_and_forbids_viewer(): void
    {
        $standaloneMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Активированный уголь',
            'quantity' => 20.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addMonths(12)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 1) Наблюдатель (VIEWER) пробует удалить -> 403 Forbidden
        $this->actingAs($this->viewer)
            ->deleteJson("/api/medicines/{$standaloneMed->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('medicines', ['id' => $standaloneMed->id]);

        // 2) Препарат с активными курсами приёма защищен от удаления -> 409 Conflict
        $this->actingAs($this->editor)
            ->deleteJson("/api/medicines/{$this->medicine->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('medicines', ['id' => $this->medicine->id]);

        // 3) Редактор (EDITOR) удаляет свободный препарат -> 200 OK
        $this->actingAs($this->editor)
            ->deleteJson("/api/medicines/{$standaloneMed->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('medicines', ['id' => $standaloneMed->id]);
    }
}
