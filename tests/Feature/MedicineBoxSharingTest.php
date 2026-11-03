<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineBoxSharingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $member;
    protected User $stranger;
    protected MedicineBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'email' => 'owner@family.test',
            'password' => Hash::make('password'),
            'sync_enabled' => true,
        ]);

        $this->member = User::create([
            'email' => 'member@family.test',
            'password' => Hash::make('password'),
            'sync_enabled' => true,
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@other.test',
            'password' => Hash::make('password'),
            'sync_enabled' => true,
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Семейная аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);
    }

    public function test_owner_can_list_box_members(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/medicine-boxes/{$this->box->id}/members");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'owner@family.test');
    }

    public function test_owner_can_invite_member_with_editor_role(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/medicine-boxes/{$this->box->id}/members", [
                'email' => 'member@family.test',
                'role' => 'editor',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'member@family.test')
            ->assertJsonPath('data.role', 'editor');

        $this->assertDatabaseHas('medicine_box_users', [
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->member->id,
            'role' => 'editor',
            'invited_by_user_id' => $this->owner->id,
        ]);
    }

    public function test_non_owner_cannot_invite_members(): void
    {
        $response = $this->actingAs($this->stranger)
            ->postJson("/api/medicine-boxes/{$this->box->id}/members", [
                'email' => 'member@family.test',
                'role' => 'editor',
            ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_remove_member(): void
    {
        // Сначала прикрепляем участника
        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->member->id,
            'role' => MedicineBoxUserRole::VIEWER,
            'invited_by_user_id' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/medicine-boxes/{$this->box->id}/members/{$this->member->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Пользователь успешно исключен из аптечки.');

        $this->assertDatabaseMissing('medicine_box_users', [
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_cannot_remove_box_owner(): void
    {
        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/medicine-boxes/{$this->box->id}/members/{$this->owner->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Запрещено удалять владельца аптечки.');

        $this->assertDatabaseHas('medicine_box_users', [
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->owner->id,
        ]);
    }

    /**
     * Защита от перечисления пользователей (DEF-12, OWASP / 152-ФЗ):
     * Приглашение несуществующего email возвращает унифицированный HTTP 200 OK
     * с нейтральным сообщением для предотвращения оракула перебора аккаунтов.
     */
    public function test_invite_non_existent_user_returns_200_neutral_message(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/medicine-boxes/{$this->box->id}/members", [
                'email' => 'nonexistent_user_for_oracle_check@family.test',
                'role' => 'editor',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Если пользователь с указанным адресом зарегистрирован в системе, приглашение в аптечку отправлено.');
    }

    /**
     * Защита от перебора: троттлинг запросов на добавление участников (DEF-12).
     * Лимит: 6 запросов в минуту. 7-й запрос должен возвращать HTTP 429 Too Many Requests.
     */
    public function test_invite_member_is_rate_limited(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $response = $this->actingAs($this->owner)
                ->postJson("/api/medicine-boxes/{$this->box->id}/members", [
                    'email' => "attempt_{$i}@family.test",
                    'role' => 'editor',
                ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response7 = $this->actingAs($this->owner)
            ->postJson("/api/medicine-boxes/{$this->box->id}/members", [
                'email' => 'attempt_7@family.test',
                'role' => 'editor',
            ]);

        $response7->assertStatus(429);
    }
}
