<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'push_tester@family.test',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * Регистрация FCM-токена авторизованным пользователем (200 OK, проверяет БД).
     */
    public function test_authenticated_user_can_register_fcm_token(): void
    {
        $token = 'fcm_device_token_sample_abc123';

        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/fcm-token', [
                'fcm_token' => $token,
                'platform' => 'android',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'FCM token registered successfully',
                'fcm_token' => $token,
                'platform' => 'android',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'fcm_token' => $token,
            'device_platform' => 'android',
        ]);
    }

    /**
     * Регистрация FCM-токена по умолчанию использует flutter при отсутствии platform.
     */
    public function test_register_fcm_token_defaults_platform_to_flutter(): void
    {
        $token = 'fcm_device_token_sample_flutter_456';

        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/fcm-token', [
                'fcm_token' => $token,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'FCM token registered successfully',
                'fcm_token' => $token,
                'platform' => 'flutter',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'fcm_token' => $token,
            'device_platform' => 'flutter',
        ]);
    }

    /**
     * Блокировка 401 для неавторизованного запроса к fcm-token.
     */
    public function test_unauthenticated_request_to_fcm_token_returns_401(): void
    {
        $response = $this->postJson('/api/notifications/fcm-token', [
            'fcm_token' => 'fcm_token_unauth',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Валидация отсутствия fcm_token (422 Unprocessable).
     */
    public function test_register_fcm_token_validates_required_token(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/fcm-token', [
                'platform' => 'android',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fcm_token']);
    }

    /**
     * Валидация недопустимого значения platform (422 Unprocessable).
     */
    public function test_register_fcm_token_validates_platform_in_list(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/fcm-token', [
                'fcm_token' => 'sample_token',
                'platform' => 'unsupported_os',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    /**
     * Отправка тестового пуша через /api/notifications/test-push (200 OK, запись в notification_logs).
     */
    public function test_authenticated_user_can_dispatch_test_push_notification(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/test-push', [
                'title' => 'Проверка уведомлений',
                'body' => 'Напоминание о приёме лекарства в 14:00',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Test push notification queued/sent',
                'dispatched' => true,
            ]);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->user->id,
            'type' => 'push_test',
            'title' => 'Проверка уведомлений',
            'message' => 'Напоминание о приёме лекарства в 14:00',
            'status' => 'sent',
        ]);
    }

    /**
     * Отправка тестового пуша с дефолтными значениями без передачи title/body.
     */
    public function test_test_push_with_default_payload(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/test-push', []);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Test push notification queued/sent',
                'dispatched' => true,
            ]);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->user->id,
            'type' => 'push_test',
            'status' => 'sent',
        ]);
    }

    /**
     * Блокировка 401 для неавторизованного запроса к test-push.
     */
    public function test_unauthenticated_request_to_test_push_returns_401(): void
    {
        $response = $this->postJson('/api/notifications/test-push', [
            'title' => 'Тест',
        ]);

        $response->assertStatus(401);
    }
}
