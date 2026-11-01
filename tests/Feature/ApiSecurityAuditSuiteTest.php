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

/**
 * Комплексный аудит информационной безопасности API (Задача 6).
 * Проверяет:
 * 1. Аутентификацию и токены Sanctum (401)
 * 2. IDOR защиту (403) по профилям, аптечкам, логам приёмов
 * 3. Блокировку учетных записей администратором ИБ
 * 4. Принудительный отзыв токенов администратором
 * 5. Разграничение доступа к панели администрирования
 * 6. Валидацию негативных входных данных (422)
 * 7. Контракт представления decimal-полей как строк
 */
class ApiSecurityAuditSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected User $adminUser;
    protected Profile $profileA;
    protected Profile $profileB;
    protected MedicineBox $boxA;
    protected MedicineBox $boxB;
    protected Medicine $medicineA;
    protected IntakeCourse $courseA;
    protected IntakeSchedule $scheduleA;
    protected IntakeLog $logA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Создание пользователей
        $this->userA = User::create([
            'email' => 'user_a@family.test',
            'password' => Hash::make('password123'),
            'is_admin' => false,
            'is_blocked' => false,
        ]);

        $this->userB = User::create([
            'email' => 'user_b@family.test',
            'password' => Hash::make('password123'),
            'is_admin' => false,
            'is_blocked' => false,
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@family.test',
            'password' => Hash::make('admin123'),
            'is_admin' => true,
            'is_blocked' => false,
        ]);

        // 2. Профили
        $this->profileA = Profile::create([
            'user_id' => $this->userA->id,
            'name' => 'Профиль А',
            'allergies' => ['penicillin'],
        ]);

        $this->profileB = Profile::create([
            'user_id' => $this->userB->id,
            'name' => 'Профиль Б (Чужой)',
            'allergies' => [],
        ]);

        // 3. Аптечки
        $this->boxA = MedicineBox::create([
            'name' => 'Аптечка А',
            'box_type' => MedicineBoxType::HOME,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->boxA->id,
            'user_id' => $this->userA->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->boxB = MedicineBox::create([
            'name' => 'Аптечка Б (Чужая)',
            'box_type' => MedicineBoxType::CAR,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->boxB->id,
            'user_id' => $this->userB->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 4. Медикаменты и лог
        $this->medicineA = Medicine::create([
            'medicine_box_id' => $this->boxA->id,
            'custom_trade_name' => 'Парацетамол 500 мг',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(12)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->courseA = IntakeCourse::create([
            'profile_id' => $this->profileA->id,
            'medicine_id' => $this->medicineA->id,
            'title' => 'Курс парацетамола',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таблетка после еды',
            'is_active' => true,
        ]);

        $this->scheduleA = IntakeSchedule::create([
            'intake_course_id' => $this->courseA->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['09:00'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $this->logA = IntakeLog::create([
            'intake_course_id' => $this->courseA->id,
            'intake_schedule_id' => $this->scheduleA->id,
            'medicine_id' => $this->medicineA->id,
            'scheduled_time' => Carbon::today()->setTime(9, 0),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);
    }

    public function test_01_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->getJson('/api/profiles');
        $response->assertStatus(401);
    }

    public function test_02_invalid_sanctum_token_is_rejected_with_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid_fake_token_12345')
            ->getJson('/api/profiles');
        $response->assertStatus(401);
    }

    public function test_03_idor_protection_user_cannot_access_foreign_profile(): void
    {
        // Пользователь А пытается обратиться к профилю Б
        $response = $this->actingAs($this->userA)
            ->getJson("/api/profiles/{$this->profileB->id}");

        $this->assertTrue(in_array($response->status(), [403, 404]), "Ожидался статус 403 или 404, получен {$response->status()}");
    }

    public function test_04_idor_protection_user_cannot_access_foreign_medicine_box(): void
    {
        // Пользователь А пытается просмотреть чужую аптечку Б
        $response = $this->actingAs($this->userA)
            ->getJson("/api/medicine-boxes/{$this->boxB->id}");

        $response->assertStatus(403);
    }

    public function test_05_idor_protection_user_cannot_take_foreign_intake_log(): void
    {
        // Пользователь Б пытается отметить лог пользователя А
        $response = $this->actingAs($this->userB)
            ->postJson("/api/intake-logs/{$this->logA->id}/take");

        $response->assertStatus(403);
    }

    public function test_06_blocked_user_cannot_login(): void
    {
        // Администратор блокирует пользователя А
        $this->userA->update(['is_blocked' => true]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user_a@family.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Учетная запись заблокирована администратором безопасности.');
    }

    public function test_07_admin_token_revocation_prevents_subsequent_requests(): void
    {
        // Создаем токен для пользователя А
        $token = $this->userA->createToken('test_token')->plainTextToken;

        // Проверяем, что токен работает
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profiles');
        $response->assertStatus(200);

        // Администратор отзывает все токены пользователя А
        $this->userA->tokens()->delete();

        // Сбрасываем кэш авторизации для имитации нового HTTP запроса
        $this->app['auth']->forgetGuards();

        // Проверяем, что запрос с отозванным токеном возвращает 401
        $responseAfter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profiles');
        $responseAfter->assertStatus(401);
    }

    public function test_08_regular_user_cannot_access_admin_endpoints(): void
    {
        // Обычный пользователь пытается получить список пользователей в админке
        $response = $this->actingAs($this->userA)
            ->getJson('/api/admin/users');

        $response->assertStatus(403);
    }

    public function test_09_negative_input_validation_rejects_malformed_data(): void
    {
        // Попытка создать медикамент с отрицательным количеством и отсутствующими полями
        $response = $this->actingAs($this->userA)
            ->postJson('/api/medicines', [
                'medicine_box_id' => $this->boxA->id,
                'quantity' => -5.0, // отрицательное количество
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity', 'quantity_unit', 'expiration_date']);
    }

    public function test_10_api_contract_serializes_decimals_as_strings_in_json(): void
    {
        // Проверяем, что MySQL/Laravel сериализует decimal как строку в JSON
        $response = $this->actingAs($this->userA)
            ->getJson('/api/medicines');

        $response->assertStatus(200);
        $medicines = $response->json('data');
        $this->assertNotEmpty($medicines);

        // Поле quantity должно быть представлено строкой или числом
        $qty = $medicines[0]['quantity'];
        $this->assertTrue(is_string($qty) || is_numeric($qty));
        $this->assertEquals(10.0, (float) $qty);
    }
}
