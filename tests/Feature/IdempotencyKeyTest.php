<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;
    protected ReferenceMedicine $referenceMedicine;
    protected IntakeCourse $course;
    protected IntakeLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'idempotency@family.test',
            'is_blocked' => false,
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Идемпотентный Профиль',
            'relationship' => 'self',
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка теста идемпотентности',
            'box_type' => 'home',
        ]);
        $this->box->users()->attach($this->user->id, ['role' => 'owner']);

        $this->referenceMedicine = ReferenceMedicine::create([
            'trade_name' => 'Ибупрофен Тест',
            'inn' => 'Ibuprofen',
            'atc_code' => 'M01AE01',
            'dosage_form' => 'Таблетки',
            'dosage' => '400 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $this->referenceMedicine->id,
            'custom_trade_name' => 'Ибупрофен Тест',
            'form' => 'таблетки',
            'quantity' => 10.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear(),
            'status' => 'active',
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс обезболивающего',
            'start_date' => Carbon::today(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб при боли',
            'is_active' => true,
        ]);

        $this->log = IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::now(),
            'status' => IntakeLogStatus::SCHEDULED,
        ]);
    }

    public function test_idempotency_keys_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('idempotency_keys'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('idempotency_keys', [
            'id', 'user_id', 'key', 'request_hash', 'response_code', 'response_body', 'created_at', 'updated_at'
        ]));
    }

    public function test_quick_take_with_same_key_and_same_body_returns_stored_response(): void
    {
        $payload = [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'dose' => 1.0,
            'symptom' => 'Головная боль',
            'idempotency_key' => 'unique-key-12345',
        ];

        // Первый вызов
        $response1 = $this->actingAs($this->user)
            ->postJson('/api/intake-logs/quick', $payload);

        $response1->assertStatus(201);
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // Повторный вызов с тем же ключом и тем же телом
        $response2 = $this->actingAs($this->user)
            ->postJson('/api/intake-logs/quick', $payload);

        $response2->assertStatus(201);
        $response2->assertJson($response1->json());

        // Остаток не должен списаться повторно
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // В таблице idempotency_keys должна быть ровно одна запись
        $this->assertDatabaseHas('idempotency_keys', [
            'user_id' => $this->user->id,
            'key' => 'unique-key-12345',
            'response_code' => 201,
        ]);
    }

    public function test_quick_take_with_same_key_and_different_body_returns_422(): void
    {
        $payload1 = [
            'medicine_id' => $this->medicine->id,
            'profile_id' => $this->profile->id,
            'dose' => 1.0,
            'symptom' => 'Головная боль',
            'idempotency_key' => 'unique-conflict-key-999',
        ];

        $response1 = $this->actingAs($this->user)
            ->postJson('/api/intake-logs/quick', $payload1);

        $response1->assertStatus(201);

        // Повторный вызов с тем же ключом, но другим телом (другая доза)
        $payload2 = $payload1;
        $payload2['dose'] = 2.0;

        $response2 = $this->actingAs($this->user)
            ->postJson('/api/intake-logs/quick', $payload2);

        $response2->assertStatus(422);
        $this->assertStringContainsString('ключ', mb_strtolower($response2->json('message')));
    }

    public function test_take_with_idempotency_key_returns_stored_response(): void
    {
        $payload = [
            'idempotency_key' => 'take-key-abc',
        ];

        $response1 = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/take", $payload);

        $response1->assertStatus(200);
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // Повтор
        $response2 = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/take", $payload);

        $response2->assertStatus(200);
        $this->medicine->refresh();
        $this->assertEquals(9.0, (float) $this->medicine->quantity);

        // Повтор с другим телом
        $response3 = $this->actingAs($this->user)
            ->postJson("/api/intake-logs/{$this->log->id}/take", [
                'idempotency_key' => 'take-key-abc',
                'confirm_without_stock' => true,
            ]);

        $response3->assertStatus(422);
    }
}
