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

class IntakeCourseGenerationTest extends TestCase
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

        // 1. Авторизованный пользователь (Анна)
        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        // 2. Посторонний пользователь (Злоумышленник)
        $this->stranger = User::create([
            'email' => 'stranger@evil.test',
            'password' => Hash::make('password'),
        ]);

        // 3. Профиль Анны
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
        ]);

        // 4. Профиль постороннего пользователя
        $this->strangerProfile = Profile::create([
            'user_id' => $this->stranger->id,
            'name' => 'Чужой профиль',
        ]);

        // 5. Аптечка и препарат
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
            'custom_trade_name' => 'Амоксициллин 500 мг',
            'quantity' => 30.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);
    }

    /**
     * Тест 1: Проверка, что при создании курса на 2 приёма в день
     * генерируется ровно 28 логов со статусом 'scheduled' на 14 дней вперед.
     */
    public function test_creating_course_generates_intake_logs_for_14_days(): void
    {
        $today = Carbon::today()->toDateString();

        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->profile->id,
                'medicine_id' => $this->medicine->id,
                'title' => 'Курс антибиотика 14 дней',
                'start_date' => $today,
                'times' => ['09:00', '21:00'], // 2 приёма в день
                'dosage_quantity' => 1.0,
                'rule' => 'После еды • 1 капсула',
            ]);

        // Статус 201 Created
        $response->assertStatus(201)
            ->assertJsonPath('logs_created', 28) // 14 дней * 2 приёма = 28
            ->assertJsonPath('data.title', 'Курс антибиотика 14 дней');

        // Проверяем создание курса в базе данных
        $course = IntakeCourse::where('title', 'Курс антибиотика 14 дней')->first();
        $this->assertNotNull($course);

        // Проверяем количество созданных логов: ровно 28
        $logs = IntakeLog::where('intake_course_id', $course->id)->get();
        $this->assertCount(28, $logs);

        // Все 28 логов должны иметь статус scheduled
        $scheduledCount = IntakeLog::where('intake_course_id', $course->id)
            ->where('status', IntakeLogStatus::SCHEDULED)
            ->count();
        $this->assertEquals(28, $scheduledCount);

        // Проверяем временной диапазон: от сегодня 09:00 до (сегодня + 13 дней) 21:00
        $firstLog = IntakeLog::where('intake_course_id', $course->id)
            ->orderBy('scheduled_time', 'asc')
            ->first();
        $lastLog = IntakeLog::where('intake_course_id', $course->id)
            ->orderBy('scheduled_time', 'desc')
            ->first();

        $this->assertEquals(
            Carbon::today()->setTime(9, 0)->format('Y-m-d H:i'),
            $firstLog->scheduled_time->format('Y-m-d H:i')
        );

        $this->assertEquals(
            Carbon::today()->addDays(13)->setTime(21, 0)->format('Y-m-d H:i'),
            $lastLog->scheduled_time->format('Y-m-d H:i')
        );
    }

    /**
     * Тест 2: Защита от IDOR — попытка назначить курс чужому профилю
     * должна пресекаться шлюзом авторизации со статусом HTTP 403 Forbidden.
     */
    public function test_cannot_create_course_for_stranger_profile(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->strangerProfile->id, // Чужой профиль
                'medicine_id' => $this->medicine->id,
                'title' => 'Попытка несанкционированного назначения',
                'start_date' => Carbon::today()->toDateString(),
                'times' => ['08:00', '20:00'],
                'dosage_quantity' => 1.0,
            ]);

        // Ожидаем отказ в доступе 403 Forbidden
        $response->assertStatus(403);

        // В базе данных курс создаваться не должен
        $this->assertEquals(0, IntakeCourse::count());
        $this->assertEquals(0, IntakeLog::count());
    }

    /**
     * Тест 3: Валидация дат — если дата окончания раньше даты начала курса,
     * Form Request обязан вернуть статус HTTP 422 Unprocessable Entity.
     */
    public function test_cannot_create_course_with_invalid_dates(): void
    {
        $startDate = Carbon::today()->toDateString();
        $invalidEndDate = Carbon::today()->subDays(5)->toDateString(); // На 5 дней раньше начала

        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-courses', [
                'profile_id' => $this->profile->id,
                'medicine_id' => $this->medicine->id,
                'title' => 'Курс с невалидными датами',
                'start_date' => $startDate,
                'end_date' => $invalidEndDate,
                'times' => ['10:00'],
                'dosage_quantity' => 1.0,
            ]);

        // Статус 422 Unprocessable Entity
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        // Курс не создан в БД
        $this->assertEquals(0, IntakeCourse::count());
    }
}
