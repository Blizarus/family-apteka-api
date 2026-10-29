<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\NotificationLogType;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\NotificationLog;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CoreApiExtensionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Тест регистрации: успешная регистрация с созданием профиля, дефолтной аптечки и хешированием пароля через Argon2id.
     */
    public function test_user_can_register_with_argon2id_and_receives_defaults(): void
    {
        $payload = [
            'name' => 'Ольга Николаева',
            'email' => 'olga@family.test',
            'password' => 'securePass123!',
            'password_confirmation' => 'securePass123!',
            'terms_accepted' => true,
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'email'],
                'profile' => ['id', 'name'],
                'default_box' => ['id', 'name'],
            ])
            ->assertJsonPath('token_type', 'Bearer');

        // Проверяем пользователя в БД и алгоритм хеширования Argon2id
        $user = User::where('email', 'olga@family.test')->first();
        $this->assertNotNull($user);
        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(Hash::check('securePass123!', $user->password));

        // Проверяем автоматически созданный основной профиль
        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('Ольга Николаева', $profile->name);

        // Проверяем автоматически созданную домашнюю аптечку и роль владельца (owner)
        $box = MedicineBox::whereHas('users', fn($q) => $q->where('users.id', $user->id))->first();
        $this->assertNotNull($box);
        $this->assertEquals('Домашняя аптечка', $box->name);

        $pivot = MedicineBoxUser::where('medicine_box_id', $box->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertEquals('owner', $pivot->role->value ?? (string) $pivot->role);
    }

    /**
     * 2. Валидация регистрации: отказ при дубликате email или пароле короче 8 символов.
     */
    public function test_registration_validation_fails_on_duplicate_email_or_weak_password(): void
    {
        User::create([
            'email' => 'existing_user@family.test',
            'password' => Hash::make('password123'),
        ]);

        // Попытка зарегистрировать дубликат email
        $dupResponse = $this->postJson('/api/auth/register', [
            'name' => 'Дубликат',
            'email' => 'existing_user@family.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);
        $dupResponse->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Попытка зарегистрироваться со слабым паролем (< 8 символов)
        $weakResponse = $this->postJson('/api/auth/register', [
            'name' => 'Короткий пароль',
            'email' => 'unique_user@family.test',
            'password' => '12345',
            'password_confirmation' => '12345',
            'terms_accepted' => true,
        ]);
        $weakResponse->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 3. Перенос приёма (Snooze): сдвиг запланированного времени на 20 минут.
     */
    public function test_user_can_snooze_intake_log(): void
    {
        $user = User::create([
            'email' => 'snooze_author@family.test',
            'password' => Hash::make('password123'),
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Пациент',
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Анальгин',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $profile->id,
            'medicine_id' => $medicine->id,
            'title' => 'Курс Анальгина',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        $baseTime = Carbon::now()->addHour()->startOfMinute();
        $log = IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $medicine->id,
            'scheduled_time' => $baseTime,
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);

        $response = $this->actingAs($user)->postJson("/api/intake-logs/{$log->id}/snooze", [
            'minutes' => 20,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Приём лекарства успешно отложен на 20 мин.');

        $log->refresh();
        $this->assertEquals(
            $baseTime->copy()->addMinutes(20)->format('Y-m-d H:i'),
            $log->scheduled_time->format('Y-m-d H:i')
        );
    }

    /**
     * 4. IDOR-защита переноса приёма: посторонний пользователь получает HTTP 403 Forbidden.
     */
    public function test_snooze_rejects_stranger_user_idor(): void
    {
        $owner = User::create([
            'email' => 'legit_owner@family.test',
            'password' => Hash::make('password123'),
        ]);

        $stranger = User::create([
            'email' => 'stranger_attacker@other.test',
            'password' => Hash::make('password123'),
        ]);

        $profile = Profile::create([
            'user_id' => $owner->id,
            'name' => 'Владелец профиля',
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка владельца',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Но-шпа',
            'quantity' => 20,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $course = IntakeCourse::create([
            'profile_id' => $profile->id,
            'medicine_id' => $medicine->id,
            'title' => 'Курс Но-шпа',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        $log = IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $medicine->id,
            'scheduled_time' => Carbon::now()->addHours(2),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);

        $response = $this->actingAs($stranger)->postJson("/api/intake-logs/{$log->id}/snooze", [
            'minutes' => 20,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 5. Семейный шеринг аптечки:
     * - Владелец приглашает с ролью editor (201);
     * - Приглашенный видит лекарства в аптечке (200);
     * - Не-владелец не может приглашать участников (403);
     * - Владелец удаляет участника (200).
     */
    public function test_medicine_box_sharing_and_permission_revocation(): void
    {
        $owner = User::create([
            'email' => 'box_owner_family@test.com',
            'password' => Hash::make('password123'),
            'sync_enabled' => true,
        ]);

        $invitedEditor = User::create([
            'email' => 'invited_editor@test.com',
            'password' => Hash::make('password123'),
            'sync_enabled' => true,
        ]);

        $stranger = User::create([
            'email' => 'third_party@test.com',
            'password' => Hash::make('password123'),
            'sync_enabled' => true,
        ]);

        $box = MedicineBox::create([
            'name' => 'Общая семейная аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Парацетамол',
            'quantity' => 15,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(8)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // Шаг 1: Владелец приглашает с ролью editor (HTTP 201)
        $inviteResponse = $this->actingAs($owner)->postJson("/api/medicine-boxes/{$box->id}/members", [
            'email' => $invitedEditor->email,
            'role' => 'editor',
        ]);
        $inviteResponse->assertStatus(201)
            ->assertJsonPath('data.email', $invitedEditor->email)
            ->assertJsonPath('data.role', 'editor');

        // Шаг 2: Приглашенный редактор видит лекарства в аптечке (HTTP 200)
        $medicinesResponse = $this->actingAs($invitedEditor)->getJson("/api/medicine-boxes/{$box->id}");
        $medicinesResponse->assertStatus(200)
            ->assertJsonPath('data.id', $box->id)
            ->assertJsonPath('data.medicines.0.custom_trade_name', 'Парацетамол');

        // Шаг 3: Не-владелец (редактор) не имеет права приглашать новых участников (HTTP 403)
        $forbiddenInvite = $this->actingAs($invitedEditor)->postJson("/api/medicine-boxes/{$box->id}/members", [
            'email' => $stranger->email,
            'role' => 'viewer',
        ]);
        $forbiddenInvite->assertStatus(403);

        // Шаг 4: Владелец удаляет участника (HTTP 200)
        $removeResponse = $this->actingAs($owner)->deleteJson("/api/medicine-boxes/{$box->id}/members/{$invitedEditor->id}");
        $removeResponse->assertStatus(200)
            ->assertJsonPath('message', 'Пользователь успешно исключен из аптечки.');

        $this->assertDatabaseMissing('medicine_box_users', [
            'medicine_box_id' => $box->id,
            'user_id' => $invitedEditor->id,
        ]);
    }

    /**
     * 6. Изоляция уведомлений и защита от IDOR:
     * - Пользователь видит только свои записи в GET /api/notifications;
     * - Попытка удалить чужой алерт возвращает HTTP 403 Forbidden.
     */
    public function test_notifications_isolation_and_deletion(): void
    {
        $userA = User::create([
            'email' => 'user_a@family.test',
            'password' => Hash::make('password123'),
        ]);

        $userB = User::create([
            'email' => 'user_b@family.test',
            'password' => Hash::make('password123'),
        ]);

        $notifA = NotificationLog::create([
            'entity_type' => Medicine::class,
            'entity_id' => 10,
            'type' => NotificationLogType::EXPIRY_WARNING_30D,
            'user_id' => $userA->id,
            'sent_at' => Carbon::now(),
        ]);

        $notifB = NotificationLog::create([
            'entity_type' => Medicine::class,
            'entity_id' => 11,
            'type' => NotificationLogType::EXPIRY_CRITICAL,
            'user_id' => $userB->id,
            'sent_at' => Carbon::now(),
        ]);

        // Пользователь A видит только своё уведомление (изоляция данных)
        $listResponse = $this->actingAs($userA)->getJson('/api/notifications');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notifA->id);

        // Попытка пользователя B удалить уведомление пользователя A (IDOR -> 403)
        $idorDeleteResponse = $this->actingAs($userB)->deleteJson("/api/notifications/{$notifA->id}");
        $idorDeleteResponse->assertStatus(403);
        $this->assertDatabaseHas('notification_logs', ['id' => $notifA->id]);

        // Пользователь A успешно удаляет своё уведомление (200)
        $ownDeleteResponse = $this->actingAs($userA)->deleteJson("/api/notifications/{$notifA->id}");
        $ownDeleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Уведомление успешно удалено.');
        $this->assertDatabaseMissing('notification_logs', ['id' => $notifA->id]);
    }

    /**
     * 7. Агрегация метрик дашборда:
     * - Проверка точности счетчиков GET /api/dashboard;
     * - Гарантия, что данные посторонних пользователей не подмешиваются в сводку.
     */
    public function test_dashboard_metrics_aggregation(): void
    {
        $targetUser = User::create([
            'email' => 'target_dashboard@family.test',
            'password' => Hash::make('password123'),
        ]);

        $targetProfile = Profile::create([
            'user_id' => $targetUser->id,
            'name' => 'Целевой пользователь',
        ]);

        $targetBox = MedicineBox::create([
            'name' => 'Аптечка целевая',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $targetBox->id,
            'user_id' => $targetUser->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 1 активный, 1 истекающий, 1 просроченный препарат для целевого пользователя
        $activeMed = Medicine::create([
            'medicine_box_id' => $targetBox->id,
            'custom_trade_name' => 'Витамин C',
            'quantity' => 30,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addMonths(5)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        Medicine::create([
            'medicine_box_id' => $targetBox->id,
            'custom_trade_name' => 'Спрей для носа',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::today()->addDays(12)->toDateString(),
            'status' => MedicineStatus::EXPIRING,
        ]);

        Medicine::create([
            'medicine_box_id' => $targetBox->id,
            'custom_trade_name' => 'Старый антибиотик',
            'quantity' => 4,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->subMonths(2)->toDateString(),
            'status' => MedicineStatus::EXPIRED,
        ]);

        // Курс и 2 лога приёма на сегодня (1 принят, 1 ожидает)
        $course = IntakeCourse::create([
            'profile_id' => $targetProfile->id,
            'medicine_id' => $activeMed->id,
            'title' => 'Курс витаминизации',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $activeMed->id,
            'scheduled_time' => Carbon::today()->setTime(9, 0),
            'taken_time' => Carbon::today()->setTime(9, 10),
            'status' => IntakeLogStatus::TAKEN,
            'quantity_taken' => 1.0,
        ]);

        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $activeMed->id,
            'scheduled_time' => Carbon::today()->setTime(21, 0),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);

        // --- Чужой пользователь с отдельными данными ---
        $otherUser = User::create([
            'email' => 'alien_dashboard@family.test',
            'password' => Hash::make('password123'),
        ]);

        $otherBox = MedicineBox::create([
            'name' => 'Чужая аптечка',
            'box_type' => MedicineBoxType::CAR,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $otherBox->id,
            'user_id' => $otherUser->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Создаем 5 посторонних препаратов в чужой аптечке
        for ($i = 1; $i <= 5; $i++) {
            Medicine::create([
                'medicine_box_id' => $otherBox->id,
                'custom_trade_name' => "Чужое лекарство {$i}",
                'quantity' => 10,
                'quantity_unit' => MedicineQuantityUnit::PILLS,
                'expiration_date' => Carbon::today()->addMonths(10)->toDateString(),
                'status' => MedicineStatus::ACTIVE,
            ]);
        }

        // Запрос дашборда целевым пользователем
        $response = $this->actingAs($targetUser)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_medicines', 3)
            ->assertJsonPath('data.expiring_medicines', 1)
            ->assertJsonPath('data.expired_medicines', 1)
            ->assertJsonPath('data.today_intakes.total', 2)
            ->assertJsonPath('data.today_intakes.taken', 1)
            ->assertJsonPath('data.today_intakes.remaining', 1)
            ->assertJsonPath('data.active_courses_count', 1);
    }
}
