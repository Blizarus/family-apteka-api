<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Гарантированное переключение на MySQL 8.4 LTS
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

// Получаем параметры из аргументов командной строки
$options = getopt('', ['user-id:', 'log-id:', 'confirm-without-stock:', 'idempotency-key:']);

$userId = (int) ($options['user-id'] ?? 1);
$logId = (int) ($options['log-id'] ?? 1);
$confirmWithoutStock = !empty($options['confirm-without-stock']);
$idempotencyKey = $options['idempotency-key'] ?? null;

$user = App\Models\User::find($userId);
if (!$user) {
    echo json_encode(['error' => 'User not found', 'status' => 500]);
    exit(1);
}

$requestParams = [
    'confirm_without_stock' => $confirmWithoutStock,
];
if ($idempotencyKey) {
    $requestParams['idempotency_key'] = $idempotencyKey;
}

$request = Illuminate\Http\Request::create("/api/intake-logs/{$logId}/take", 'POST', $requestParams);
if ($idempotencyKey) {
    $request->headers->set('X-Idempotency-Key', $idempotencyKey);
}
$request->setUserResolver(fn () => $user);

$controller = app(App\Http\Controllers\Api\IntakeLogController::class);

try {
    $response = $controller->take($request, $logId);
    echo json_encode([
        'status' => $response->getStatusCode(),
        'content' => json_decode($response->getContent(), true),
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'status' => 500,
    ]);
}
