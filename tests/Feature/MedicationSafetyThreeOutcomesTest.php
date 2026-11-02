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
use Tests\TestCase;

class MedicationSafetyThreeOutcomesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Profile $profile;
    protected MedicineBox $box;
    protected MedicationSafetyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MedicationSafetyService();

        $this->user = User::create([
            'email' => 'cds_tester@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Тест',
            'allergies' => ['lactose'],
            'chronic_conditions' => ['gastritis'],
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка Тест',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    protected function createRefMed(array $attributes = []): ReferenceMedicine
    {
        return ReferenceMedicine::create(array_merge([
            'trade_name' => 'Тестовый препарат',
            'inn' => '',
            'dosage_form' => 'таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'ФармСтандарт',
            'country' => 'Россия',
            'atc_code' => '',
            'is_prescription' => false,
            'status' => 'active',
            'composition' => null,
            'allergen_tags' => [],
        ], $attributes));
    }

    protected function createMedicine(array $attributes = []): Medicine
    {
        return Medicine::create(array_merge([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Кастомный препарат',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addMonths(6)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ], $attributes));
    }

    /**
     * 1. Статус "safe" (все уровни проверены, состав и МНН/АТХ полные, конфликтов нет).
     */
    public function test_three_outcomes_safe_when_all_levels_verified_and_clean(): void
    {
        $cleanProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Здоровый Пациент',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);

        $refMed = $this->createRefMed([
            'trade_name' => 'Парацетамол Таблетки',
            'inn' => 'Парацетамол',
            'atc_code' => 'N02BE01',
            'composition' => 'Парацетамол 500 мг, крахмал картофельный, стеариновая кислота.',
        ]);

        $report = $this->service->checkSafety($cleanProfile, $refMed);

        $this->assertArrayHasKey('status', $report);
        $this->assertEquals('safe', $report['status']);
        $this->assertTrue($report['is_safe']);
        $this->assertEmpty($report['warnings']);
        $this->assertEmpty($report['unverified_checks']);
        $this->assertArrayHasKey('disclaimer', $report);
    }

    /**
     * 2. Статус "conflict" (обнаружен клинический конфликт безопасности).
     */
    public function test_three_outcomes_conflict_when_allergy_detected(): void
    {
        $penicillinProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Аллергик',
            'allergies' => ['penicillin'],
            'chronic_conditions' => [],
        ]);

        $amoxicillin = $this->createRefMed([
            'trade_name' => 'Амоксициллин 500',
            'inn' => 'Амоксициллин',
            'atc_code' => 'J01CA04',
            'composition' => 'Амоксициллин 500 мг',
            'allergen_tags' => ['penicillin'],
        ]);

        $report = $this->service->checkSafety($penicillinProfile, $amoxicillin);

        $this->assertEquals('conflict', $report['status']);
        $this->assertFalse($report['is_safe']);
        $this->assertTrue($report['has_critical']);
        $this->assertNotEmpty($report['warnings']);
    }

    /**
     * 3. Fail-open баг: пустые МНН и АТХ у целевого препарата не должны молча давать safe!
     * Должен возвращаться статус "unverifiable" с указанием причины в unverified_checks.
     */
    public function test_fail_open_target_medicine_without_inn_and_atc_is_unverifiable(): void
    {
        $blankRefMed = $this->createRefMed([
            'trade_name' => 'Неизвестный фитосбор',
            'inn' => '',
            'atc_code' => '',
            'composition' => null,
            'allergen_tags' => [],
        ]);

        $report = $this->service->checkSafety($this->profile, $blankRefMed);

        $this->assertEquals('unverifiable', $report['status'], 'Препарат без МНН и АТХ не может считаться безопасно проверенным (fail-closed)');
        $this->assertFalse($report['is_safe']);
        $this->assertNotEmpty($report['unverified_checks']);

        $unverifiedString = implode(' ', $report['unverified_checks']);
        $this->assertStringContainsString('МНН', $unverifiedString);
    }

    /**
     * 4. Fail-open баг: активный курс приёма без привязанного препарата не должен молча пропускаться через continue.
     */
    public function test_fail_open_active_course_without_medicine_is_unverifiable(): void
    {
        $paracetamol = $this->createRefMed([
            'trade_name' => 'Парацетамол',
            'inn' => 'Парацетамол',
            'atc_code' => 'N02BE01',
            'composition' => 'Парацетамол 500 мг, крахмал картофельный',
        ]);

        $cleanProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с битым курсом',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);

        $tempMed = $this->createMedicine([
            'custom_trade_name' => 'Удаляемый препарат',
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $cleanProfile->id,
            'medicine_id' => $tempMed->id,
            'title' => 'Потерянный курс без препарата',
            'dosage_instruction' => '1 раз в день',
            'dosage_quantity' => 1.0,
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        // Имитируем случай, когда связь medicine не разрешилась (null)
        $cleanProfile->setRelation('intakeCourses', collect([
            $course->setRelation('medicine', null),
        ]));

        $report = $this->service->checkSafety($cleanProfile, $paracetamol);

        $this->assertEquals('unverifiable', $report['status'], 'Наличие непроверенного курса должно давать unverifiable');
        $this->assertFalse($report['is_safe']);
        $unverifiedString = implode(' ', $report['unverified_checks']);
        $this->assertStringContainsString('Потерянный курс', $unverifiedString);
    }

    /**
     * 5. Fail-open баг Уровень 3: у пациента есть аллергии на эксципиенты (лактоза),
     * но у препарата пустой состав (composition = null/empty) и пустые теги.
     * Нельзя возвращать safe: проверку эксципиентов выполнить невозможно!
     */
    public function test_fail_open_level3_empty_composition_with_patient_allergies_is_unverifiable(): void
    {
        $lactoseAllergyProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с непереносимостью лактозы',
            'allergies' => ['lactose'],
            'chronic_conditions' => [],
        ]);

        $drugWithoutComposition = $this->createRefMed([
            'trade_name' => 'Капли без описания состава',
            'inn' => 'Ксилометазолин',
            'atc_code' => 'R01AA07',
            'composition' => null, // Состав отсутствует в базе
            'allergen_tags' => [],
        ]);

        $report = $this->service->checkSafety($lactoseAllergyProfile, $drugWithoutComposition);

        $this->assertEquals('unverifiable', $report['status']);
        $this->assertFalse($report['is_safe']);
        $unverifiedString = implode(' ', $report['unverified_checks']);
        $this->assertStringContainsString('состав', mb_strtolower($unverifiedString));
    }

    /**
     * 6. Fail-open баг Уровень 4: у пациента есть хроническое заболевание (гастрит/язва),
     * но у препарата отсутствуют фармакологические данные (МНН, АТХ, состав).
     */
    public function test_fail_open_level4_empty_data_with_chronic_conditions_is_unverifiable(): void
    {
        $gastritisProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с язвой',
            'allergies' => [],
            'chronic_conditions' => ['gastritis', 'ulcer'],
        ]);

        $unknownDrug = $this->createRefMed([
            'trade_name' => 'Какое-то новое обезболивающее',
            'inn' => '',
            'atc_code' => '',
            'composition' => null,
            'allergen_tags' => [],
        ]);

        $report = $this->service->checkSafety($gastritisProfile, $unknownDrug);

        $this->assertEquals('unverifiable', $report['status']);
        $this->assertFalse($report['is_safe']);
        $unverifiedString = implode(' ', $report['unverified_checks']);
        $this->assertStringContainsString('хроническ', mb_strtolower($unverifiedString));
    }

    /**
     * 7. Препарат из аптечки пользователя не привязан к справочнику (кастомный препарат без МНН/АТХ).
     */
    public function test_custom_medicine_not_in_reference_catalog_is_unverifiable(): void
    {
        $cleanProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Обычный пациент',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);

        $customMed = $this->createMedicine([
            'reference_medicine_id' => null, // Не сопоставлен со справочником
            'custom_trade_name' => 'Таблетки от соседа без маркировки',
        ]);

        $report = $this->service->checkSafety($cleanProfile, $customMed);

        $this->assertEquals('unverifiable', $report['status']);
        $this->assertFalse($report['is_safe']);
        $unverifiedString = implode(' ', $report['unverified_checks']);
        $this->assertStringContainsString('справочник', mb_strtolower($unverifiedString));
    }

    /**
     * 8. API-эндпоинт /api/medication-safety/check возвращает статус, unverified_checks и дисклеймер.
     */
    public function test_api_safety_check_returns_three_outcomes_and_unverified_checks(): void
    {
        $drug = $this->createRefMed([
            'trade_name' => 'Неразмеченный препарат',
            'inn' => '',
            'atc_code' => '',
            'composition' => null,
            'allergen_tags' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/medication-safety/check', [
                'profile_id' => $this->profile->id,
                'reference_medicine_id' => $drug->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'unverifiable')
            ->assertJsonPath('data.is_safe', false)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'is_safe',
                    'has_critical',
                    'warnings',
                    'unverified_checks',
                    'disclaimer',
                ],
            ]);
    }
}
