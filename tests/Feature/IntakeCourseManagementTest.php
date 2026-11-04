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

class IntakeCourseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;
    protected Profile $strangerProfile;
    protected MedicineBox $box;
    protected Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@evil.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
        ]);

        $this->strangerProfile = Profile::create([
            'user_id' => $this->stranger->id,
            'name' => 'Чужой профиль',
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
            'custom_trade_name' => 'Ибупрофен 400 мг',
            'quantity' => 50.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);
    }

    public function test_user_can_update_intake_course_dosage_and_title(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Старый курс',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб. утром',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/intake-courses/{$course->id}", [
                'title' => 'Обновленный курс',
                'dosage_quantity' => 2.5,
                'dosage_instruction' => '2.5 таб. после еды',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Обновленный курс')
            ->assertJsonPath('data.dosage_instruction', '2.5 таб. после еды');

        $this->assertDatabaseHas('intake_courses', [
            'id' => $course->id,
            'title' => 'Обновленный курс',
            'dosage_instruction' => '2.5 таб. после еды',
        ]);

        $this->assertEquals(2.5, (float) $course->fresh()->dosage_quantity);
    }

    public function test_user_cannot_update_another_users_course_idor_blocked(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Анны',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->stranger)
            ->putJson("/api/intake-courses/{$course->id}", [
                'title' => 'Попытка взлома',
                'dosage_quantity' => 5.0,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('intake_courses', [
            'id' => $course->id,
            'title' => 'Курс Анны',
        ]);
    }

    public function test_deactivating_course_cancels_future_scheduled_logs(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс антибиотиков',
            'start_date' => Carbon::today()->subDays(3)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        $schedule = IntakeSchedule::create([
            'intake_course_id' => $course->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['09:00'],
            'valid_from' => $course->start_date,
        ]);

        // Прошлый выполненный приём
        $pastLog = IntakeLog::create([
            'intake_course_id' => $course->id,
            'intake_schedule_id' => $schedule->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now()->subDay(),
            'taken_time' => Carbon::now()->subDay(),
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::TAKEN,
        ]);

        // Будущий запланированный приём
        $futureLog = IntakeLog::create([
            'intake_course_id' => $course->id,
            'intake_schedule_id' => $schedule->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now()->addDays(2),
            'taken_time' => null,
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/intake-courses/{$course->id}", [
                'is_active' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($course->fresh()->is_active);

        // Прошлый лог остался
        $this->assertDatabaseHas('intake_logs', [
            'id' => $pastLog->id,
            'status' => IntakeLogStatus::TAKEN->value,
        ]);

        // Будущий scheduled лог отменен (удален)
        $this->assertDatabaseMissing('intake_logs', [
            'id' => $futureLog->id,
        ]);
    }

    public function test_user_can_extend_active_intake_course_logs(): void
    {
        $today = Carbon::today();

        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Длительный курс',
            'start_date' => $today->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 капсула',
            'is_active' => true,
        ]);

        $schedule = IntakeSchedule::create([
            'intake_course_id' => $course->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['08:00'],
            'valid_from' => $course->start_date,
        ]);

        // Создаем логи на первые 3 дня (день 0, 1, 2)
        for ($i = 0; $i < 3; $i++) {
            IntakeLog::create([
                'intake_course_id' => $course->id,
                'intake_schedule_id' => $schedule->id,
                'medicine_id' => $this->medicine->id,
                'scheduled_time' => $today->copy()->addDays($i)->setTime(8, 0),
                'taken_time' => null,
                'quantity_taken' => 1.0,
                'status' => IntakeLogStatus::SCHEDULED,
            ]);
        }

        $this->assertEquals(3, IntakeLog::where('intake_course_id', $course->id)->count());

        $response = $this->actingAs($this->user)
            ->postJson("/api/intake-courses/{$course->id}/extend");

        $response->assertStatus(200)
            ->assertJsonPath('logs_created', 14);

        // Всего должно быть 3 + 14 = 17 логов
        $this->assertEquals(17, IntakeLog::where('intake_course_id', $course->id)->count());

        // Первый продленный лог должен быть на 4-й день (today + 3 days)
        $fourthLog = IntakeLog::where('intake_course_id', $course->id)
            ->orderBy('scheduled_time', 'asc')
            ->skip(3)
            ->first();

        $userTz = $this->user->timezone ?? 'Europe/Moscow';
        $expectedFourthUtc = Carbon::createFromFormat('Y-m-d H:i', $today->copy()->addDays(3)->toDateString() . ' 08:00', $userTz)->setTimezone('UTC');
        $expectedLastUtc = Carbon::createFromFormat('Y-m-d H:i', $today->copy()->addDays(16)->toDateString() . ' 08:00', $userTz)->setTimezone('UTC');

        $this->assertEquals(
            $expectedFourthUtc->format('Y-m-d H:i'),
            $fourthLog->scheduled_time->format('Y-m-d H:i')
        );

        // Последний продленный лог должен быть на today + 16 days
        $lastLog = IntakeLog::where('intake_course_id', $course->id)
            ->orderBy('scheduled_time', 'desc')
            ->first();

        $this->assertEquals(
            $expectedLastUtc->format('Y-m-d H:i'),
            $lastLog->scheduled_time->format('Y-m-d H:i')
        );
    }

    public function test_extending_inactive_course_returns_422(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Завершенный курс',
            'start_date' => Carbon::today()->subMonth()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/intake-courses/{$course->id}/extend");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Нельзя продлить завершенный курс приёма.',
            ]);
    }
}
