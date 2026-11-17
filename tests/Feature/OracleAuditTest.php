<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\NotificationLog;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use App\NotificationLogType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OracleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_all_parameterized_routes_for_oracle_leaks(): void
    {
        // 1. Создаем владельца (User A) и атакующего (User B)
        $userA = User::factory()->create(['email' => 'owner@example.com']);
        $userB = User::factory()->create(['email' => 'attacker@example.com']);

        // 2. Создаем сущности владельца
        $profileA = Profile::create([
            'user_id' => $userA->id,
            'name' => 'Профиль Владельца',
            'relationship' => 'self',
        ]);

        $boxA = MedicineBox::create([
            'name' => 'Аптечка Владельца',
            'box_type' => 'home',
        ]);
        $boxA->users()->attach($userA->id, ['role' => 'owner']);

        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Тестовый Препарат',
            'inn' => 'Парацетамол',
            'atc_code' => 'N02BE01',
            'dosage_form' => 'таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $medicineA = Medicine::create([
            'medicine_box_id' => $boxA->id,
            'reference_medicine_id' => $refMed->id,
            'custom_trade_name' => 'Парацетамол 500',
            'form' => 'таблетки',
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear()->format('Y-m-d'),
            'status' => 'active',
        ]);

        $courseA = IntakeCourse::create([
            'profile_id' => $profileA->id,
            'medicine_id' => $medicineA->id,
            'title' => 'Курс обезболивающего',
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'dosage_quantity' => 1,
            'dosage_instruction' => '1 таб при боли',
            'is_active' => true,
        ]);

        $logA = IntakeLog::create([
            'intake_course_id' => $courseA->id,
            'medicine_id' => $medicineA->id,
            'scheduled_time' => Carbon::now()->format('Y-m-d H:i:s'),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        $notifA = NotificationLog::create([
            'user_id' => $userA->id,
            'entity_type' => User::class,
            'entity_id' => $userA->id,
            'type' => NotificationLogType::INTAKE_REMINDER,
            'title' => 'Напоминание',
            'message' => 'Тест',
            'channel' => 'push',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Список всех параметризованных приватных маршрутов с {id}
        // [method, uri_template, foreign_id, body]
        $routesToAudit = [
            // Profiles
            ['GET', '/api/profiles/{id}', $profileA->id, []],
            ['PUT', '/api/profiles/{id}', $profileA->id, ['name' => 'Updated']],
            ['DELETE', '/api/profiles/{id}', $profileA->id, []],

            // Medicine Boxes
            ['GET', '/api/medicine-boxes/{id}', $boxA->id, []],
            ['PUT', '/api/medicine-boxes/{id}', $boxA->id, ['name' => 'Updated']],
            ['DELETE', '/api/medicine-boxes/{id}', $boxA->id, []],
            ['GET', '/api/medicine-boxes/{id}/members', $boxA->id, []],
            ['POST', '/api/medicine-boxes/{id}/members', $boxA->id, ['email' => 'guest@example.com', 'role' => 'viewer']],

            // Medicines
            ['GET', '/api/medicines/{id}', $medicineA->id, []],
            ['PUT', '/api/medicines/{id}', $medicineA->id, ['custom_trade_name' => 'Updated']],
            ['DELETE', '/api/medicines/{id}', $medicineA->id, []],
            ['POST', '/api/medicines/{id}/open', $medicineA->id, []],
            ['POST', '/api/medicines/{id}/move', $medicineA->id, ['target_box_id' => $boxA->id]],

            // Intake Courses
            ['GET', '/api/intake-courses/{id}', $courseA->id, []],
            ['PUT', '/api/intake-courses/{id}', $courseA->id, ['dosage_quantity' => 2]],
            ['DELETE', '/api/intake-courses/{id}', $courseA->id, []],
            ['POST', '/api/intake-courses/{id}/extend', $courseA->id, ['days' => 5]],

            // Intake Logs
            ['GET', '/api/intake-logs/{id}', $logA->id, []],
            ['POST', '/api/intake-logs/{id}/take', $logA->id, []],
            ['POST', '/api/intake-logs/{id}/skip', $logA->id, ['reason' => 'test']],
            ['POST', '/api/intake-logs/{id}/snooze', $logA->id, ['minutes' => 15]],
            ['POST', '/api/intake-logs/{id}/reset-snooze', $logA->id, []],
            ['POST', '/api/intake-logs/{id}/undo', $logA->id, []],

            // Notifications
            ['DELETE', '/api/notifications/{id}', $notifA->id, []],
        ];

        $results = [];
        $oracleLeaks = [];

        foreach ($routesToAudit as [$method, $template, $foreignId, $body]) {
            $foreignUrl = str_replace('{id}', (string)$foreignId, $template);
            $nonExistentUrl = str_replace('{id}', '999999', $template);
            $malformedUrl = str_replace('{id}', 'abc', $template);

            // 1. Чужой существующий ID
            $resForeign = $this->actingAs($userB)->json($method, $foreignUrl, $body);
            $statusForeign = $resForeign->getStatusCode();

            // 2. Несуществующий ID
            $resNonExistent = $this->actingAs($userB)->json($method, $nonExistentUrl, $body);
            $statusNonExistent = $resNonExistent->getStatusCode();

            // 3. Некорректный ID (строка)
            $resMalformed = $this->actingAs($userB)->json($method, $malformedUrl, $body);
            $statusMalformed = $resMalformed->getStatusCode();

            // Утечка оракула: статус для чужого ID отличается от статуса для несуществующего ID
            $isLeak = ($statusForeign !== $statusNonExistent);

            $entry = [
                'method' => $method,
                'route' => $template,
                'foreign_status' => $statusForeign,
                'non_existent_status' => $statusNonExistent,
                'malformed_status' => $statusMalformed,
                'is_oracle_leak' => $isLeak,
            ];

            $results[] = $entry;
            if ($isLeak) {
                $oracleLeaks[] = $entry;
            }
        }

        $reportJson = json_encode([
            'timestamp' => now()->toIso8601String(),
            'total_tested' => count($results),
            'leaks_count' => count($oracleLeaks),
            'oracle_leaks' => $oracleLeaks,
            'all_results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents(base_path('../../experiments/logs/oracle_audit.json'), $reportJson);

        echo PHP_EOL . "=== ORACLE AUDIT RESULTS ===" . PHP_EOL;
        echo "Total routes tested: " . count($results) . PHP_EOL;
        echo "Oracle leaks detected: " . count($oracleLeaks) . PHP_EOL;
        foreach ($results as $r) {
            $flag = $r['is_oracle_leak'] ? ' [LEAK!]' : ' [EQUAL]';
            echo sprintf(
                "%-6s %-38s -> Foreign: %d | NonExistent: %d | Malformed: %d %s\n",
                $r['method'],
                $r['route'],
                $r['foreign_status'],
                $r['non_existent_status'],
                $r['malformed_status'],
                $flag
            );
        }

        $this->assertNotEmpty($results);
    }
}
