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

class IntakeLogSnoozeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected IntakeLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@other.test',
            'password' => Hash::make('password'),
        ]);

        $profile = Profile::create([
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

        $medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Ибупрофен',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $profile->id,
            'medicine_id' => $medicine->id,
            'title' => 'Курс',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        $this->log = IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $medicine->id,
            'scheduled_time' => Carbon::now()->addHours(2)->startOfMinute(),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);
    }

    public function test_user_can_snooze_intake_log(): void
    {
        $originalTime = $this->log->scheduled_time->copy();

        $response = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/snooze", [
                'minutes' => 30,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Приём лекарства успешно отложен на 30 мин.');

        $this->log->refresh();
        $this->assertEquals(
            $originalTime->addMinutes(30)->format('Y-m-d H:i'),
            $this->log->scheduled_time->format('Y-m-d H:i')
        );
    }

    public function test_snooze_defaults_to_15_minutes_when_not_specified(): void
    {
        $originalTime = $this->log->scheduled_time->copy();

        $response = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/snooze");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Приём лекарства успешно отложен на 15 мин.');

        $this->log->refresh();
        $this->assertEquals(
            $originalTime->addMinutes(15)->format('Y-m-d H:i'),
            $this->log->scheduled_time->format('Y-m-d H:i')
        );
    }

    public function test_idor_protection_stranger_cannot_snooze_log(): void
    {
        $response = $this->actingAs($this->stranger)
            ->postJson("/api/intake-logs/{$this->log->id}/snooze", [
                'minutes' => 15,
            ]);

        $response->assertStatus(403);
    }
}
