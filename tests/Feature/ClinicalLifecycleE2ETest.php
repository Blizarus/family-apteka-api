<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\IntakeScheduleFrequencyType;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сквозной интеграционный сценарий (E2E Clinical Lifecycle):
 * 1. Регистрация пользователя с согласием 152-ФЗ
 * 2. Создание профиля пациента с аллергией на пенициллин и хроническим гастритом
 * 3. Создание семейной аптечки
 * 4. Добавление лекарственного препарата (Амоксициллин, бета-лактам) с остатком 10 таблеток
 * 5. Проверка безопасности CDS-движком (MedicationSafetyService) -> обнаружение аллергического конфликта
 * 6. Назначение курса приёма и расписания
 * 7. Фиксация приёма (take) со списанием остатка (10 -> 9 таблеток)
 * 8. Формирование отчёта о комплаенсе/приверженности лечению (Adherence Report)
 */
class ClinicalLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_clinical_lifecycle_from_registration_to_adherence_report(): void
    {
        // -------------------------------------------------------------
        // ШАГ 1. Регистрация нового пользователя с согласием 152-ФЗ
        // -------------------------------------------------------------
        $registerPayload = [
            'name' => 'Ирина Маркова',
            'email' => 'irina_e2e@family.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'timezone' => 'Europe/Moscow',
            'terms_accepted' => true,
            'sync_enabled' => true,
        ];

        $registerResp = $this->postJson('/api/auth/register', $registerPayload);
        $registerResp->assertStatus(201)
            ->assertJsonStructure(['token', 'user' => ['id', 'email'], 'profile' => ['id', 'name']]);

        $token = $registerResp->json('token');
        $this->assertNotEmpty($token);
        $authHeaders = ['Authorization' => "Bearer {$token}"];

        // -------------------------------------------------------------
        // ШАГ 2. Создание клинического профиля с аллергией на пенициллин
        // -------------------------------------------------------------
        $profilePayload = [
            'name' => 'Ирина',
            'relationship' => 'self',
            'birth_date' => '1998-05-14',
            'allergies' => ['penicillin'],
            'conditions' => ['chronic_gastritis'],
        ];

        $profileResp = $this->withHeaders($authHeaders)->postJson('/api/profiles', $profilePayload);
        $profileResp->assertStatus(201);
        $profileId = $profileResp->json('data.id') ?? $profileResp->json('id');
        $this->assertNotNull($profileId);

        // -------------------------------------------------------------
        // ШАГ 3. Создание семейной аптечки
        // -------------------------------------------------------------
        $boxPayload = [
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME->value,
            'description' => 'Основная аптечка в гостиной',
        ];

        $boxResp = $this->withHeaders($authHeaders)->postJson('/api/medicine-boxes', $boxPayload);
        $boxResp->assertStatus(201);
        $boxId = $boxResp->json('data.id') ?? $boxResp->json('id');
        $this->assertNotNull($boxId);

        // -------------------------------------------------------------
        // ШАГ 4. Наполнение справочника и добавление препарата в аптечку
        // -------------------------------------------------------------
        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Амоксиклав',
            'inn' => 'Амоксициллин + Клавулановая кислота',
            'atc_code' => 'J01CR02',
            'dosage_form' => 'таблетки покрытые оболочкой',
            'dosage' => '500 мг + 125 мг',
            'pack_size' => 14,
            'manufacturer' => 'Lek d.d.',
            'country' => 'Словения',
            'status' => 'active',
            'allergen_tags' => ['penicillin', 'beta-lactam', 'amoxicillin'],
            'is_prescription' => true,
        ]);

        $medPayload = [
            'medicine_box_id' => $boxId,
            'reference_medicine_id' => $refMed->id,
            'custom_trade_name' => 'Амоксиклав 500 мг',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS->value,
            'expiration_date' => Carbon::now()->addMonths(12)->toDateString(),
            'notes' => 'Принимать строго по назначению врача',
        ];

        $medResp = $this->withHeaders($authHeaders)->postJson('/api/medicines', $medPayload);
        $medResp->assertStatus(201);
        $medicineId = $medResp->json('data.id') ?? $medResp->json('id');
        $this->assertNotNull($medicineId);

        // -------------------------------------------------------------
        // ШАГ 5. Проверка безопасности CDS: выявление аллергического конфликта
        // -------------------------------------------------------------
        $safetyResp = $this->withHeaders($authHeaders)->postJson('/api/medication-safety/check', [
            'profile_id' => $profileId,
            'medicine_id' => $medicineId,
        ]);

        $safetyResp->assertStatus(200);
        $safetyData = $safetyResp->json('data') ?? $safetyResp->json();
        $this->assertFalse($safetyData['is_safe'], 'CDS-движок обязан зафиксировать небезопасность назначения');
        $this->assertTrue($safetyData['has_critical'], 'CDS-движок обязан установить флаг критического риска');

        $warnings = $safetyData['warnings'];
        $hasPenicillinAllergyWarning = false;
        foreach ($warnings as $w) {
            if (str_contains(mb_strtolower($w), 'пенициллин')) {
                $hasPenicillinAllergyWarning = true;
            }
        }
        $this->assertTrue($hasPenicillinAllergyWarning, 'Ожидалось предупреждение о перекрестной аллергии на группу пенициллинов');

        // -------------------------------------------------------------
        // ШАГ 6. Назначение курса приёма с подтверждением предупреждения
        // -------------------------------------------------------------
        $coursePayload = [
            'profile_id' => $profileId,
            'medicine_id' => $medicineId,
            'title' => 'Антибиотикотерапия',
            'dosage_instruction' => '1 таблетка 2 раза в день во время еды',
            'dosage_quantity' => 1.0,
            'dosage_unit' => MedicineQuantityUnit::PILLS->value,
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->addDays(5)->toDateString(),
            'schedules' => [
                [
                    'time_of_day' => '09:00',
                    'frequency_type' => IntakeScheduleFrequencyType::DAILY->value,
                    'dosage_quantity' => 1.0,
                ],
                [
                    'time_of_day' => '21:00',
                    'frequency_type' => IntakeScheduleFrequencyType::DAILY->value,
                    'dosage_quantity' => 1.0,
                ],
            ],
        ];

        $courseResp = $this->withHeaders($authHeaders)->postJson('/api/intake-courses', $coursePayload);
        $courseResp->assertStatus(201);
        $courseId = $courseResp->json('data.id') ?? $courseResp->json('id');
        $this->assertNotNull($courseId);

        // -------------------------------------------------------------
        // ШАГ 7. Фиксация приёма и проверка списания остатка
        // -------------------------------------------------------------
        // Получаем созданный лог приёма (по умолчанию возвращает логи на сегодня)
        $logsResp = $this->withHeaders($authHeaders)->getJson('/api/intake-logs');
        $logsResp->assertStatus(200);
        $logs = $logsResp->json('data') ?? $logsResp->json();
        $this->assertNotEmpty($logs, 'Лог приёма должен быть сгенерирован автоматически');
        $targetLogId = $logs[0]['id'];

        // Выполняем фиксацию приёма
        $takeResp = $this->withHeaders($authHeaders)->postJson("/api/intake-logs/{$targetLogId}/take");
        $takeResp->assertStatus(200)
            ->assertJsonPath('data.status', IntakeLogStatus::TAKEN->value);

        // Проверяем списание остатка: было 10.0, стало 9.0
        $updatedMedResp = $this->withHeaders($authHeaders)->getJson("/api/medicines/{$medicineId}");
        $updatedMedResp->assertStatus(200);
        $updatedQty = (float) ($updatedMedResp->json('data.quantity') ?? $updatedMedResp->json('quantity'));
        $this->assertEquals(9.0, $updatedQty, 'Остаток препарата должен быть уменьшен ровно на 1 таблетку (с 10.0 до 9.0)');

        // -------------------------------------------------------------
        // ШАГ 8. Запрос аналитического отчёта о комплаенсе (Adherence Report)
        // -------------------------------------------------------------
        $reportResp = $this->withHeaders($authHeaders)->getJson("/api/reports/adherence?period=7_days&profile_id={$profileId}");
        $reportResp->assertStatus(200);

        $reportData = $reportResp->json();
        $this->assertEquals(1, $reportData['taken_count'], 'Должен быть зафиксирован 1 успешный приём');
        $this->assertEquals(100.0, (float) $reportData['adherence_rate'], 'Приверженность должна составлять 100%');
        $this->assertNotEmpty($reportData['daily_stats'], 'Отчёт должен содержать посуточную динамику');
        $this->assertNotEmpty($reportData['medicines_summary'], 'Отчёт должен содержать сводку по препаратам');
        $this->assertEquals('Амоксиклав 500 мг', $reportData['medicines_summary'][0]['name']);
    }
}
