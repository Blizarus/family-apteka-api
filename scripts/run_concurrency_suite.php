<?php

if (!getenv('DB_CONNECTION')) {
    putenv('DB_CONNECTION=mysql');
}
if (!getenv('DB_PORT')) {
    putenv('DB_PORT=3307');
}
if (!getenv('DB_DATABASE')) {
    putenv('DB_DATABASE=family_apteka_test');
}

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

// MySQL 8.4 LTS
config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => '127.0.0.1',
    'database.connections.mysql.port' => 3307,
    'database.connections.mysql.database' => 'family_apteka_test',
    'database.connections.mysql.username' => 'root',
    'database.connections.mysql.password' => '',
]);
\Illuminate\Support\Facades\DB::purge('mysql');
\Illuminate\Support\Facades\DB::reconnect('mysql');

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

$logFilePath = __DIR__ . '/../concurrency_runs.log';
$logFile = fopen($logFilePath, 'w');
$logOut = function (string $msg) use ($logFile) {
    echo $msg;
    if ($logFile) {
        fwrite($logFile, $msg);
    }
};

$logOut("=================================================================\n");
$logOut("  СТРЕСС-ТЕСТ КОНКУРЕНТНОСТИ (20 ПРОГОНОВ НА РЕАЛЬНОЙ MYSQL/MARIADB)\n");
$logOut("  Время запуска: " . date('Y-m-d H:i:s') . "\n");
$logOut("  СУБД: MySQL 8.4 LTS (Host: " . (getenv('DB_HOST') ?: '127.0.0.1') . ":" . (getenv('DB_PORT') ?: '3307') . ")\n");
$logOut("=================================================================\n");

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

$suiteRunId = uniqid('suite_', true);

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

    // Запускаем 10 параллельных процессов одновременно с одним ключом идемпотентности
    $idempotencyKey = "key-{$suiteRunId}-{$run}";
    $handles = [];
    $pipes = [];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($i = 0; $i < 10; $i++) {
        $cmd = "{$phpBinary} {$workerScript} --user-id={$user->id} --log-id={$log->id} --idempotency-key={$idempotencyKey}";
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

    // Проверяем ответы: 10 одновременных запросов с одним ключом идемпотентности
    $status200Count = 0;
    $errors = 0;
    $hashes = [];

    foreach ($responses as $resp) {
        $status = $resp['status'] ?? 0;
        if ($status === 200) {
            $status200Count++;
            $hashes[] = hash('sha256', json_encode($resp['content'] ?? []));
        } else {
            $errors++;
        }
    }

    $uniqueHashes = array_unique($hashes);
    $allIdenticalResponses = (count($uniqueHashes) === 1);

    // Дополнительно: запрос с тем же ключом, но другим телом (confirm-without-stock=1) обязан вернуть 422
    $diffPipes = [];
    $diffProc = proc_open(
        "{$phpBinary} {$workerScript} --user-id={$user->id} --log-id={$log->id} --idempotency-key={$idempotencyKey} --confirm-without-stock=1",
        $descriptors,
        $diffPipes
    );
    fclose($diffPipes[0]);
    $diffOut = stream_get_contents($diffPipes[1]);
    fclose($diffPipes[1]);
    fclose($diffPipes[2]);
    proc_close($diffProc);
    $diffJson = json_decode($diffOut, true);
    $diffStatus = $diffJson['status'] ?? 0;

    $medicine->refresh();
    $finalQuantity = (float) $medicine->quantity;
    $log->refresh();

    // Критерии ТЗ:
    // 1) Все 10 параллельных потоков получили HTTP 200 ($status200Count === 10)
    // 2) Все 10 потоков получили идентичный JSON-ответ ($allIdenticalResponses === true)
    // 3) Ровно 1 списание остатка (10.0 -> 9.0)
    // 4) Статус лога TAKEN
    // 5) Конфликтующее тело возвращает строгий HTTP 422 ($diffStatus === 422)
    $test1Pass = ($errors === 0) &&
                 ($status200Count === 10) &&
                 $allIdenticalResponses &&
                 ($finalQuantity === 9.0) &&
                 ($log->status === IntakeLogStatus::TAKEN) &&
                 ($diffStatus === 422);

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
    $line = sprintf(
        "Прогон %02d/20: %s | Тест 1 (10 потоков): HTTP 200=%d/10, идентичный JSON=%s, списание=1 (остаток=%.1f), конфликт=HTTP %d | Тест 2 (Последняя ед.): статусы=[%s], остаток=%.1f\n",
        $run,
        $statusIcon,
        $status200Count,
        $allIdenticalResponses ? 'YES' : 'NO',
        $finalQuantity,
        $diffStatus,
        implode(', ', $statuses),
        $lastQty
    );
    $logOut($line);

    // Удаляем тестовые логи, курсы и медикаменты
    IntakeLog::whereIn('id', [$log->id, $logA->id, $logB->id])->delete();
    IntakeCourse::whereIn('id', [$course->id, $courseA->id, $courseB->id])->delete();
    $medicine->delete();
    $lastMedicine->delete();
}

$logOut("=================================================================\n");
if ($allPassed) {
    $logOut("  ИТОГ: ВСЕ 20 ПРОГОНОВ УСПЕШНО ЗАВЕРШЕНЫ (100% ПРЕДОТВРАЩЕНИЕ ГОНОК)\n");
    $logOut("=================================================================\n");
    if ($logFile) fclose($logFile);
    exit(0);
} else {
    $logOut("  ИТОГ: ОБНАРУЖЕНЫ СБОИ В БЛОКИРОВКАХ КОНКУРЕНТНОСТИ!\n");
    $logOut("=================================================================\n");
    if ($logFile) fclose($logFile);
    exit(1);
}
