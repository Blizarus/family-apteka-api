<?php

namespace Tests\Feature;

use App\NotificationLogType;
use App\Models\Medicine;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $stranger;
    protected NotificationLog $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->stranger = User::create([
            'email' => 'stranger@other.test',
            'password' => Hash::make('password'),
        ]);

        $this->notification = NotificationLog::create([
            'entity_type' => Medicine::class,
            'entity_id' => 1,
            'type' => NotificationLogType::EXPIRY_CRITICAL,
            'user_id' => $this->user->id,
            'sent_at' => Carbon::now(),
        ]);
    }

    public function test_user_can_list_own_notifications(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->notification->id);
    }

    public function test_user_can_delete_own_notification(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/notifications/{$this->notification->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Уведомление успешно удалено.');

        $this->assertDatabaseMissing('notification_logs', [
            'id' => $this->notification->id,
        ]);
    }

    public function test_idor_protection_stranger_cannot_delete_notification(): void
    {
        $response = $this->actingAs($this->stranger)
            ->deleteJson("/api/notifications/{$this->notification->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('notification_logs', [
            'id' => $this->notification->id,
        ]);
    }
}
