<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'patient@family.test',
            'password' => Hash::make('password123'),
            'terms_accepted' => true,
            'sync_enabled' => true,
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    /**
     * DEF-15: Ошибка 422, если не переданы ни reference_medicine_id, ни custom_trade_name.
     */
    public function test_store_medicine_fails_when_both_reference_id_and_custom_name_are_missing(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/medicines', [
            'medicine_box_id' => $this->box->id,
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['custom_trade_name', 'reference_medicine_id'])
            ->assertJsonFragment([
                'custom_trade_name' => ['Укажите наименование препарата или выберите его из справочника.'],
            ]);
    }

    /**
     * DEF-15: Ошибка 422, если custom_trade_name пустой или состоит из пробелов при reference_medicine_id = null.
     */
    public function test_store_medicine_fails_when_custom_name_is_empty_string_and_reference_id_null(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/medicines', [
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => '   ',
            'reference_medicine_id' => null,
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['custom_trade_name']);
    }

    /**
     * DEF-15: Успешное создание только с пользовательским наименованием (холодный старт без справочника).
     */
    public function test_store_medicine_succeeds_with_only_custom_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/medicines', [
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Парацетамол Экстра',
            'reference_medicine_id' => null,
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('medicines', [
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => null,
        ]);

        $medicine = Medicine::where('medicine_box_id', $this->box->id)->first();
        $this->assertNotNull($medicine);
        $this->assertSame('Парацетамол Экстра', $medicine->custom_trade_name);
    }

    /**
     * DEF-15: Успешное создание только со ссылкой на справочник ГРЛС.
     */
    public function test_store_medicine_succeeds_with_only_reference_medicine_id(): void
    {
        $ref = ReferenceMedicine::create([
            'trade_name' => 'Ибупрофен 400мг',
            'inn' => 'Ибупрофен',
            'dosage_form' => 'таблетки',
            'dosage' => '400 мг',
            'pack_size' => 1,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'atc_code' => 'M01AE01',
            'barcode' => '4601234567890',
            'is_prescription' => false,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/medicines', [
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $ref->id,
            'custom_trade_name' => null,
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('medicines', [
            'medicine_box_id' => $this->box->id,
            'reference_medicine_id' => $ref->id,
        ]);
    }
}
