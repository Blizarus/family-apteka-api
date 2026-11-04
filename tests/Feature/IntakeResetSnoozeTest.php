<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\IntakeSchedule;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntakeResetSnoozeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;
    private IntakeLog $intakeLog;
    private Carbon $originalTimeUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'reset_snooze@family.test',
            'timezone' => 'Europe/Moscow',
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка Сброс',
            'box_type' => 'home',
        ]);
        $box->users()->attach($this->user->id, ['role' => 'owner']);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Сброс',
            'relationship' => 'self',
        ]);

        $medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Ибупрофен 400',
            'quantity' => 30,
            'quantity_unit' => 'pill',
            'status' => 'active',
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $medicine->id,
            'title' => 'Курс Ибупрофена',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'dosage_instruction' => 'После еды',
            'is_active' => true,
        ]);

        $schedule = IntakeSchedule::create([
            'intake_course_id' => $course->id,
            'frequency_type' => \App\IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['08:00'],
            'valid_from' => '2026-11-10',
        ]);

        // 08:00 MSK = 05:00 UTC
        $this->originalTimeUtc = Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-10 08:00:00', 'Europe/Moscow')->setTimezone('UTC');

        $this->intakeLog = IntakeLog::create([
            'intake_course_id' => $course->id,
            'intake_schedule_id' => $schedule->id,
            'medicine_id' => $medicine->id,
            'scheduled_time' => $this->originalTimeUtc,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);
    }

    public function test_reset_snooze_restores_original_slot_time(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Сначала откладываем на 15 минут
        $snoozeRes = $this->postJson("/api/intake-logs/{$this->intakeLog->id}/snooze", [
            'minutes' => 15,
        ]);
        $snoozeRes->assertStatus(200);

        $this->intakeLog->refresh();
        $this->assertNotEquals($this->originalTimeUtc->toDateTimeString(), $this->intakeLog->scheduled_time->toDateTimeString());

        // 2. Сбрасываем перенос назад к исходному слоту
        $resetRes = $this->postJson("/api/intake-logs/{$this->intakeLog->id}/reset-snooze");
        $resetRes->assertStatus(200);

        $this->intakeLog->refresh();
        $this->assertEquals($this->originalTimeUtc->toDateTimeString(), $this->intakeLog->scheduled_time->toDateTimeString());
    }

    public function test_reset_snooze_forbidden_for_other_user(): void
    {
        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);

        $res = $this->postJson("/api/intake-logs/{$this->intakeLog->id}/reset-snooze");
        $res->assertStatus(403);
    }
}
