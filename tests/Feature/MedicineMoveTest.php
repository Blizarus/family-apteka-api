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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineMoveTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $attacker;
    protected MedicineBox $sourceBox;
    protected MedicineBox $targetBox;
    protected MedicineBox $attackerBox;
    protected Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Владелец аптечек
        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        // Исходная домашняя аптечка
        $this->sourceBox = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
            'description' => 'Основная аптечка',
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->sourceBox->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Целевая дорожная аптечка
        $this->targetBox = MedicineBox::create([
            'name' => 'Дорожная аптечка',
            'box_type' => MedicineBoxType::TRAVEL,
            'description' => 'Для поездок',
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->targetBox->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Препарат в исходной аптечке
        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->sourceBox->id,
            'custom_trade_name' => 'Ибупрофен 400 мг',
            'barcode' => '4601234567890',
            'quantity' => 20,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => now()->addMonths(6),
            'status' => MedicineStatus::ACTIVE,
            'low_stock_threshold' => 5,
        ]);

        // 2. Чужой пользователь и его аптечка для проверки IDOR
        $this->attacker = User::create([
            'email' => 'attacker@evil.test',
            'password' => Hash::make('password'),
        ]);

        $this->attackerBox = MedicineBox::create([
            'name' => 'Чужая аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);
        MedicineBoxUser::create([
            'medicine_box_id' => $this->attackerBox->id,
            'user_id' => $this->attacker->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    public function test_user_can_move_medicine_between_own_boxes(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/medicines/{$this->medicine->id}/move", [
                'target_box_id' => $this->targetBox->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.medicine_box_id', $this->targetBox->id)
            ->assertJsonFragment([
                'message' => 'Препарат успешно перемещён из аптечки «Домашняя аптечка» в «Дорожная аптечка».',
            ]);

        $this->assertEquals($this->targetBox->id, $this->medicine->fresh()->medicine_box_id);
    }

    public function test_cannot_move_medicine_to_non_existent_box(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/medicines/{$this->medicine->id}/move", [
                'target_box_id' => 9999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_box_id']);
    }

    public function test_cannot_move_medicine_from_another_users_box_idor(): void
    {
        // Злоумышленник пытается переместить препарат Анны
        $response = $this->actingAs($this->attacker)
            ->postJson("/api/medicines/{$this->medicine->id}/move", [
                'target_box_id' => $this->attackerBox->id,
            ]);

        $response->assertStatus(403);
        $this->assertEquals($this->sourceBox->id, $this->medicine->fresh()->medicine_box_id);
    }

    public function test_cannot_move_medicine_into_another_users_box_idor(): void
    {
        // Анна пытается переместить свой препарат в чужую аптечку, куда у неё нет доступа
        $response = $this->actingAs($this->user)
            ->postJson("/api/medicines/{$this->medicine->id}/move", [
                'target_box_id' => $this->attackerBox->id,
            ]);

        $response->assertStatus(403);
        $this->assertEquals($this->sourceBox->id, $this->medicine->fresh()->medicine_box_id);
    }

    public function test_unauthenticated_user_cannot_move_medicine(): void
    {
        $response = $this->postJson("/api/medicines/{$this->medicine->id}/move", [
            'target_box_id' => $this->targetBox->id,
        ]);

        $response->assertStatus(401);
    }
}
