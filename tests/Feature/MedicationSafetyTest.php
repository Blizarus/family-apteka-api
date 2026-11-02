<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $mishaProfile;
    protected Profile $elenaProfile;
    protected Profile $strangerProfile;
    protected MedicineBox $homeBox;
    protected MedicineBox $strangerBox;
    protected ReferenceMedicine $nurofenKids;
    protected ReferenceMedicine $amoxicillin;
    protected ReferenceMedicine $ibuprofen;
    protected ReferenceMedicine $nurofenCaps;
    protected ReferenceMedicine $ketorol;
    protected MedicationSafetyService $safetyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safetyService = new MedicationSafetyService();

        // 1. Тестовые пользователи
        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@evil.test',
            'password' => Hash::make('password'),
        ]);

        // 2. Профили членов семьи
        $this->mishaProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Сын Миша',
            'allergies' => ['penicillin', 'citrus'],
            'chronic_conditions' => [],
        ]);

        $this->elenaProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Бабушка Елена',
            'allergies' => [],
            'chronic_conditions' => ['hypertension', 'gastritis'],
        ]);

        $this->strangerProfile = Profile::create([
            'user_id' => $this->stranger->id,
            'name' => 'Чужой профиль',
        ]);

        // 3. Аптечки
        $this->homeBox = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->homeBox->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->strangerBox = MedicineBox::create([
            'name' => 'Чужая аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->strangerBox->id,
            'user_id' => $this->stranger->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 4. Эталонные лекарства справочника
        $this->nurofenKids = ReferenceMedicine::create([
            'trade_name' => 'Нурофен для детей (апельсин)',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'Суспензия для приема внутрь',
            'dosage' => '100 мг/5 мл',
            'pack_size' => 1,
            'manufacturer' => 'Рекитт Бенкизер',
            'country' => 'Великобритания',
            'atc_code' => 'M01AE01',
            'barcode' => '5000158066220',
            'is_prescription' => false,
            'composition' => 'Ибупрофен 100 мг на 5 мл. Вспомогательные вещества: ароматизатор апельсиновый, лимонная кислота, мальтитол.',
            'allergen_tags' => ['citrus', 'citric_acid', 'nsaid'],
            'status' => 'active',
        ]);

        $this->amoxicillin = ReferenceMedicine::create([
            'trade_name' => 'Амоксициллин Сандоз',
            'inn' => 'Амоксициллин',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 20,
            'manufacturer' => 'Сандоз',
            'country' => 'Австрия',
            'atc_code' => 'J01CA04',
            'barcode' => '4601808001028',
            'is_prescription' => true,
            'composition' => 'Амоксициллин 500 мг.',
            'allergen_tags' => ['penicillin'],
            'status' => 'active',
        ]);

        $this->ibuprofen = ReferenceMedicine::create([
            'trade_name' => 'Ибупрофен',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'Таблетки',
            'dosage' => '400 мг',
            'pack_size' => 20,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'atc_code' => 'M01AE01',
            'barcode' => '4601808001011',
            'is_prescription' => false,
            'composition' => 'Ибупрофен 400 мг.',
            'allergen_tags' => ['nsaid'],
            'status' => 'active',
        ]);

        $this->nurofenCaps = ReferenceMedicine::create([
            'trade_name' => 'Нурофен Экспресс Форте',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'Капсулы',
            'dosage' => '400 мг',
            'pack_size' => 10,
            'manufacturer' => 'Рекитт Бенкизер',
            'country' => 'Великобритания',
            'atc_code' => 'M01AE01',
            'barcode' => '5000158066121',
            'is_prescription' => false,
            'composition' => 'Ибупрофен 400 мг.',
            'allergen_tags' => ['nsaid'],
            'status' => 'active',
        ]);

        $this->ketorol = ReferenceMedicine::create([
            'trade_name' => 'Кеторол Экспресс',
            'inn' => 'Кеторолак',
            'dosage_form' => 'Таблетки',
            'dosage' => '10 мг',
            'pack_size' => 20,
            'manufacturer' => 'Д-р Реддиc',
            'country' => 'Индия',
            'atc_code' => 'M01AB15',
            'barcode' => '8901148234012',
            'is_prescription' => true,
            'composition' => 'Кеторолак 10 мг.',
            'allergen_tags' => ['nsaid'],
            'status' => 'active',
        ]);
    }

    /**
     * Тест: Детекция аллергии на цитрусовые ароматизаторы в детской суспензии.
     */
    public function test_detects_citrus_allergy_in_nurofen_orange(): void
    {
        $report = $this->safetyService->checkSafety($this->mishaProfile, $this->nurofenKids);

        $this->assertFalse($report['is_safe']);
        $this->assertTrue($report['has_critical']);
        $this->assertStringContainsString('цитрусового ряда', implode(' ', $report['warnings']));
    }

    /**
     * Тест: Критическая детекция аллергии на пенициллиновый ряд.
     */
    public function test_detects_critical_penicillin_allergy(): void
    {
        $report = $this->safetyService->checkSafety($this->mishaProfile, $this->amoxicillin);

        $this->assertFalse($report['is_safe']);
        $this->assertTrue($report['has_critical']);
        $this->assertStringContainsString('пенициллиновый ряд', implode(' ', $report['warnings']));
    }

    /**
     * Тест: Детекция дублирования действующего вещества (МНН Ибупрофен).
     */
    public function test_detects_duplicate_active_substance_overlap(): void
    {
        // 1. Создаем физическое лекарство Ибупрофен
        $ibuMedicine = Medicine::create([
            'medicine_box_id' => $this->homeBox->id,
            'reference_medicine_id' => $this->ibuprofen->id,
            'quantity' => 20,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(6),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Создаем физическое лекарство Нурофен
        $nurofenMedicine = Medicine::create([
            'medicine_box_id' => $this->homeBox->id,
            'reference_medicine_id' => $this->nurofenCaps->id,
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(6),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 3. Назначаем активный курс Ибупрофена бабушке
        IntakeCourse::create([
            'profile_id' => $this->elenaProfile->id,
            'medicine_id' => $ibuMedicine->id,
            'title' => 'Курс Ибупрофена',
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->addDays(7)->toDateString(),
            'dosage_quantity' => 1,
            'dosage_instruction' => 'После еды',
            'is_active' => true,
        ]);

        // 4. Проверяем безопасность назначения Нурофена тому же профилю
        $report = $this->safetyService->checkSafety($this->elenaProfile, $nurofenMedicine);

        $this->assertFalse($report['is_safe']);
        $this->assertStringContainsString('дублирование действующего вещества', implode(' ', $report['warnings']));
    }

    /**
     * Тест: Клиническое противопоказание: Гастрит у пациента при назначении НПВП.
     */
    public function test_detects_contraindication_for_gastritis_and_nsaid(): void
    {
        $report = $this->safetyService->checkSafety($this->elenaProfile, $this->ketorol);

        $this->assertFalse($report['is_safe']);
        $this->assertTrue($report['has_critical']);
        $this->assertStringContainsString('гастрит/язвенная болезнь', implode(' ', $report['warnings']));
    }

    /**
     * Тест: Эндпоинт POST /api/medication-safety/check возвращает отчет безопасности.
     */
    public function test_medication_safety_check_endpoint_returns_analysis(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->mishaProfile->id,
            'reference_medicine_id' => $this->amoxicillin->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_safe' => false,
                    'has_critical' => true,
                ],
            ]);

        $this->assertNotEmpty($response->json('data.warnings'));
    }

    /**
     * Тест: IDOR-защита на эндпоинте проверки клинической безопасности.
     */
    public function test_medication_safety_check_prevents_idor(): void
    {
        Sanctum::actingAs($this->user);

        // Попытка проверить безопасность чужого профиля
        $response = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->strangerProfile->id,
            'reference_medicine_id' => $this->amoxicillin->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Тест: Создание курса приёма возвращает список предупреждений warnings в теле ответа.
     */
    public function test_store_intake_course_includes_clinical_safety_warnings(): void
    {
        Sanctum::actingAs($this->user);

        $med = Medicine::create([
            'medicine_box_id' => $this->homeBox->id,
            'reference_medicine_id' => $this->nurofenKids->id,
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $response = $this->postJson('/api/intake-courses', [
            'profile_id' => $this->mishaProfile->id,
            'medicine_id' => $med->id,
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 5,
            'times' => ['09:00', '21:00'],
            'rule' => 'После еды',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data',
                'logs_created',
                'warnings',
                'safety',
            ]);

        // Предупреждение о цитрусовом аллергене для Миши присутствует в warnings
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertStringContainsString('цитрусового ряда', implode(' ', $response->json('warnings')));
    }

    /**
     * Тест: Создание и обновление профиля с сохранением allergies и chronic_conditions.
     */
    public function test_profile_crud_supports_allergies_and_chronic_conditions(): void
    {
        Sanctum::actingAs($this->user);

        $createResponse = $this->postJson('/api/profiles', [
            'name' => 'Дедушка Виктор',
            'birth_date' => '1950-01-15',
            'allergies' => ['lactose', 'penicillin'],
            'chronic_conditions' => ['hypertension', 'asthma'],
        ]);

        $createResponse->assertStatus(201);
        $profileId = $createResponse->json('data.id');

        $profile = Profile::find($profileId);
        $this->assertEquals(['lactose', 'penicillin'], $profile->allergies);
        $this->assertEquals(['hypertension', 'asthma'], $profile->chronic_conditions);

        // Обновление профиля
        $updateResponse = $this->putJson("/api/profiles/{$profileId}", [
            'allergies' => ['lactose'],
        ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals(['lactose'], $profile->fresh()->allergies);
    }

    /**
     * Тест: Проверка эндпоинта на аллергию к пенициллинам при назначении Амоксиклава (Критическое предупреждение).
     */
    public function test_endpoint_detects_amoxiclav_penicillin_allergy(): void
    {
        Sanctum::actingAs($this->user);

        $amoxiclav = ReferenceMedicine::create([
            'trade_name' => 'Амоксиклав',
            'inn' => 'Амоксициллин + Клавулановая кислота',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг + 125 мг',
            'pack_size' => 14,
            'manufacturer' => 'Лек д.д.',
            'country' => 'Словения',
            'atc_code' => 'J01CR02',
            'is_prescription' => true,
            'allergen_tags' => ['penicillin'],
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->mishaProfile->id,
            'reference_medicine_id' => $amoxiclav->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_safe' => false,
                    'has_critical' => true,
                ],
            ]);

        $this->assertStringContainsString('пенициллиновый ряд', implode(' ', $response->json('data.warnings')));
    }

    /**
     * Тест: Проверка аллергий на вспомогательные компоненты (лактоза и красители).
     */
    public function test_endpoint_detects_lactose_and_dye_excipient_allergies(): void
    {
        Sanctum::actingAs($this->user);

        $sensitiveProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Аллергик',
            'allergies' => ['lactose', 'dye'],
            'chronic_conditions' => [],
        ]);

        $drugWithExcipients = ReferenceMedicine::create([
            'trade_name' => 'Препарат с лактозой и красителем',
            'inn' => 'Тестовое вещество',
            'dosage_form' => 'Таблетки',
            'dosage' => '10 мг',
            'pack_size' => 10,
            'manufacturer' => 'ТестФарм',
            'country' => 'Россия',
            'atc_code' => 'A01AA01',
            'is_prescription' => false,
            'composition' => 'Действующее вещество 10 мг. Вспомогательные вещества: лактозы моногидрат, краситель тартразин E102.',
            'allergen_tags' => ['lactose', 'dye'],
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $sensitiveProfile->id,
            'reference_medicine_id' => $drugWithExcipients->id,
        ]);

        $response->assertStatus(200);
        $warnings = implode(' ', $response->json('data.warnings'));
        $this->assertStringContainsString('лактоз', $warnings);
        $this->assertStringContainsString('красител', $warnings);
    }

    /**
     * Тест: Проверка хронических противопоказаний: сосудосуживающие (Називин / ксилометазолин) при гипертензии.
     */
    public function test_endpoint_detects_vasoconstrictor_for_hypertension(): void
    {
        Sanctum::actingAs($this->user);

        $nazivin = ReferenceMedicine::create([
            'trade_name' => 'Називин спрей',
            'inn' => 'Оксиметазолин',
            'dosage_form' => 'Спрей назальный',
            'dosage' => '0.05%',
            'pack_size' => 1,
            'manufacturer' => 'Никомед',
            'country' => 'Германия',
            'atc_code' => 'R01AA05',
            'is_prescription' => false,
            'status' => 'active',
        ]);

        $snoop = ReferenceMedicine::create([
            'trade_name' => 'Снуп',
            'inn' => 'Ксилометазолин',
            'dosage_form' => 'Спрей назальный',
            'dosage' => '0.1%',
            'pack_size' => 1,
            'manufacturer' => 'Штада',
            'country' => 'Германия',
            'atc_code' => 'R01AA07',
            'is_prescription' => false,
            'status' => 'active',
        ]);

        // 1. Проверяем Називин для бабушки Елены с гипертензией
        $resNazivin = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->elenaProfile->id,
            'reference_medicine_id' => $nazivin->id,
        ]);
        $resNazivin->assertStatus(200);
        $this->assertStringContainsString('артериальной гипертензией', implode(' ', $resNazivin->json('data.warnings')));

        // 2. Проверяем Снуп (ксилометазолин) для бабушки Елены
        $resSnoop = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->elenaProfile->id,
            'reference_medicine_id' => $snoop->id,
        ]);
        $resSnoop->assertStatus(200);
        $this->assertStringContainsString('артериальной гипертензией', implode(' ', $resSnoop->json('data.warnings')));
    }

    /**
     * Тест: Проверка противопоказаний: НПВП при бронхиальной астме (риск бронхоспазма).
     */
    public function test_endpoint_detects_nsaid_contraindication_for_asthma(): void
    {
        Sanctum::actingAs($this->user);

        $asthmaProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с астмой',
            'allergies' => [],
            'chronic_conditions' => ['asthma'],
        ]);

        $response = $this->postJson('/api/medication-safety/check', [
            'profile_id' => $asthmaProfile->id,
            'reference_medicine_id' => $this->ibuprofen->id,
        ]);

        $response->assertStatus(200);
        $warnings = implode(' ', $response->json('data.warnings'));
        $this->assertStringContainsString('бронхиальной астме', $warnings);
        $this->assertStringContainsString('бронхоспазма', $warnings);
    }

    /**
     * Тест: Валидация входных данных эндпоинта /api/medication-safety/check.
     */
    public function test_endpoint_validates_input_data(): void
    {
        Sanctum::actingAs($this->user);

        // 1. Отсутствие profile_id
        $this->postJson('/api/medication-safety/check', [
            'reference_medicine_id' => $this->ibuprofen->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['profile_id']);

        // 2. Несуществующий profile_id возвращает 422 Unprocessable Entity
        $this->postJson('/api/medication-safety/check', [
            'profile_id' => 999999,
            'reference_medicine_id' => $this->ibuprofen->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['profile_id']);

        // 3. Отсутствие обоих идентификаторов лекарства (ни medicine_id, ни reference_medicine_id)
        $this->postJson('/api/medication-safety/check', [
            'profile_id' => $this->mishaProfile->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['medicine_id', 'reference_medicine_id']);

        // 4. Некорректный формат данных (строки вместо целых чисел)
        $this->postJson('/api/medication-safety/check', [
            'profile_id' => 'not-a-number',
            'reference_medicine_id' => 'invalid',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['profile_id', 'reference_medicine_id']);
    }
}
