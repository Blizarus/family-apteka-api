<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$dbPath = database_path('concurrency_test.sqlite');
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $dbPath,
]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');
\Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=5000;');

use App\IntakeLogStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Очистка
DB::table('idempotency_keys')->delete();
DB::table('intake_logs')->delete();
DB::table('intake_courses')->delete();
DB::table('medicines')->delete();
DB::table('reference_medicines')->delete();
DB::table('medicine_box_users')->delete();
DB::table('medicine_boxes')->delete();
DB::table('profiles')->delete();
DB::table('users')->where('email', 'test_concurrency@example.com')->delete();

$user = User::create([
    'email' => 'test_concurrency@example.com',
    'password' => 'secret',
    'timezone' => 'Europe/Moscow',
    'sync_enabled' => false,
    'is_blocked' => false,
    'is_admin' => false,
]);

$profile = Profile::create([
    'user_id' => $user->id,
    'name' => 'Concurrency Profile',
]);

$box = MedicineBox::create([
    'name' => 'Concurrency Box',
    'box_type' => 'home',
]);

MedicineBoxUser::create([
    'medicine_box_id' => $box->id,
    'user_id' => $user->id,
    'role' => 'owner',
]);

$refMed = ReferenceMedicine::create([
    'trade_name' => 'Ибупрофен 400',
    'inn' => 'Ibuprofen',
    'atc_code' => 'M01AE01',
    'dosage_form' => 'таблетки',
    'dosage' => '400 мг',
    'pack_size' => 10,
    'manufacturer' => 'Фармстандарт',
    'country' => 'РФ',
    'is_prescription' => false,
    'status' => 'active',
]);

$medicine = Medicine::create([
    'medicine_box_id' => $box->id,
    'reference_medicine_id' => $refMed->id,
    'custom_trade_name' => 'Ибупрофен Тест',
    'quantity' => 10.0,
    'quantity_unit' => 'pill',
    'expiration_date' => now()->addYear(),
    'status' => 'active',
]);

$course = IntakeCourse::create([
    'profile_id' => $profile->id,
    'medicine_id' => $medicine->id,
    'title' => 'Курс 1',
    'start_date' => now(),
    'dosage_quantity' => 1.0,
    'dosage_instruction' => '1 таб при боли',
    'is_active' => true,
]);

$log = IntakeLog::create([
    'intake_course_id' => $course->id,
    'medicine_id' => $medicine->id,
    'scheduled_time' => now(),
    'status' => IntakeLogStatus::SCHEDULED,
]);

echo json_encode([
    'user_id' => $user->id,
    'medicine_id' => $medicine->id,
    'log_id' => $log->id,
]);
