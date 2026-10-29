<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\IntakeScheduleFrequencyType;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\IntakeSchedule;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntakeLogTakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Profile $profile;
    protected Medicine $medicine;
    protected IntakeCourse $course;
    protected IntakeSchedule $schedule;
    protected IntakeLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->otherUser = User::create([
            'email' => 'stranger@other.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
        ]);

        $box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Амоксициллин 500 мг',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(6)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс антибиотика',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таблетка во время еды',
            'is_active' => true,
        ]);

        $this->schedule = IntakeSchedule::create([
            'intake_course_id' => $this->course->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['13:00'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $this->log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'intake_schedule_id' => $this->schedule->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(13, 0),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);
    }

    public function test_take_intake_log_changes_status_to_taken_and_decrements_medicine_quantity(): void
    {
        $this->assertEquals(10.0, (float) $this->medicine->quantity);
        $this->assertEquals(IntakeLogStatus::SCHEDULED, $this->log->status);

        $response = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/take");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'taken')
            ->assertJsonPath('remaining_quantity', 9);

        // Проверяем статус в БД
        $this->log->refresh();
        $this->assertEquals(IntakeLogStatus::TAKEN, $this->log->status);
        $this->assertNotNull($this->log->taken_time);

        // Проверяем списание остатка (-1)
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);
    }

    public function test_idor_protection_other_user_cannot_take_stranger_log(): void
    {
        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/intake-logs/{$this->log->id}/take");

        $response->assertStatus(403);

        // Остаток не должен измениться
        $this->medicine->refresh();
        $this->assertEquals(10.0, (float) $this->medicine->quantity);
    }

    public function test_get_today_intake_logs_returns_user_logs(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/intake-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'date',
                'data' => [
                    '*' => ['id', 'status', 'scheduled_time', 'intake_course'],
                ],
            ]);

        $this->assertCount(1, $response->json('data'));
    }
}
