<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'concurrency@family.test',
            'is_blocked' => false,
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Тест',
            'relationship' => 'self',
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка Тест',
            'box_type' => 'home',
        ]);
        $this->box->users()->attach($this->user->id, ['role' => 'owner']);

        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Парацетамол Референс',
            'inn' => 'Paracetamol',
            'atc_code' => 'N02BE01',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $refMed->id,
            'custom_trade_name' => 'Парацетамол Референс',
            'form' => 'таблетки',
            'quantity' => 10.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear(),
            'status' => 'active',
        ]);
    }

    /**
     * DEF-43: При незавершенной конкурирующей транзакции возвращается 409 с Retry-After: 1 без spin-lock.
     */
    public function test_in_progress_idempotency_key_immediately_returns_409_conflict(): void
    {
        $payload = [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'dose' => 1.0,
            'symptom' => 'Жар',
            'idempotency_key' => 'concurrent-key-409',
        ];

        $unfiltered = $payload;
        unset($unfiltered['idempotency_key']);
        ksort($unfiltered);
        $requestHash = hash('sha256', json_encode($unfiltered));

        // Имитируем зависшую/обрабатываемую в другом потоке транзакцию (response_code is null)
        DB::table('idempotency_keys')->insert([
            'user_id' => $this->user->id,
            'key' => 'concurrent-key-409',
            'request_hash' => $requestHash,
            'response_code' => null,
            'response_body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $startTime = microtime(true);

        $response = $this->actingAs($this->user)
            ->postJson('/api/intake-logs/quick', $payload);

        $duration = microtime(true) - $startTime;

        // Должно вернуть 409 немедленно (без spin-lock ожидания 4 секунды)
        $this->assertLessThan(1.0, $duration, 'Spin-lock DoS detected: response took >= 1.0 seconds');
        $response->assertStatus(409);
        $this->assertEquals('1', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('уже обрабатывается', $response->json('message'));
    }
}
