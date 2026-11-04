<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\NotificationLog;
use App\NotificationLogType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

$u = User::where('email', 'anna@family.test')->first();
if (!$u) {
    Artisan::call('db:seed', ['--force' => true]);
    $u = User::where('email', 'anna@family.test')->first();
} else {
    $u->password = Hash::make('password');
    $u->save();
}

// Удаляем временных пользователей от предыдущих прогонов
User::where('email', 'like', 'new_user%')->delete();

// Гарантируем наличие системного уведомления для contract-тестов
if ($u && NotificationLog::where('user_id', $u->id)->count() === 0) {
    NotificationLog::create([
        'user_id' => $u->id,
        'entity_type' => User::class,
        'entity_id' => $u->id,
        'type' => NotificationLogType::EXPIRY_WARNING_7D,
        'title' => 'Истекает срок годности',
        'message' => 'Препарат Нурофен истекает через 3 дня.',
        'channel' => 'push',
        'status' => 'sent',
        'sent_at' => now(),
    ]);
}

echo "Database successfully prepared and seeded.\n";
