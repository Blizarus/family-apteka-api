<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idempotency:prune {--hours=72 : Количество часов, после которых ключи считаются устаревшими}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистка устаревших записей ключей идемпотентности из таблицы idempotency_keys (DEF-51)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours <= 0) {
            $hours = 72;
        }

        $cutoff = Carbon::now()->subHours($hours);

        $deletedCount = DB::table('idempotency_keys')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Удалено устаревших ключей идемпотентности: {$deletedCount} (старше {$hours} ч.)");

        return self::SUCCESS;
    }
}
