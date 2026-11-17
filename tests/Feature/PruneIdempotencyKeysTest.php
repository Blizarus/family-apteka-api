<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DEF-51: Artisan-команда idempotency:prune удаляет устаревшие ключи идемпотентности.
     */
    public function test_prune_command_deletes_expired_idempotency_keys(): void
    {
        $user = User::factory()->create([
            'email' => 'prune@family.test',
        ]);

        // 1. Старая запись: создана 75 часов назад (должна быть удалена)
        DB::table('idempotency_keys')->insert([
            'user_id' => $user->id,
            'key' => 'expired-key-75h',
            'request_hash' => hash('sha256', 'old'),
            'response_code' => 200,
            'response_body' => json_encode(['status' => 'old']),
            'created_at' => Carbon::now()->subHours(75),
            'updated_at' => Carbon::now()->subHours(75),
        ]);

        // 2. Свежая запись: создана 24 часа назад (должна остаться)
        DB::table('idempotency_keys')->insert([
            'user_id' => $user->id,
            'key' => 'fresh-key-24h',
            'request_hash' => hash('sha256', 'fresh'),
            'response_code' => 200,
            'response_body' => json_encode(['status' => 'fresh']),
            'created_at' => Carbon::now()->subHours(24),
            'updated_at' => Carbon::now()->subHours(24),
        ]);

        $this->assertEquals(2, DB::table('idempotency_keys')->count());

        $this->artisan('idempotency:prune', ['--hours' => 72])
            ->expectsOutputToContain('Удалено устаревших ключей идемпотентности: 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('idempotency_keys', [
            'key' => 'expired-key-75h',
        ]);

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'fresh-key-24h',
        ]);
    }
}
