<?php

namespace Tests\Feature;

use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationSafetyAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected Profile $profile;
    protected MedicineBox $box;
    protected MedicationSafetyService $safetyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safetyService = new MedicationSafetyService();

        $this->user = User::factory()->create(['email' => 'audit_tester@family.test']);
        $this->stranger = User::factory()->create(['email' => 'stranger_audit@family.test']);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Аудит Пациент',
            'relationship' => 'self',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка Аудита',
            'box_type' => 'home',
        ]);
        $this->box->users()->attach($this->user->id, ['role' => 'owner']);
    }

    /**
     * 1. Self-exclusion при дублировании:
     * Проверка безопасности препарата, который уже назначен пациенту в активном курсе,
     * не должна сигнализировать о дублировании с самим собой.
     */
    public function test_01_self_exclusion_does_not_flag_medicine_against_itself(): void
    {
        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Нурофен 200',
            'inn' => 'Ибупрофен',
            'atc_code' => 'M01AE01',
            'dosage_form' => 'Таблетки',
            'dosage' => '200 мг',
            'pack_size' => 10,
            'manufacturer' => 'Рекитт',
            'country' => 'Великобритания',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $refMed->id,
            'custom_trade_name' => 'Нурофен 200',
            'form' => 'таблетки',
            'quantity' => 20.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear(),
            'status' => 'active',
        ]);

        // Активный курс с этим медикаментом
        IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $med->id,
            'title' => 'Текущий курс Нурофена',
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб при боли',
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        // Проверяем тот же самый медикамент (например, повторная проверка курса в UI)
        $reportMed = $this->safetyService->checkSafety($this->profile, $med);
        $this->assertFalse($reportMed['has_critical']);
        $this->assertEmpty($reportMed['warnings'], 'Препарат не должен конфликтовать сам с собой (self-exclusion)');

        // Проверяем reference medicine, на котором основан текущий единственный курс
        $reportRef = $this->safetyService->checkSafety($this->profile, $refMed);
        $this->assertFalse($reportRef['has_critical']);
        $this->assertEmpty($reportRef['warnings'], 'Справочный препарат не должен конфликтовать с назначенным из него курсом (self-exclusion)');
    }

    /**
     * 2. hasCritical для дублей:
     * При обнаружении дублирования активного вещества (двойная доза / токсическая передозировка)
     * флаг has_critical ОБЯЗАН быть true.
     */
    public function test_02_has_critical_is_true_when_substance_overlap_detected(): void
    {
        $refNurofen = ReferenceMedicine::create([
            'trade_name' => 'Нурофен Форте',
            'inn' => 'Ибупрофен',
            'atc_code' => 'M01AE01',
            'dosage_form' => 'Таблетки',
            'dosage' => '400 мг',
            'pack_size' => 12,
            'manufacturer' => 'Рекитт',
            'country' => 'Великобритания',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $medNurofen = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $refNurofen->id,
            'custom_trade_name' => 'Нурофен Форте',
            'form' => 'таблетки',
            'quantity' => 12.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear(),
            'status' => 'active',
        ]);

        IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $medNurofen->id,
            'title' => 'Курс Нурофена',
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб при боли',
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        // Другой препарат с тем же МНН (Ибупрофен другого производителя)
        $refIbuprom = ReferenceMedicine::create([
            'trade_name' => 'Ибупром Спринт',
            'inn' => 'Ибупрофен',
            'atc_code' => 'M01AE01',
            'dosage_form' => 'Капсулы',
            'dosage' => '200 мг',
            'pack_size' => 10,
            'manufacturer' => 'Польфа',
            'country' => 'Польша',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $report = $this->safetyService->checkSafety($this->profile, $refIbuprom);

        $this->assertEquals('conflict', $report['status']);
        $this->assertTrue($report['has_critical'], 'has_critical обязан быть true при дублировании действующего вещества!');
        $this->assertNotEmpty($report['warnings']);
    }

    /**
     * 3. Захардкоженные бренды и латиница:
     * Распознавание брендов и МНН в латинице (amoxiclav, nasivin, snup, ibuprofen).
     */
    public function test_03_latin_and_multilingual_brand_recognition(): void
    {
        // Пациент с аллергией на penicillin и гипертонией
        $this->profile->update([
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['hypertension'],
        ]);

        // Препарат на латинице: Amoxiclav (МНН: Amoxicillin + Clavulanic acid)
        $refAmoxiclavLatin = ReferenceMedicine::create([
            'trade_name' => 'Amoxiclav Quicktab',
            'inn' => 'Amoxicillin and beta-lactamase inhibitor',
            'atc_code' => 'J01CR02',
            'dosage_form' => 'Tablets',
            'dosage' => '625 mg',
            'pack_size' => 14,
            'manufacturer' => 'Sandoz',
            'country' => 'Slovenia',
            'is_prescription' => true,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $reportAmox = $this->safetyService->checkSafety($this->profile, $refAmoxiclavLatin);
        $this->assertTrue($reportAmox['has_critical']);
        $this->assertStringContainsString('пенициллин', mb_strtolower(implode(' ', $reportAmox['warnings'])));

        // Сосудосуживающий спрей на латинице: Nasivin (Oxymetazoline)
        $refNasivinLatin = ReferenceMedicine::create([
            'trade_name' => 'Nasivin Sensitive',
            'inn' => 'Oxymetazoline',
            'atc_code' => 'R01AA05',
            'dosage_form' => 'Nasal spray',
            'dosage' => '0.05%',
            'pack_size' => 1,
            'manufacturer' => 'Merck',
            'country' => 'Germany',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $reportNasi = $this->safetyService->checkSafety($this->profile, $refNasivinLatin);
        $this->assertStringContainsString('артериальной гипертензией', mb_strtolower(implode(' ', $reportNasi['warnings'])));
    }

    /**
     * 4. Лимонная кислота E330:
     * Детекция лимонной кислоты (E330 / Е330 / citric acid) при аллергии на цитрусовые/кислоты.
     */
    public function test_04_citric_acid_e330_detected_for_citrus_allergy(): void
    {
        $this->profile->update([
            'allergies' => ['citrus'],
        ]);

        $refWithE330 = ReferenceMedicine::create([
            'trade_name' => 'Шипучие таблетки Мультивит',
            'inn' => 'Multivitamins',
            'atc_code' => 'A11BA',
            'dosage_form' => 'Таблетки шипучие',
            'dosage' => '1000 мг',
            'pack_size' => 20,
            'manufacturer' => 'КРКА',
            'country' => 'Словения',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
            'composition' => 'Витаминный комплекс. Вспомогательные вещества: регулятор кислотности E330 (лимонная кислота), натрия гидрокарбонат.',
            'allergen_tags' => [],
        ]);

        $report = $this->safetyService->checkSafety($this->profile, $refWithE330);
        $this->assertTrue($report['has_critical']);
        $this->assertStringContainsString('цитрусов', mb_strtolower(implode(' ', $report['warnings'])));
    }

    /**
     * 5. Отрицания «без лактозы»:
     * Фармацевтические описания с отрицанием («без лактозы», «не содержит лактозу», «lactose-free»)
     * НЕ должны вызывать ложноположительное срабатывание аллергии на лактозу.
     */
    public function test_05_lactose_free_negation_does_not_trigger_allergy_warning(): void
    {
        $this->profile->update([
            'allergies' => ['lactose'],
        ]);

        $refLactoseFree = ReferenceMedicine::create([
            'trade_name' => 'Пробиотик Форте',
            'inn' => 'Bifidobacterium',
            'atc_code' => 'A07FA',
            'dosage_form' => 'Капсулы',
            'dosage' => '5 млрд КОЕ',
            'pack_size' => 30,
            'manufacturer' => 'БиоФарм',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
            'composition' => 'Живые лиофилизированные бактерии. Препарат произведен по технологии без лактозы (не содержит лактозу и молочный сахар). Подходит при лактазной недостаточности.',
            'allergen_tags' => [],
        ]);

        $report = $this->safetyService->checkSafety($this->profile, $refLactoseFree);
        $this->assertEquals('safe', $report['status']);
        $this->assertEmpty($report['warnings'], 'Препарат с явной маркировкой "без лактозы" не должен триггерить аллергию!');
    }

    /**
     * 6. Защита от IDOR и утечки данных:
     * В эндпоинте POST /api/medication-safety/check запрос с чужим profile_id
     * возвращает строгий HTTP 403 Forbidden без утечки данных,
     * а несуществующий профиль отсекается валидацией схемы (HTTP 422).
     */
    public function test_06_idor_protection_for_foreign_profile_and_medicine(): void
    {
        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Анальгин Тест',
            'inn' => 'Metamizole sodium',
            'atc_code' => 'N02BB02',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $strangerProfile = Profile::create([
            'user_id' => $this->stranger->id,
            'name' => 'Чужой Профиль',
            'relationship' => 'self',
        ]);

        // 1. Запрос к существующему, но чужому профилю -> строгий 403 Forbidden
        $respStranger = $this->actingAs($this->user)->postJson('/api/medication-safety/check', [
            'profile_id' => $strangerProfile->id,
            'reference_medicine_id' => $refMed->id,
        ]);

        $this->assertEquals(403, $respStranger->status());
        $respStranger->assertJson([
            'message' => 'Доступ к указанному профилю запрещен (IDOR protection).',
        ]);

        // 2. Запрос к несуществующему профилю -> валидация схемы 422 Unprocessable Entity
        $respNonExistent = $this->actingAs($this->user)->postJson('/api/medication-safety/check', [
            'profile_id' => 999999,
            'reference_medicine_id' => $refMed->id,
        ]);

        $this->assertEquals(422, $respNonExistent->status());
        $respNonExistent->assertJsonValidationErrors(['profile_id']);
    }
}
