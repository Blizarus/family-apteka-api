<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineBoxAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $attacker;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Владелец аптечки
        $this->owner = User::create([
            'email' => 'owner@family.test',
            'password' => Hash::make('password'),
        ]);

        // 2. Посторонний пользователь (потенциальный атакующий IDOR)
        $this->attacker = User::create([
            'email' => 'attacker@evil.test',
            'password' => Hash::make('password'),
        ]);

        // 3. Аптечка владельца
        $this->box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
            'description' => 'Шкаф в коридоре',
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    public function test_owner_can_view_own_box(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/medicine-boxes/' . $this->box->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Домашняя аптечка');
    }

    public function test_idor_protection_attacker_cannot_view_others_box(): void
    {
        // Атакующий пытается прочитать аптечку владельца
        $response = $this->actingAs($this->attacker)
            ->getJson('/api/medicine-boxes/' . $this->box->id);

        $response->assertStatus(403);
    }

    public function test_idor_protection_attacker_cannot_update_others_box(): void
    {
        $response = $this->actingAs($this->attacker)
            ->putJson('/api/medicine-boxes/' . $this->box->id, [
                'name' => 'Взломанная аптечка',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('medicine_boxes', [
            'id' => $this->box->id,
            'name' => 'Домашняя аптечка',
        ]);
    }

    public function test_idor_protection_attacker_cannot_delete_others_box(): void
    {
        $response = $this->actingAs($this->attacker)
            ->deleteJson('/api/medicine-boxes/' . $this->box->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('medicine_boxes', [
            'id' => $this->box->id,
        ]);
    }

    public function test_store_medicine_request_prevents_sql_injection(): void
    {
        // Отправка полезной нагрузки SQL-инъекции в названии
        $response = $this->actingAs($this->owner)
            ->postJson('/api/medicines', [
                'medicine_box_id' => $this->box->id,
                'custom_trade_name' => "Парацетамол' OR '1'='1' --",
                'barcode' => "' OR 1=1; DROP TABLE medicines; --",
                'quantity' => 10,
                'quantity_unit' => 'pill',
                'expiration_date' => now()->addYear()->toDateString(),
            ]);

        // Form Request обязан заблокировать недопустимые спецсимволы
        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_medicine_sensitive_fields_are_encrypted_in_database(): void
    {
        $medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Секретный препарат',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Конфиденциальные медицинские примечания',
        ]);

        // В модели данные автоматически расшифровываются:
        $this->assertEquals('Конфиденциальные медицинские примечания', $medicine->notes);
        $this->assertEquals('Секретный препарат', $medicine->custom_trade_name);

        // В сырой строке базы данных данные хранятся в зашифрованном виде (Data at Rest):
        $raw = DB::table('medicines')->where('id', $medicine->id)->first();
        $this->assertNotEquals('Конфиденциальные медицинские примечания', $raw->notes);
        $this->assertNotEquals('Секретный препарат', $raw->custom_trade_name);
    }
}
