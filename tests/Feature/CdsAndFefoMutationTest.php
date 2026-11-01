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
use App\Models\NotificationLog;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use App\NotificationLogType;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Мутационное и граничное тестирование систем Clinical Decision Support (CDS)
 * и алгоритма контроля сроков годности и вскрытия упаковок (FEFO-Opened).
 *
 * Разработано в рамках магистерской диссертации 09.04.04 «Программная инженерия».
 * Проверяет устойчивость логики к семантическим мутациям, регистровым различиям
 * и пограничным временным условиям (0d, 1d, 30d, 31d, timezone shifts).
 */
class CdsAndFefoMutationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicineBox $box;
    protected Profile $profile;
    protected MedicationSafetyService $safetyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safetyService = new MedicationSafetyService();

        // 1. Базовый пользователь в таймзоне Europe/Moscow
        $this->user = User::create([
            'email' => 'doctor.safety@family.test',
            'password' => Hash::make('password123'),
            'timezone' => 'Europe/Moscow',
        ]);

        // 2. Аптечка пользователя
        $this->box = MedicineBox::create([
            'name' => 'Аптечка интенсивной терапии',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 3. Профиль пациента
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Клинический',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);
    }

    /**
     * Вспомогательный фабричный метод для создания эталонного препарата ГРЛС.
     */
    protected function createRefMedicine(array $attributes = []): ReferenceMedicine
    {
        return ReferenceMedicine::create(array_merge([
            'trade_name' => 'Тестовый препарат',
            'inn' => 'Тестовое вещество',
            'dosage_form' => 'Таблетки',
            'dosage' => '100 мг',
            'pack_size' => 10,
            'manufacturer' => 'ФармСтандарт',
            'country' => 'Россия',
            'atc_code' => 'N02BE01',
            'is_prescription' => false,
            'status' => 'active',
            'composition' => 'Действующее вещество 100 мг',
            'allergen_tags' => [],
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | ГРУППА 1: CDS (Clinical Decision Support - Клиническая безопасность)
    |--------------------------------------------------------------------------
    */

    /**
     * CDS.1: Проверка дублей МНН при несовпадающих торговых наименованиях.
     * Пациент уже принимает «Нурофен Форте», попытка назначить «Ибупрофен-Акрихин» с тем же МНН ibuprofen
     * должна вызывать предупреждение о риске токсической передозировки.
     */
    public function test_cds_detects_substance_overlap_with_different_trade_names_and_same_inn(): void
    {
        // Препарат 1: «Нурофен Форте», МНН: Ибупрофен
        $nurofenRef = $this->createRefMedicine([
            'trade_name' => 'Нурофен Форте',
            'inn' => 'Ибупрофен',
            'dosage' => '400 мг',
            'atc_code' => 'M01AE01',
            'composition' => 'Ибупрофен 400 мг',
            'allergen_tags' => ['nsaid'],
        ]);

        $nurofenMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $nurofenRef->id,
            'custom_trade_name' => 'Нурофен Форте',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today($this->user->timezone)->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Активный курс приёма Нурофена
        IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $nurofenMed->id,
            'title' => 'Курс Нурофена',
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таблетка после еды',
            'is_active' => true,
            'start_date' => Carbon::today($this->user->timezone)->toDateString(),
            'end_date' => Carbon::today($this->user->timezone)->addDays(10)->toDateString(),
        ]);

        // Препарат 2: «Ибупрофен-Акрихин», МНН: Ибупрофен (торговые наименования различны)
        $targetIbuprofenRef = $this->createRefMedicine([
            'trade_name' => 'Ибупрофен-Акрихин',
            'inn' => 'Ибупрофен',
            'dosage' => '200 мг',
            'atc_code' => 'M01AE01',
            'composition' => 'Ибупрофен 200 мг',
            'allergen_tags' => ['nsaid'],
        ]);

        $result = $this->safetyService->checkSafety($this->profile, $targetIbuprofenRef);

        $this->assertFalse($result['is_safe'], 'Назначение препарата с дублирующим МНН должно быть помечено как небезопасное.');
        $this->assertNotEmpty($result['warnings']);

        $hasOverlapWarning = collect($result['warnings'])->contains(function ($warning) {
            return str_contains($warning, 'дублирование действующего вещества')
                && str_contains($warning, 'Ибупрофен');
        });

        $this->assertTrue($hasOverlapWarning, 'CDS должен явно предупреждать о токсической передозировке из-за совпадения МНН.');
    }

    /**
     * CDS.2: Проверка перекрестной гиперчувствительности бета-лактамов.
     * У пациента аллергия на пенициллин, проверка назначения Амоксициллина
     * должна вызывать критическое предупреждение о риске анафилаксии.
     */
    public function test_cds_detects_beta_lactam_cross_hypersensitivity_for_amoxicillin_with_penicillin_allergy(): void
    {
        $this->profile->update([
            'allergies' => ['penicillin', 'beta-lactam'],
        ]);

        $amoxicillinRef = $this->createRefMedicine([
            'trade_name' => 'Амоксициллин Сандоз',
            'inn' => 'Амоксициллин',
            'dosage' => '500 мг',
            'atc_code' => 'J01CA04',
            'composition' => 'Амоксициллин тригидрат 500 мг',
            'allergen_tags' => ['penicillin'],
        ]);

        $result = $this->safetyService->checkSafety($this->profile, $amoxicillinRef);

        $this->assertFalse($result['is_safe']);
        $this->assertTrue($result['has_critical'], 'Аллергия на пенициллин при назначении амоксициллина является критической.');

        $hasCriticalPenicillinWarning = collect($result['warnings'])->contains(function ($warning) {
            return str_contains($warning, 'КРИТИЧЕСКАЯ ОПАСНОСТЬ')
                && str_contains($warning, 'пенициллиновый ряд')
                && str_contains($warning, 'анафилаксии');
        });

        $this->assertTrue($hasCriticalPenicillinWarning, 'Предупреждение должно содержать указание на риск анафилаксии.');
    }

    /**
     * CDS.3: Проверка эксципиентов (лактоза в составе таблетки при лактазной недостаточности).
     */
    public function test_cds_detects_excipient_lactose_intolerance_in_tablet_composition(): void
    {
        $this->profile->update([
            'allergies' => ['лактазная недостаточность'],
        ]);

        $lactoseDrugRef = $this->createRefMedicine([
            'trade_name' => 'Но-шпа',
            'inn' => 'Дротаверин',
            'dosage' => '40 мг',
            'atc_code' => 'A03AD02',
            'composition' => 'Дротаверина гидрохлорид 40 мг. Вспомогательные вещества: лактозы моногидрат (52 мг), крахмал кукурузный, тальк.',
            'allergen_tags' => ['lactose'],
        ]);

        $result = $this->safetyService->checkSafety($this->profile, $lactoseDrugRef);

        $this->assertFalse($result['is_safe']);
        $hasLactoseWarning = collect($result['warnings'])->contains(function ($warning) {
            return str_contains($warning, 'лактозу')
                && str_contains($warning, 'лактазная недостаточность');
        });

        $this->assertTrue($hasLactoseWarning, 'CDS должен идентифицировать лактозу в эксципиентах.');
    }

    /**
     * CDS.4.1: Противопоказание: НПВП при гастрите и язвенной болезни ЖКТ.
     */
    public function test_cds_detects_nsaid_contraindication_with_gastritis_and_peptic_ulcer(): void
    {
        $ulcerProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с язвой ЖКТ',
            'allergies' => [],
            'chronic_conditions' => ['язвенная болезнь', 'gastritis'],
        ]);

        $nsaidRef = $this->createRefMedicine([
            'trade_name' => 'Диклофенак Ретард',
            'inn' => 'Диклофенак',
            'dosage' => '100 мг',
            'atc_code' => 'M01AB05',
            'composition' => 'Диклофенак натрия 100 мг',
            'allergen_tags' => ['nsaid'],
        ]);

        $result = $this->safetyService->checkSafety($ulcerProfile, $nsaidRef);
        $this->assertFalse($result['is_safe']);
        $this->assertTrue($result['has_critical']);
        $this->assertTrue(collect($result['warnings'])->contains(function ($w) {
            return str_contains($w, 'ульцерогенным действием') && str_contains($w, 'гастрит/язвенная болезнь');
        }));
    }

    /**
     * CDS.4.2: Противопоказание: сосудосуживающие / псевдоэфедрин при гипертензии.
     */
    public function test_cds_detects_vasoconstrictor_contraindication_with_arterial_hypertension(): void
    {
        $hypertensionProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с артериальной гипертензией',
            'allergies' => [],
            'chronic_conditions' => ['гипертония', 'hypertension'],
        ]);

        $decongestantRef = $this->createRefMedicine([
            'trade_name' => 'Терафлю Экстра',
            'inn' => 'Парацетамол + Фенилэфрин',
            'dosage' => '650 мг + 10 мг',
            'atc_code' => 'R05X',
            'composition' => 'Парацетамол 650 мг, фенилэфрина гидрохлорид 10 мг',
            'allergen_tags' => ['decongestant'],
        ]);

        $result = $this->safetyService->checkSafety($hypertensionProfile, $decongestantRef);
        $this->assertFalse($result['is_safe']);
        $this->assertTrue(collect($result['warnings'])->contains(function ($w) {
            return str_contains($w, 'адреномиметики') && str_contains($w, 'артериальной гипертензией');
        }));
    }

    /**
     * CDS.4.3: Противопоказание: аспирин при бронхиальной астме (риск астматической триады).
     */
    public function test_cds_detects_aspirin_contraindication_with_bronchial_asthma(): void
    {
        $asthmaProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент с бронхиальной астмой',
            'allergies' => [],
            'chronic_conditions' => ['бронхиальная астма'],
        ]);

        $aspirinRef = $this->createRefMedicine([
            'trade_name' => 'Аспирин Кардио',
            'inn' => 'Ацетилсалициловая кислота',
            'dosage' => '100 мг',
            'atc_code' => 'B01AC06',
            'composition' => 'Ацетилсалициловая кислота 100 мг',
            'allergen_tags' => ['nsaid'],
        ]);

        $result = $this->safetyService->checkSafety($asthmaProfile, $aspirinRef);
        $this->assertFalse($result['is_safe']);
        $this->assertTrue(collect($result['warnings'])->contains(function ($w) {
            return str_contains($w, 'аспириновая астма') || str_contains($w, 'бронхоспазма');
        }));
    }

    /**
     * CDS.5.1: Мутационный тест: Пустой профиль пациента не порождает ложных срабатываний.
     */
    public function test_cds_mutation_empty_allergy_profile_produces_no_false_positives(): void
    {
        $emptyProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Чистый профиль',
            'allergies' => [],
            'chronic_conditions' => [],
        ]);

        $safeDrugRef = $this->createRefMedicine([
            'trade_name' => 'Парацетамол',
            'inn' => 'Парацетамол',
            'dosage' => '500 мг',
            'atc_code' => 'N02BE01',
            'composition' => 'Парацетамол 500 мг',
            'allergen_tags' => [],
        ]);

        $cleanResult = $this->safetyService->checkSafety($emptyProfile, $safeDrugRef);
        $this->assertTrue($cleanResult['is_safe'], 'При отсутствии аллергий и противопоказаний проверка должна возвращать is_safe = true.');
        $this->assertFalse($cleanResult['has_critical']);
        $this->assertEmpty($cleanResult['warnings']);
    }

    /**
     * CDS.5.2: Мутационный тест: Регистровые различия ('Penicillin' vs 'penicillin') обрабатываются безошибочно.
     */
    public function test_cds_mutation_case_insensitivity_in_allergies_and_chronic_conditions(): void
    {
        $caseInsensitiveProfile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Тест Регистра',
            'allergies' => ['PeNiCiLLiN', 'CiTrUs'],
            'chronic_conditions' => ['HyPeRtEnSiOn'],
        ]);

        $penicillinRef = $this->createRefMedicine([
            'trade_name' => 'Аугментин',
            'inn' => 'Амоксициллин + Клавулановая кислота',
            'dosage' => '400 мг / 57 мг',
            'atc_code' => 'J01CR02',
            'composition' => 'Амоксициллин, вспомогательный апельсиновый ароматизатор',
            'allergen_tags' => ['penicillin', 'citrus'],
        ]);

        $caseResult = $this->safetyService->checkSafety($caseInsensitiveProfile, $penicillinRef);
        $this->assertFalse($caseResult['is_safe']);
        $this->assertTrue($caseResult['has_critical']);
        $this->assertCount(2, $caseResult['warnings'], 'Должны быть обнаружены и пенициллин, и цитрусовые независимо от регистра.');
    }

    /*
    |--------------------------------------------------------------------------
    | ГРУППА 2: FEFO-Opened (Граничные случаи сроков и вскрытия)
    |--------------------------------------------------------------------------
    */

    /**
     * FEFO.1: Граница «ровно сегодня»:
     * Препарат со сроком годности сегодня в полночь переходит в статус 'expiring'
     * (сегодня — последний день годности, еще не просрочен, но истекает).
     */
    public function test_fefo_boundary_exactly_today_status_is_expiring(): void
    {
        $todayTz = Carbon::today($this->user->timezone)->toDateString();

        $todayMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Срок истекает сегодня',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => $todayTz,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $todayMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRING, $todayMed->status, 'Препарат со сроком годности сегодня должен получить статус EXPIRING.');
    }

    /**
     * FEFO.2: Граница «ровно 30 дней»:
     * Препарат со сроком годности ровно через 30 дней (today()->addDays(30)) переходит в статус 'expiring'
     * и получает соответствующее уведомление EXPIRY_WARNING_30D.
     */
    public function test_fefo_boundary_exactly_30_days_status_is_expiring(): void
    {
        $in30Days = Carbon::today($this->user->timezone)->addDays(30)->toDateString();

        $medIn30Days = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Ровно 30 дней до конца срока',
            'quantity' => 5,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => $in30Days,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $medIn30Days->refresh();
        $this->assertEquals(MedicineStatus::EXPIRING, $medIn30Days->status, 'Препарат с ровно 30 днями срока должен быть EXPIRING.');

        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $medIn30Days->id,
            'type' => NotificationLogType::EXPIRY_WARNING_30D,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * FEFO.3: Граница «через 31 день»:
     * Препарат со сроком годности ровно через 31 день сохраняет статус 'active'
     * и НЕ переходит в expiring.
     */
    public function test_fefo_boundary_in_31_days_status_remains_active(): void
    {
        $in31Days = Carbon::today($this->user->timezone)->addDays(31)->toDateString();

        $medIn31Days = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Запас на 31 день',
            'quantity' => 20,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => $in31Days,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $medIn31Days->refresh();
        $this->assertEquals(MedicineStatus::ACTIVE, $medIn31Days->status, 'Препарат со сроком 31 день не должен переходить в статус EXPIRING.');

        $this->assertDatabaseMissing('notification_logs', [
            'entity_id' => $medIn31Days->id,
        ]);
    }

    /**
     * FEFO.4: Граница «вчера»:
     * Препарат, срок которого истёк 1 день назад, переходит в статус 'expired'.
     */
    public function test_fefo_boundary_yesterday_status_is_expired(): void
    {
        $yesterday = Carbon::today($this->user->timezone)->subDay()->toDateString();

        $yesterdayMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Вчерашний просроченный сироп',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => $yesterday,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $yesterdayMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $yesterdayMed->status, 'Препарат со вчерашним сроком должен стать EXPIRED.');

        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $yesterdayMed->id,
            'type' => NotificationLogType::EXPIRY_CRITICAL,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * FEFO.5: Вскрытие флакона:
     * Заводской срок через 2 года, но после вскрытия срок 28 дней (days_after_opening = 28).
     * Если флакон вскрыт 30 дней назад — эффективный срок истёк, воркер переводит статус в 'expired'!
     */
    public function test_fefo_opened_bottle_expired_after_28_days_worker_marks_expired(): void
    {
        $factoryExpiry = Carbon::today($this->user->timezone)->addYears(2)->toDateString();
        $openedAt = Carbon::today($this->user->timezone)->subDays(30)->toDateString();

        $openedMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Глазные капли (вскрытые)',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => $factoryExpiry,
            'opened_at' => $openedAt,
            'days_after_opening' => 28,
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Проверяем вычисляемый атрибут effective_expiration_date
        $expectedEffectiveDate = Carbon::today($this->user->timezone)->subDays(2)->toDateString();
        $this->assertNotNull($openedMed->effective_expiration_date);
        $this->assertEquals($expectedEffectiveDate, $openedMed->effective_expiration_date->toDateString());

        // Запуск воркера проверки
        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $openedMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $openedMed->status, 'Вскрытый флакон с истёкшим сроком хранения после вскрытия должен получить статус EXPIRED.');

        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $openedMed->id,
            'type' => NotificationLogType::OPENED_EXPIRED,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * FEFO.6: Минимальный срок:
     * Заводской срок истекает через 5 дней, а после вскрытия срок 28 дней.
     * Побеждает заводской срок: min(factory, opened + shelf) = через 5 дней.
     */
    public function test_fefo_opened_minimum_rule_factory_date_wins_when_earlier_than_opened_shelf_life(): void
    {
        $factoryDate = Carbon::today($this->user->timezone)->addDays(5)->toDateString();
        $openedDate = Carbon::today($this->user->timezone)->toDateString();

        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Мазь с близким заводским сроком',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => $factoryDate,
            'opened_at' => $openedDate,
            'days_after_opening' => 28,
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Заводской срок: today + 5d; Срок после вскрытия: today + 28d -> побеждает 5d
        $this->assertNotNull($med->effective_expiration_date);
        $this->assertEquals($factoryDate, $med->effective_expiration_date->toDateString(), 'Эффективный срок должен быть равен заводскому, если он наступает раньше срока после вскрытия.');

        // Запуск воркера: препарат со сроком 5 дней переходит в EXPIRING
        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $med->refresh();
        $this->assertEquals(MedicineStatus::EXPIRING, $med->status);
    }

    /**
     * FEFO.7: Корректная обработка временных зон (Europe/Moscow):
     * Проверка вычисления сроков и границ суток в московском часовом поясе.
     * В 00:01:00 по Москве наступает новый день (2026-11-18), и препарат признается просроченным,
     * хотя в UTC на этот момент еще 21:01:00 предыдущего дня (2026-11-17).
     */
    public function test_fefo_opened_timezone_handling_europe_moscow(): void
    {
        // 1. Создаем препарат, вскрытый 15 ноября с лимитом 3 дня (срок истекает 18 ноября)
        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Антисептик Московский',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => '2026-11-30',
            'opened_at' => '2026-11-15',
            'days_after_opening' => 3, // Эффективный срок 2026-11-18
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->assertEquals('2026-11-18', $med->effective_expiration_date->toDateString());

        try {
            // 2. Фаза А: 17 ноября 23:45:00 по Москве (в UTC 20:45:00)
            // Препарат еще годен, но входит в 30-дневное окно истечения (EXPIRING)
            Carbon::setTestNow(Carbon::parse('2026-11-17 23:45:00', 'Europe/Moscow'));

            $this->artisan('medicines:check-expiry')->assertExitCode(0);
            $med->refresh();
            $this->assertEquals(MedicineStatus::EXPIRING, $med->status, 'До наступления полуночи препарат находится в статусе EXPIRING.');

            // 3. Фаза Б: 18 ноября 00:01:00 по Москве (в UTC еще 17 ноября 21:01:00!)
            // Поскольку владелец аптечки находится в Europe/Moscow, для него наступило 18 ноября,
            // следовательно, 3 дня после вскрытия истекли, и препарат должен стать EXPIRED!
            Carbon::setTestNow(Carbon::parse('2026-11-18 00:01:00', 'Europe/Moscow'));

            $this->artisan('medicines:check-expiry')->assertExitCode(0);
            $med->refresh();
            $this->assertEquals(MedicineStatus::EXPIRED, $med->status, 'После наступления полуночи по часовому поясу пользователя Europe/Moscow препарат признается EXPIRED.');
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
