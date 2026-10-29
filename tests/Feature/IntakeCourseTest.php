<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
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

class IntakeCourseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;
    protected Profile $strangerProfile;
    protected MedicineBox $box;
    protected MedicineBox $strangerBox;
    protected Medicine $medicine;
    protected Medicine $strangerMedicine;

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

        $this->strangerBox = MedicineBox::create([
            'name' => 'Чужая аптечка',
            'box_type' => MedicineBoxType::CAR,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->strangerBox->id,
            'user_id' => $this->stranger->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Ибупрофен 400 мг',
            'quantity' => 20.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->strangerMedicine = Medicine::create([
            'medicine_box_id' => $this->strangerBox->id,
            'custom_trade_name' => 'Чужой препарат',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);
    }

    public function test_user_can_create_intake_course_and_generate_14_days_logs(): void
    {
        $today = Carbon::today()->toDateString();

        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->profile->id,
                'medicine_id' => $this->medicine->id,
                'title' => 'Курс от простуды',
                'start_date' => $today,
                'times' => ['08:00', '13:00', '20:00'],
                'dosage_quantity' => 1.0,
                'rule' => 'После еды • 1 таб.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Курс от простуды')
            ->assertJsonPath('logs_created', 42); // 14 дней * 3 приёма в день = 42

        // Проверяем создание курса в базе
        $this->assertDatabaseHas('intake_courses', [
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс от простуды',
        ]);

        // Проверяем создание расписаний (3 штуки)
        $this->assertEquals(3, IntakeSchedule::count());

        // Проверяем создание 42 логов
        $this->assertEquals(42, IntakeLog::count());

        // Все логи должны иметь статус scheduled
        $this->assertEquals(42, IntakeLog::where('status', IntakeLogStatus::SCHEDULED)->count());

        // Проверяем, что даты распределены от today до today + 13 дней
        $firstLog = IntakeLog::orderBy('scheduled_time', 'asc')->first();
        $lastLog = IntakeLog::orderBy('scheduled_time', 'desc')->first();

        $this->assertEquals(
            Carbon::today()->setTime(8, 0)->format('Y-m-d H:i'),
            $firstLog->scheduled_time->format('Y-m-d H:i')
        );

        $this->assertEquals(
            Carbon::today()->addDays(13)->setTime(20, 0)->format('Y-m-d H:i'),
            $lastLog->scheduled_time->format('Y-m-d H:i')
        );
    }

    public function test_idor_protection_cannot_create_course_for_stranger_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->strangerProfile->id,
                'medicine_id' => $this->medicine->id,
                'start_date' => Carbon::today()->toDateString(),
                'times' => ['09:00'],
                'dosage_quantity' => 1.0,
                'rule' => 'До еды',
            ]);

        $response->assertStatus(403);
        $this->assertEquals(0, IntakeCourse::count());
    }

    public function test_idor_protection_cannot_create_course_using_stranger_medicine(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->profile->id,
                'medicine_id' => $this->strangerMedicine->id,
                'start_date' => Carbon::today()->toDateString(),
                'times' => ['09:00'],
                'dosage_quantity' => 1.0,
                'rule' => 'До еды',
            ]);

        $response->assertStatus(403);
        $this->assertEquals(0, IntakeCourse::count());
    }

    public function test_user_can_list_own_courses_with_optional_filter(): void
    {
        // Создаем курс для Анны
        IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Анны',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        // Создаем курс для чужого профиля
        IntakeCourse::create([
            'profile_id' => $this->strangerProfile->id,
            'medicine_id' => $this->strangerMedicine->id,
            'title' => 'Чужой курс',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        // Запрос всех своих курсов
        $response = $this->actingAs($this->user)
            ->getJson('/api/intake-courses');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Курс Анны');

        // Фильтрация по своему profile_id
        $filterResponse = $this->actingAs($this->user)
            ->getJson('/api/intake-courses?profile_id=' . $this->profile->id);

        $filterResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Попытка фильтрации по чужому profile_id -> 403 Forbidden
        $idorResponse = $this->actingAs($this->user)
            ->getJson('/api/intake-courses?profile_id=' . $this->strangerProfile->id);

        $idorResponse->assertStatus(403);
    }
}
