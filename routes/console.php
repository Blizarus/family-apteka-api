<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневная очистка устаревших ключей идемпотентности (старше 72 часов, DEF-51)
Schedule::command('idempotency:prune --hours=72')->daily();
