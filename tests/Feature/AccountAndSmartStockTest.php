<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\NotificationLogType;
use App\StorageCondition;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\NotificationLog;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountAndSmartStockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. 152-ФЗ: Регистрация требует обязательного принятия условий и оферты (terms_accepted).
     */
    public function test_registration_requires_terms_accepted_for_152_fz(): void
    {
        $payload = [
            'name' => 'Алексей Смирнов',
            'email' => 'alex@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        // Без terms_accepted
        $response = $this->postJson('/api/auth/register', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);

        // С terms_accepted = false
        $payload['terms_accepted'] = false;
        $response = $this->postJson('/api/auth/register', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);
    }

    /**
     * 1. 152-ФЗ: Регистрация фиксирует время согласия, версию политики и флаг облачной синхронизации.
     */
    public function test_registration_persists_consent_and_sync_preferences(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Екатерина Белова',
            'email' => 'ekaterina@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'sync_enabled' => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'ekaterina@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->sync_enabled);
        $this->assertNotNull($user->consent_given_at);
        $this->assertEquals('1.0', $user->consent_version);

        // Регистрация с дефолтным sync_enabled (false)
        $response2 = $this->postJson('/api/auth/register', [
            'name' => 'Дмитрий Волков',
            'email' => 'dmitry@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $response2->assertStatus(201);
        $user2 = User::where('email', 'dmitry@example.test')->first();
        $this->assertFalse($user2->sync_enabled);
        $this->assertNotNull($user2->consent_given_at);
    }

    /**
     * 1. 152-ФЗ: Семейный шеринг аптечки блокируется с HTTP 403, если sync_enabled отключен.
     */
    public function test_box_member_invitation_blocked_when_sync_disabled(): void
    {
        $owner = User::create([
            'email' => 'owner_nosync@family.test',
            'password' => Hash::make('password123'),
            'sync_enabled' => false, // Облачная синхронизация выключена
        ]);

        $invitee = User::create([
            'email' => 'invitee@family.test',
            'password' => Hash::make('password123'),
            'sync_enabled' => true,
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка без синхронизации',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $owner->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Попытка пригласить участника, когда у владельца sync_enabled = false
        $response = $this->actingAs($owner)->postJson("/api/medicine-boxes/{$box->id}/members", [
            'email' => $invitee->email,
            'role' => 'editor',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Для использования семейного доступа необходимо включить облачную синхронизацию в настройках профиля.',
            ]);

        // Включаем владельцу, но отключаем приглашаемому
        $owner->update(['sync_enabled' => true]);
        $invitee->update(['sync_enabled' => false]);

        $response2 = $this->actingAs($owner)->postJson("/api/medicine-boxes/{$box->id}/members", [
            'email' => $invitee->email,
            'role' => 'editor',
        ]);

        $response2->assertStatus(403)
            ->assertJson([
                'message' => 'Для использования семейного доступа необходимо включить облачную синхронизацию в настройках профиля.',
            ]);

        // Когда у обоих sync_enabled = true — приглашение успешно
        $invitee->update(['sync_enabled' => true]);

        $response3 = $this->actingAs($owner)->postJson("/api/medicine-boxes/{$box->id}/members", [
            'email' => $invitee->email,
            'role' => 'editor',
        ]);

        $response3->assertStatus(201);
    }

    /**
     * 2. Смена пароля PUT /api/auth/password с проверкой текущего пароля (Argon2id).
     */
    public function test_user_can_update_password_with_argon2id(): void
    {
        $user = User::create([
            'email' => 'change_pass@family.test',
            'password' => Hash::make('old_secret_123'),
        ]);

        Sanctum::actingAs($user);

        // Ошибка при неверном текущем пароле
        $failResponse = $this->putJson('/api/auth/password', [
            'current_password' => 'wrong_password',
            'password' => 'new_secret_456',
            'password_confirmation' => 'new_secret_456',
        ]);

        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // Успешная смена пароля
        $successResponse = $this->putJson('/api/auth/password', [
            'current_password' => 'old_secret_123',
            'password' => 'new_secret_456',
            'password_confirmation' => 'new_secret_456',
        ]);

        $successResponse->assertStatus(200)
            ->assertJson(['message' => 'Пароль успешно изменен.']);

        $user->refresh();
        $this->assertTrue(Hash::check('new_secret_456', $user->password));
        $this->assertFalse(Hash::check('old_secret_123', $user->password));

        // Проверяем возможность успешной аутентификации через POST /api/auth/login с новым паролем
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'change_pass@family.test',
            'password' => 'new_secret_456',
        ]);
        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['token', 'token_type']);
    }

    /**
     * 2. Удаление учетной записи DELETE /api/auth/account (отзыв токенов и softDelete профилей/аптечек).
     */
    public function test_user_can_delete_account_and_soft_delete_data(): void
    {
        $user = User::create([
            'email' => 'delete_me@family.test',
            'password' => Hash::make('delete_pass_123'),
        ]);

        // Создаем токен
        $token = $user->createToken('test_token');

        // Создаем 2 профиля
        $profile1 = Profile::create(['user_id' => $user->id, 'name' => 'Профиль 1']);
        $profile2 = Profile::create(['user_id' => $user->id, 'name' => 'Профиль 2']);

        // Создаем аптечку, где он owner
        $ownerBox = MedicineBox::create(['name' => 'Личная аптечка', 'box_type' => MedicineBoxType::HOME]);
        MedicineBoxUser::create([
            'medicine_box_id' => $ownerBox->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Чужая аптечка, где он viewer
        $strangerBox = MedicineBox::create(['name' => 'Чужая аптечка', 'box_type' => MedicineBoxType::HOME]);
        MedicineBoxUser::create([
            'medicine_box_id' => $strangerBox->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::VIEWER,
        ]);

        Sanctum::actingAs($user);

        // Ошибка при неверном пароле подтверждения
        $failResponse = $this->deleteJson('/api/auth/account', [
            'current_password' => 'wrong_pass',
        ]);
        $failResponse->assertStatus(422);

        // Успешное удаление
        $successResponse = $this->deleteJson('/api/auth/account', [
            'current_password' => 'delete_pass_123',
        ]);

        $successResponse->assertStatus(200)
            ->assertJson(['message' => 'Аккаунт и все связанные персональные данные успешно удалены.']);

        // Проверяем:
        // 1) Токены отозваны
        $this->assertEquals(0, $user->tokens()->count());

        // 2) Пользователь помечен как удаленный (SoftDelete)
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // 3) Профили пользователя мягко удалены
        $this->assertSoftDeleted('profiles', ['id' => $profile1->id]);
        $this->assertSoftDeleted('profiles', ['id' => $profile2->id]);

        // 4) Аптечка, где пользователь owner, мягко удалена
        $this->assertSoftDeleted('medicine_boxes', ['id' => $ownerBox->id]);

        // 5) Чужая аптечка не удалена
        $this->assertDatabaseHas('medicine_boxes', ['id' => $strangerBox->id, 'deleted_at' => null]);
    }

    /**
     * 3. FEFO-Opened: консольный воркер помечает препарат просроченным, если opened_at + days_after_opening истек.
     */
    public function test_fefo_opened_marks_medicine_expired_when_period_exceeded(): void
    {
        $user = User::create([
            'email' => 'fefo_owner@family.test',
            'password' => Hash::make('password123'),
        ]);

        $box = MedicineBox::create(['name' => 'Аптечка FEFO', 'box_type' => MedicineBoxType::HOME]);
        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Препарат: срок годности на упаковке до 2028 года, но вскрыт 20 дней назад, а годен после вскрытия 10 дней
        $med = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Глазные капли Сульфацил',
            'quantity' => 1.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::today()->addYears(2)->toDateString(),
            'opened_at' => Carbon::today()->subDays(20)->toDateString(),
            'days_after_opening' => 10,
            'storage_condition' => StorageCondition::FRIDGE,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        // Статус должен стать EXPIRED
        $med->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $med->status);

        // В notification_logs создана запись opened_expired
        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $med->id,
            'type' => NotificationLogType::OPENED_EXPIRED,
            'user_id' => $user->id,
        ]);
    }

    /**
     * 3. Low Stock: воркер формирует уведомление low_stock и предотвращает дублирование за 7 дней.
     */
    public function test_low_stock_creates_notification_and_prevents_duplicate(): void
    {
        $user = User::create([
            'email' => 'stock_owner@family.test',
            'password' => Hash::make('password123'),
        ]);

        $box = MedicineBox::create(['name' => 'Аптечка остатков', 'box_type' => MedicineBoxType::HOME]);
        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // Препарат: осталось 1.5 таб. при пороге low_stock_threshold = 2.0
        $med = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Нурофен табл',
            'quantity' => 1.5,
            'low_stock_threshold' => 2.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 1-й запуск воркера
        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        // Уведомление создано
        $this->assertDatabaseHas('notification_logs', [
            'entity_type' => Medicine::class,
            'entity_id' => $med->id,
            'type' => NotificationLogType::LOW_STOCK,
            'user_id' => $user->id,
        ]);

        $countBefore = NotificationLog::where('entity_id', $med->id)
            ->where('type', NotificationLogType::LOW_STOCK)
            ->count();
        $this->assertEquals(1, $countBefore);

        // 2-й запуск воркера: дублирование должно быть предотвращено
        $this->artisan('medicines:check-expiry')->assertExitCode(0);

        $countAfter = NotificationLog::where('entity_id', $med->id)
            ->where('type', NotificationLogType::LOW_STOCK)
            ->count();
        $this->assertEquals(1, $countAfter);
    }

    /**
     * 3. Условия хранения: поле storage_condition ('room' vs 'fridge') корректно сохраняется и выдается в API GET /api/medicines.
     */
    public function test_medicine_storage_condition_persistence_and_api_output(): void
    {
        $user = User::create([
            'email' => 'storage_user@family.test',
            'password' => Hash::make('password123'),
        ]);

        $box = MedicineBox::create(['name' => 'Аптечка хранения', 'box_type' => MedicineBoxType::HOME]);
        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        Sanctum::actingAs($user);

        // 1. Создаем препарат с условием хранения 'fridge' (холодильник) через POST /api/medicines
        $createResponse = $this->postJson('/api/medicines', [
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Инсулин Протафан',
            'quantity' => 5.0,
            'quantity_unit' => 'unit',
            'expiration_date' => Carbon::today()->addMonths(6)->toDateString(),
            'storage_condition' => 'fridge',
            'days_after_opening' => 28,
            'low_stock_threshold' => 1.0,
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.storage_condition', 'fridge')
            ->assertJsonPath('data.days_after_opening', 28);

        $medId = $createResponse->json('data.id');

        // 2. Создаем препарат с условием хранения 'room' (комнатная температура)
        $createResponse2 = $this->postJson('/api/medicines', [
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Парацетамол табл',
            'quantity' => 20.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::today()->addYears(2)->toDateString(),
            'storage_condition' => 'room',
        ]);

        $createResponse2->assertStatus(201)
            ->assertJsonPath('data.storage_condition', 'room');

        // 3. Проверяем выдачу списка через GET /api/medicines
        $listResponse = $this->getJson('/api/medicines');
        $listResponse->assertStatus(200);

        $items = collect($listResponse->json('data'));
        $fridgeMed = $items->firstWhere('id', $medId);
        $this->assertNotNull($fridgeMed);
        $this->assertEquals('fridge', $fridgeMed['storage_condition']);
        $this->assertEquals(28, $fridgeMed['days_after_opening']);

        // 4. Проверяем детальный просмотр GET /api/medicines/{id}
        $showResponse = $this->getJson("/api/medicines/{$medId}");
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.storage_condition', 'fridge')
            ->assertJsonPath('data.days_after_opening', 28)
            ->assertJsonPath('data.low_stock_threshold', '1.000');
    }
}
