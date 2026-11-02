<?php

/**
 * Воспроизведение ошибки конкурентности в старой реализации take() (до введения lockForUpdate).
 * Старая логика:
 * 1. Проверка if ($log->status === TAKEN) до транзакции.
 * 2. Чтение остатка $medicine->quantity в память PHP.
 * 3. $medicine->quantity = max(0.0, $medicine->quantity - $amount);
 * 4. $medicine->save() без блокировок.
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

echo "=================================================================\n";
echo "  ТЕСТ СТАРОЙ РЕАЛИЗАЦИИ take() НА MYSQL 8.4 (БЕЗ lockForUpdate)\n";
echo "=================================================================\n";

$user = User::firstOrCreate(['email' => 'old_bug_tester@family.test'], [
    'password' => Hash::make('secret123'),
    'is_admin' => false,
    'is_blocked' => false,
]);

$profile = Profile::firstOrCreate(['user_id' => $user->id, 'name' => 'Bug Profile'], [
    'allergies' => [],
]);

$box = MedicineBox::firstOrCreate(['name' => 'Bug Test Box'], [
    'box_type' => 'home',
]);

MedicineBoxUser::firstOrCreate([
    'medicine_box_id' => $box->id,
    'user_id' => $user->id,
], [
    'role' => 'owner',
]);

// Создаем медикамент с остатком 10.0
$medicine = Medicine::create([
    'medicine_box_id' => $box->id,
    'custom_trade_name' => 'Тест Уязвимости Конкурентности',
    'quantity' => 10.0,
    'quantity_unit' => MedicineQuantityUnit::PILLS,
    'expiration_date' => Carbon::now()->addMonths(6)->toDateString(),
    'status' => MedicineStatus::ACTIVE,
]);

$course = IntakeCourse::create([
    'profile_id' => $profile->id,
    'medicine_id' => $medicine->id,
    'title' => 'Курс для воспроизведения бага',
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

// Создаем воркер старой логики
$workerCode = <<<'PHP'
<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\IntakeLogStatus;
use App\Models\IntakeLog;
use App\Models\Medicine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$logId = (int) $argv[1];

try {
    // СТАРАЯ ЛОГИКА (БЕЗ lockForUpdate):
    // 1. Поиск без блокировки
    $intakeLog = IntakeLog::with(['intakeCourse.medicine', 'medicine'])->findOrFail($logId);

    // 2. Проверка статуса до транзакции
    if ($intakeLog->status === IntakeLogStatus::TAKEN) {
        echo json_encode(['status' => 200, 'type' => 'already_taken']);
        exit(0);
    }

    // Имитация небольшой микропаузы между проверкой и транзакцией (как в реальной ОС)
    usleep(random_int(1000, 5000));

    // 3. Неблокирующая транзакция
    DB::transaction(function () use ($intakeLog) {
        $intakeLog->status = IntakeLogStatus::TAKEN;
        $intakeLog->taken_time = Carbon::now();
        $intakeLog->quantity_taken = 1.0;
        $intakeLog->save();

        // Неатомарное чтение в память и перезапись
        $medicine = Medicine::find($intakeLog->medicine_id);
        if ($medicine) {
            $newQuantity = max(0.0, (float) $medicine->quantity - 1.0);
            // Пауза перед записью для гарантированного проявления состояния гонки
            usleep(random_int(1000, 3000));
            $medicine->quantity = $newQuantity;
            $medicine->save();
        }
    });

    echo json_encode(['status' => 200, 'type' => 'deducted']);
} catch (\Throwable $e) {
    echo json_encode(['status' => 500, 'error' => $e->getMessage()]);
}
PHP;

$workerFile = __DIR__ . '/old_take_worker.php';
file_put_contents($workerFile, $workerCode);

// Запускаем 10 параллельных процессов одновременно
$handles = [];
$pipes = [];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$phpBinary = PHP_BINARY;
for ($i = 0; $i < 10; $i++) {
    $cmd = "\"{$phpBinary}\" \"{$workerFile}\" {$log->id}";
    $proc = proc_open($cmd, $descriptors, $pipes[$i]);
    if (is_resource($proc)) {
        $handles[$i] = $proc;
    }
}

$responses = [];
for ($i = 0; $i < 10; $i++) {
    if (isset($pipes[$i][1])) {
        $out = stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][0]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($handles[$i]);
        $data = json_decode(trim($out), true);
        $responses[] = $data ?: ['status' => 0, 'raw' => $out];
    }
}

@unlink($workerFile);

$deductions = 0;
$alreadyTaken = 0;
$errors = 0;
foreach ($responses as $r) {
    if (($r['type'] ?? '') === 'deducted') {
        $deductions++;
    } elseif (($r['type'] ?? '') === 'already_taken') {
        $alreadyTaken++;
    } else {
        $errors++;
    }
}

$medicine->refresh();
$finalStock = (float) $medicine->quantity;

echo "Результаты 10 одновременных вызовов старой версии take():\n";
echo "  - Попыток списания (прошли проверку статуса): {$deductions} (ожидалось: 1)\n";
echo "  - Идемпотентных отсечек: {$alreadyTaken} (ожидалось: 9)\n";
echo "  - Ошибок / Deadlocks: {$errors}\n";
echo "  - Исходный остаток: 10.0\n";
echo "  - Итоговый остаток на MySQL 8.4: {$finalStock} (должно быть ровно 9.0 при корректной блокировке)\n";

if ($deductions > 1 || $finalStock !== 9.0 || $errors > 0) {
    echo "\n>>> [ВОСПРОИЗВЕДЕНО]: Ошибка конкурентности (состояние гонки / Race Condition / Lost Updates) гарантированно воспроизводится на MySQL 8.4!\n";
    echo "    Несколько потоков одновременно считали статус scheduled и перезаписали остаток.\n";
} else {
    echo "\n>>> Ошибка не воспроизвелась в этом единичном прогоне.\n";
}

// Очистка
$log->delete();
$course->delete();
$medicine->delete();
