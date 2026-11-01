<?php

/**
 * Стресс-тест конкурентности на реальной СУБД MySQL / MariaDB (InnoDB).
 * Проверяет:
 * 1. 10 одновременных вызовов take() на один лог: ровно 1 списание, 9 идемпотентных отсечек, остаток уменьшается ровно на 1.
 * 2. Конкурентное списание последней единицы (остаток = 1.0, два параллельных запроса):
 *    ровно один списывает в 0.0, второй получает 409 Conflict (requires_confirmation), остаток никогда не уходит в минус.
 * 3. 20 прогонов подряд без сбоев.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\IntakeLogStatus;
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
use Illuminate\Support\Facades\Hash;

$iterations = 20;
$allPassed = true;
$results = [];

echo "=================================================================\n";
echo "  СТРЕСС-ТЕСТ КОНКУРЕНТНОСТИ (20 ПРОГОНОВ НА РЕАЛЬНОЙ MYSQL/MARIADB)\n";
echo "=================================================================\n";

// Создание базового пользователя и аптечки
$user = User::firstOrCreate(['email' => 'concurrency_tester@family.test'], [
    'password' => Hash::make('secret123'),
    'is_admin' => false,
    'is_blocked' => false,
]);

$profile = Profile::firstOrCreate(['user_id' => $user->id, 'name' => 'Concurrency Profile'], [
    'allergies' => [],
]);

$box = MedicineBox::firstOrCreate(['name' => 'Concurrency Test Box'], [
    'box_type' => 'home',
]);

MedicineBoxUser::firstOrCreate([
    'medicine_box_id' => $box->id,
    'user_id' => $user->id,
], [
    'role' => 'owner',
]);

$phpBinary = PHP_BINARY;
$workerScript = escapeshellarg(__DIR__ . '/concurrency_worker.php');

for ($run = 1; $run <= $iterations; $run++) {
    // -------------------------------------------------------------------------
    // ТЕСТ 1: 10 параллельных запросов на ОДИН IntakeLog (остаток 10.0 -> 9.0)
    // -------------------------------------------------------------------------
    $medicine = Medicine::create([
        'medicine_box_id' => $box->id,
        'custom_trade_name' => "Парацетамол Concurrency #{$run}",
        'quantity' => 10.0,
        'quantity_unit' => MedicineQuantityUnit::PILLS,
        'expiration_date' => Carbon::now()->addMonths(6)->toDateString(),
        'status' => MedicineStatus::ACTIVE,
    ]);

    $course = IntakeCourse::create([
        'profile_id' => $profile->id,
        'medicine_id' => $medicine->id,
        'title' => "Курс #{$run}",
        'dosage_instruction' => '1 таблетка',
        'dosage_quantity' => 1.0,
        'dosage_unit' => MedicineQuantityUnit::PILLS,
        'start_date' => Carbon::today(),
    ]);

    $log = IntakeLog::create([
        'intake_course_id' => $course->id,
        'medicine_id' => $medicine->id,
        'scheduled_time' => Carbon::now(),
        'status' => IntakeLogStatus::SCHEDULED,
    ]);

    // Запускаем 10 параллельных процессов одновременно
    $handles = [];
    $pipes = [];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($i = 0; $i < 10; $i++) {
        $cmd = "{$phpBinary} {$workerScript} --user-id={$user->id} --log-id={$log->id}";
        $proc = proc_open($cmd, $descriptors, $pipes[$i]);
        if (is_resource($proc)) {
            $handles[$i] = $proc;
        }
    }

    $responses = [];
    foreach ($handles as $i => $proc) {
        fclose($pipes[$i][0]);
        $stdout = stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][2]);
        proc_close($proc);

        $json = json_decode($stdout, true);
        $responses[] = $json;
    }

    // Проверяем ответы
    $initialDeductions = 0;
    $idempotentPasses = 0;
    $errors = 0;

    foreach ($responses as $resp) {
        $msg = $resp['content']['message'] ?? '';
        if (str_contains($msg, 'Остаток списан')) {
            $initialDeductions++;
        } elseif (str_contains($msg, 'уже был зафиксирован ранее')) {
            $idempotentPasses++;
        } else {
            $errors++;
        }
    }

    $medicine->refresh();
    $finalQuantity = (float) $medicine->quantity;
    $log->refresh();

    $test1Pass = ($initialDeductions === 1) && ($idempotentPasses === 9) && ($finalQuantity === 9.0) && ($log->status === IntakeLogStatus::TAKEN);

    // -------------------------------------------------------------------------
    // ТЕСТ 2: Параллельное списание ПОСЛЕДНЕЙ единицы (остаток 1.0, 2 запроса)
    // -------------------------------------------------------------------------
    $lastMedicine = Medicine::create([
        'medicine_box_id' => $box->id,
        'custom_trade_name' => "Последняя ампула Concurrency #{$run}",
        'quantity' => 1.0,
        'quantity_unit' => MedicineQuantityUnit::AMPOULE,
        'expiration_date' => Carbon::now()->addMonths(6)->toDateString(),
        'status' => MedicineStatus::ACTIVE,
    ]);

    $courseA = IntakeCourse::create([
        'profile_id' => $profile->id,
        'medicine_id' => $lastMedicine->id,
        'title' => "Курс A #{$run}",
        'dosage_instruction' => '1 ампула',
        'dosage_quantity' => 1.0,
        'dosage_unit' => MedicineQuantityUnit::AMPOULE,
        'start_date' => Carbon::today(),
    ]);

    $courseB = IntakeCourse::create([
        'profile_id' => $profile->id,
        'medicine_id' => $lastMedicine->id,
        'title' => "Курс B #{$run}",
        'dosage_instruction' => '1 ампула',
        'dosage_quantity' => 1.0,
        'dosage_unit' => MedicineQuantityUnit::AMPOULE,
        'start_date' => Carbon::today(),
    ]);

    $logA = IntakeLog::create([
        'intake_course_id' => $courseA->id,
        'medicine_id' => $lastMedicine->id,
        'scheduled_time' => Carbon::now(),
        'status' => IntakeLogStatus::SCHEDULED,
    ]);

    $logB = IntakeLog::create([
        'intake_course_id' => $courseB->id,
        'medicine_id' => $lastMedicine->id,
        'scheduled_time' => Carbon::now(),
        'status' => IntakeLogStatus::SCHEDULED,
    ]);

    // Запускаем 2 параллельных процесса одновременно
    $handles2 = [];
    $pipes2 = [];
    $procA = proc_open("{$phpBinary} {$workerScript} --user-id={$user->id} --log-id={$logA->id}", $descriptors, $pipes2[0]);
    $procB = proc_open("{$phpBinary} {$workerScript} --user-id={$user->id} --log-id={$logB->id}", $descriptors, $pipes2[1]);
    $handles2 = [$procA, $procB];

    $resp2 = [];
    foreach ($handles2 as $idx => $p) {
        fclose($pipes2[$idx][0]);
        $out = stream_get_contents($pipes2[$idx][1]);
        fclose($pipes2[$idx][1]);
        fclose($pipes2[$idx][2]);
        proc_close($p);
        $resp2[] = json_decode($out, true);
    }

    $lastMedicine->refresh();
    $lastQty = (float) $lastMedicine->quantity;

    // Ровно один должен вернуть 200 со списанием, второй — 409 с requires_confirmation
    $statuses = array_map(fn($r) => $r['status'] ?? 0, $resp2);
    sort($statuses);

    $test2Pass = ($statuses === [200, 409]) && ($lastQty === 0.0) && ($lastMedicine->status === MedicineStatus::FINISHED);

    $runPassed = $test1Pass && $test2Pass;
    if (!$runPassed) {
        $allPassed = false;
        foreach ($resp2 as $r) {
            if (($r['status'] ?? 0) === 500) {
                echo "DEBUG 500 error: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }

    $statusIcon = $runPassed ? "[OK]" : "[FAIL]";
    echo sprintf(
        "Прогон %02d/20: %s | Тест 1 (10 потоков): списаний=%d, отсечек=%d, остаток=%.1f | Тест 2 (Последняя ед.): статусы=[%s], остаток=%.1f\n",
        $run,
        $statusIcon,
        $initialDeductions,
        $idempotentPasses,
        $finalQuantity,
        implode(', ', $statuses),
        $lastQty
    );

    // Удаляем тестовые логи, курсы и медикаменты
    IntakeLog::whereIn('id', [$log->id, $logA->id, $logB->id])->delete();
    IntakeCourse::whereIn('id', [$course->id, $courseA->id, $courseB->id])->delete();
    $medicine->delete();
    $lastMedicine->delete();
}

echo "=================================================================\n";
if ($allPassed) {
    echo "  ИТОГ: ВСЕ 20 ПРОГОНОВ УСПЕШНО ЗАВЕРШЕНЫ (100% ПРЕДОТВРАЩЕНИЕ ГОНОК)\n";
    echo "=================================================================\n";
    exit(0);
} else {
    echo "  ИТОГ: ОБНАРУЖЕНЫ СБОИ В БЛОКИРОВКАХ КОНКУРЕНТНОСТИ!\n";
    echo "=================================================================\n";
    exit(1);
}
