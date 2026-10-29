<?php

namespace Tests\Feature;

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
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditAndOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Profile $profile;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'audit_user@family.test',
            'password' => Hash::make('password123'),
            'sync_enabled' => true,
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Аудит Тест',
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка аудита',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    /**
     * 1. Устранение бага поиска по зашифрованному custom_trade_name (Data at Rest AES-256).
     */
    public function test_medicine_search_works_with_encrypted_custom_trade_name(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Создаем препарат с зашифрованным пользовательским наименованием (без привязки к ГРЛС)
        $customMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Особый Корвалол в каплях',
            'quantity' => 25,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::now()->addMonths(6)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Создаем препарат из справочника ГРЛС
        $ref = ReferenceMedicine::create([
            'trade_name' => 'Парацетамол Экстра',
            'inn' => 'Парацетамол',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'atc_code' => 'N02BE01',
            'is_prescription' => false,
            'status' => 'active',
        ]);

        $refMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $ref->id,
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Поиск по зашифрованному имени custom_trade_name
        $response1 = $this->getJson('/api/medicines?q=корвалол');
        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $this->assertCount(1, $data1);
        $this->assertEquals($customMed->id, $data1[0]['id']);
        $this->assertEquals('Особый Корвалол в каплях', $data1[0]['custom_trade_name']);

        // Поиск по торговому названию из связанного справочника
        $response2 = $this->getJson('/api/medicines?q=парацетамол');
        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals($refMed->id, $data2[0]['id']);

        // Поиск по несуществующему слову
        $response3 = $this->getJson('/api/medicines?q=несуществующий_препарат');
        $response3->assertStatus(200);
        $this->assertCount(0, $response3->json('data'));
    }

    /**
     * 2. Проверка явного $fillable в моделях Medicine, Profile, IntakeCourse (защита от Mass Assignment).
     */
    public function test_models_have_explicit_fillable_attributes(): void
    {
        $med = new Medicine();
        $this->assertNotEmpty($med->getFillable());
        $this->assertContains('custom_trade_name', $med->getFillable());
        $this->assertContains('low_stock_threshold', $med->getFillable());
        $this->assertContains('storage_condition', $med->getFillable());

        $profile = new Profile();
        $this->assertNotEmpty($profile->getFillable());
        $this->assertContains('name', $profile->getFillable());
        $this->assertContains('allergies', $profile->getFillable());
        $this->assertContains('chronic_conditions', $profile->getFillable());

        $course = new IntakeCourse();
        $this->assertNotEmpty($course->getFillable());
        $this->assertContains('title', $course->getFillable());
        $this->assertContains('dosage_quantity', $course->getFillable());
    }

    /**
     * 3. Внедрение пагинации в MedicineController::index.
     */
    public function test_medicine_listing_is_paginated(): void
    {
        Sanctum::actingAs($this->user);

        // Создаем 3 препарата
        for ($i = 1; $i <= 3; $i++) {
            Medicine::create([
                'medicine_box_id' => $this->box->id,
                'custom_trade_name' => "Препарат {$i}",
                'quantity' => 10,
                'quantity_unit' => MedicineQuantityUnit::PILLS,
                'expiration_date' => Carbon::now()->addMonths($i)->toDateString(),
                'status' => MedicineStatus::ACTIVE,
            ]);
        }

        $response = $this->getJson('/api/medicines?per_page=2');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'per_page',
                'total',
            ]);

        $this->assertEquals(2, $response->json('per_page'));
        $this->assertEquals(3, $response->json('total'));
        $this->assertCount(2, $response->json('data'));
    }

    /**
     * 3. Внедрение пагинации в IntakeCourseController::index.
     */
    public function test_intake_courses_listing_is_paginated(): void
    {
        Sanctum::actingAs($this->user);

        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Анальгин',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $med->id,
            'title' => 'Курс Анальгина',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1,
            'dosage_instruction' => 'После еды',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/intake-courses');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'total',
            ]);

        $this->assertEquals(1, $response->json('total'));
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * 3. Пагинация в IntakeLogController: выборка на конкретную дату без пагинации, а истории — с пагинацией (50).
     */
    public function test_intake_logs_pagination_and_history_mode(): void
    {
        Sanctum::actingAs($this->user);

        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Витамин C',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $med->id,
            'title' => 'Курс Витамина',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1,
            'dosage_instruction' => 'Утром',
            'is_active' => true,
        ]);

        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $med->id,
            'scheduled_time' => Carbon::today()->setTime(10, 0),
            'status' => 'scheduled',
            'quantity_taken' => 1,
        ]);

        // Запрос на конкретную дату (сегодня) -> структура { date: "...", data: [...] }
        $dateRes = $this->getJson('/api/intake-logs?date=today');
        $dateRes->assertStatus(200)
            ->assertJsonStructure(['date', 'data']);

        // Запрос истории -> пагинированная структура { current_page, data, total... }
        $historyRes = $this->getJson('/api/intake-logs?history=1');
        $historyRes->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data',
                'total',
                'per_page',
            ]);

        $this->assertEquals(50, $historyRes->json('per_page'));
    }

    /**
     * 4. Защита справочника ГРЛС лимитом запросов throttle:60,1 от скрапинга.
     */
    public function test_reference_medicines_has_rate_limit_protection(): void
    {
        $response = $this->getJson('/api/reference-medicines');
        $response->assertStatus(200);

        // Проверяем наличие заголовков лимитера запросов
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals(60, (int) $response->headers->get('X-RateLimit-Limit'));
    }

    /**
     * 4. Чистая аутентификация через Sanctum token в AuthController::login (без мертвого кода).
     */
    public function test_auth_login_returns_clean_sanctum_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'audit_user@family.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'email'],
            ]);

        $this->assertEquals('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('token'));
    }
}
