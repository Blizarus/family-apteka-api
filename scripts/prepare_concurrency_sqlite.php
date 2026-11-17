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

\Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode=WAL;');
\Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=5000;');

\Illuminate\Support\Facades\Artisan::call('migrate:fresh', [
    '--database' => 'sqlite',
    '--force' => true,
]);

echo \Illuminate\Support\Facades\Artisan::output();
echo "Concurrency SQLite ready at: " . $dbPath . PHP_EOL;
