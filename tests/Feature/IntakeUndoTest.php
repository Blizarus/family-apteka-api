<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeUndoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;
    protected MedicineBox $box;
    protected ReferenceMedicine $refMedicine;
    protected Medicine $medicine;
    protected IntakeCourse $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'undo_tester@family.test']);
        $this->stranger = User::factory()->create(['email' => 'stranger_undo@family.test']);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Undo',
            'relationship' => 'self',
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка Undo',
            'box_type' => 'home',
        ]);
        $this->box->users()->attach($this->user->id, ['role' => 'owner']);

        $this->refMedicine = ReferenceMedicine::create([
            'trade_name' => 'Парацетамол Undo',
            'inn' => 'Paracetamol',
            'atc_code' => 'N02BE01',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 20,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $this->refMedicine->id,
            'custom_trade_name' => 'Парацетамол Undo',
            'form' => 'таблетки',
            'quantity' => 10.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addMonths(12),
            'status' => 'active',
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Парацетамола',
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб при температуре',
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);
    }

    /**
     * 1. Откат приёма с обычным списанием: остаток восстанавливается на фактически списанное количество.
     */
    public function test_01_undo_normal_take_restores_deducted_quantity(): void
    {
        $log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Приём: списывает 1.0
        $takeResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/take");
        $takeResponse->assertStatus(200);

        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        $log->refresh();
        $this->assertEquals(1.0, (float) $log->deducted_quantity);
        $this->assertEquals(IntakeLogStatus::TAKEN, $log->status);

        // Откат (Undo)
        $undoResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/undo");

        $undoResponse->assertStatus(200);
        $this->assertEquals(1.0, (float) $undoResponse->json('refunded_quantity'));
        $this->assertEquals(10.0, (float) $undoResponse->json('remaining_quantity'));

        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);

        $log->refresh();
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $log->status);
        $this->assertNull($log->taken_time);
        $this->assertEquals(0.0, (float) $log->deducted_quantity);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->user->id,
            'type' => 'intake_undo',
        ]);
    }

    /**
     * 2. Откат приёма через confirm_without_stock: остаток не меняется (deducted_quantity = 0).
     */
    public function test_02_undo_take_via_confirm_without_stock_does_not_change_stock(): void
    {
        // Устанавливаем нулевой остаток
        $this->medicine->update(['quantity' => 0.0, 'status' => 'finished']);

        $log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Приём без списания при нехватке на складе
        $takeResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/take", [
                'confirm_without_stock' => true,
            ]);
        $takeResponse->assertStatus(200);
        $takeResponse->assertJsonPath('stock_warning', true);

        $log->refresh();
        $this->assertEquals(0.0, (float) $log->deducted_quantity);
        $this->assertEquals(IntakeLogStatus::TAKEN, $log->status);

        $this->medicine->refresh();
        $this->assertEquals(0.0, (float) $this->medicine->quantity);

        // Откат (Undo)
        $undoResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/undo");

        $undoResponse->assertStatus(200);
        $this->assertEquals(0.0, (float) $undoResponse->json('refunded_quantity'));
        $this->assertEquals(0.0, (float) $undoResponse->json('remaining_quantity'));

        // Остаток не должен стать 1.0 (не было списания - не должно быть возврата!)
        $this->medicine->refresh();
        $this->assertEquals(0.0, (float) $this->medicine->quantity);

        $log->refresh();
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $log->status);
        $this->assertEquals(0.0, (float) $log->deducted_quantity);
    }

    /**
     * 3. Повторный откат: повторный вызов идемпотентен (возвращает 200, не списывает и не возвращает лишнего).
     */
    public function test_03_repeated_undo_is_idempotent_and_does_not_double_refund(): void
    {
        $log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Приём
        $this->actingAs($this->user)->postJson("/api/intake-logs/{$log->id}/take");

        // Первый откат
        $undo1 = $this->actingAs($this->user)->postJson("/api/intake-logs/{$log->id}/undo");
        $undo1->assertStatus(200);
        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);

        // Повторный откат того же лога
        $undo2 = $this->actingAs($this->user)->postJson("/api/intake-logs/{$log->id}/undo");
        $undo2->assertStatus(200);
        $this->assertEquals(0.0, (float) $undo2->json('refunded_quantity'));

        // Остаток не должен вырасти до 11.0!
        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);
    }

    /**
     * 4. Откат пропуска: переводит статус из SKIPPED в SCHEDULED, очищает причину пропуска.
     */
    public function test_04_undo_skipped_intake_resets_to_scheduled(): void
    {
        $log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Пропуск
        $skipResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/skip", ['reason' => 'Тошнота']);
        $skipResponse->assertStatus(200);

        $log->refresh();
        $this->assertEquals(IntakeLogStatus::SKIPPED, $log->status);

        // Откат пропуска
        $undoResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/undo");

        $undoResponse->assertStatus(200);
        $log->refresh();
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $log->status);
        $this->assertNull($log->skipped_reason);

        // Остаток лекарства не менялся
        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);
    }

    /**
     * 5. Откат от чужого пользователя (IDOR): отклоняется с кодом 403 Forbidden.
     */
    public function test_05_undo_from_foreign_user_is_forbidden_403(): void
    {
        $log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        $this->actingAs($this->user)->postJson("/api/intake-logs/{$log->id}/take");

        // Чужой пользователь пытается откатить лог
        $foreignResponse = $this->actingAs($this->stranger)
            ->postJson("/api/intake-logs/{$log->id}/undo");

        $foreignResponse->assertStatus(403);

        // Остаток не должен измениться
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);
    }

    /**
     * 6. DEF-28: Откат приёма без списания (confirm_without_stock):
     * не изменяет складской баланс, не переводит finished препарат в active,
     * возвращает refunded_quantity = 0.0 и создает честное уведомление.
     */
    public function test_undo_intake_confirmed_without_stock_does_not_modify_inventory_balance(): void
    {
        $emptyMedicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $this->refMedicine->id,
            'custom_trade_name' => 'Закончившийся Парацетамол',
            'form' => 'таблетки',
            'quantity' => 0.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addMonths(6),
            'status' => 'finished',
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $emptyMedicine->id,
            'title' => 'Курс без остатка',
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб по схеме',
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        $log = IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $emptyMedicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Приём с confirm_without_stock = true
        $takeResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/take", [
                'confirm_without_stock' => true,
            ]);
        $takeResponse->assertStatus(200);

        $log->refresh();
        $this->assertEquals(IntakeLogStatus::TAKEN, $log->status);
        $this->assertEquals(0.0, (float) $log->deducted_quantity);

        // Откат приёма (Undo)
        $undoResponse = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$log->id}/undo");

        $undoResponse->assertStatus(200);
        $this->assertEquals(0.0, (float) $undoResponse->json('refunded_quantity'));
        $this->assertEquals(0.0, (float) $undoResponse->json('remaining_quantity'));

        $log->refresh();
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $log->status);

        $emptyMedicine->refresh();
        $this->assertEquals(0.0, (float) $emptyMedicine->quantity);
        $statusVal = is_string($emptyMedicine->status) ? $emptyMedicine->status : $emptyMedicine->status->value;
        $this->assertEquals('finished', $statusVal);

        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => IntakeLog::class,
            'entity_id' => $log->id,
            'message' => "Приём препарата #{$log->id} отменён. Препарат был принят без списания остатка, складской баланс не изменялся.",
        ]);
    }
}
