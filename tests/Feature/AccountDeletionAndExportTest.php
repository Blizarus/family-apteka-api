<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AccountDeletionAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;
    protected IntakeCourse $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'dsar_tester@family.test',
            'password' => Hash::make('password123'),
            'fcm_token' => 'fcm_token_super_secret_xyz',
            'sync_enabled' => true,
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна DSAR',
            'allergies' => ['aspirin'],
            'chronic_conditions' => ['migraine'],
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка Анны',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Цитрамон',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addYear()->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Цитрамона',
            'dosage_instruction' => 'При боли',
            'dosage_quantity' => 1.0,
            'start_date' => Carbon::today(),
            'is_active' => true,
        ]);

        IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::yesterday()->setTime(10, 0),
            'taken_time' => Carbon::yesterday()->setTime(10, 5),
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::TAKEN,
        ]);

        IntakeLog::create([
            'intake_course_id' => $this->course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(10, 0),
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        NotificationLog::create([
            'user_id' => $this->user->id,
            'entity_type' => 'intake_log',
            'entity_id' => 1,
            'type' => \App\NotificationLogType::INTAKE_REMINDER,
            'channel' => 'push',
            'title' => 'Напоминание о приёме',
            'message' => 'Пора принять Цитрамон',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * 1. Выгрузка данных DSAR (ст. 14 152-ФЗ) включает полные записи журнала приёмов
     * со временем и статусом, а не только агрегированное число.
     */
    public function test_dsar_export_includes_full_intake_log_records(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/auth/export');

        $response->assertStatus(200);
        $data = $response->json();

        $courseExport = $data['profiles'][0]['intake_courses'][0];

        // Проверяем наличие детального массива intake_logs
        $this->assertArrayHasKey('intake_logs', $courseExport, 'Выгрузка DSAR должна содержать детальный массив intake_logs, а не только агрегаты!');
        $this->assertCount(2, $courseExport['intake_logs']);

        $firstLog = $courseExport['intake_logs'][0];
        $this->assertArrayHasKey('scheduled_time', $firstLog);
        $this->assertArrayHasKey('taken_time', $firstLog);
        $this->assertArrayHasKey('status', $firstLog);
        $this->assertArrayHasKey('quantity_taken', $firstLog);
        $this->assertEquals(IntakeLogStatus::TAKEN->value, $firstLog['status']);
    }

    /**
     * 2. Удаление аккаунта (ст. 21 152-ФЗ) очищает fcm_token, notification_logs и intake_logs.
     */
    public function test_account_deletion_cleans_up_fcm_token_notifications_and_intake_logs(): void
    {
        $this->assertNotNull(DB::table('users')->where('id', $this->user->id)->value('fcm_token'));
        $this->assertGreaterThan(0, DB::table('notification_logs')->where('user_id', $this->user->id)->count());
        $this->assertGreaterThan(0, DB::table('intake_logs')->where('intake_course_id', $this->course->id)->count());

        $response = $this->actingAs($this->user)->deleteJson('/api/auth/account', [
            'current_password' => 'password123',
        ]);

        $response->assertStatus(200);

        // 1) Пользователь помечен как удаленный (soft deleted)
        $this->assertSoftDeleted('users', ['id' => $this->user->id]);

        // 2) fcm_token стерт из БД для предотвращения отправки пушей на удаленный аккаунт
        $rawFcmToken = DB::table('users')->where('id', $this->user->id)->value('fcm_token');
        $this->assertNull($rawFcmToken, 'fcm_token должен быть очищен при удалении учетной записи!');

        // 3) Логи уведомлений пользователя полностью удалены
        $this->assertEquals(0, DB::table('notification_logs')->where('user_id', $this->user->id)->count(), 'Логи уведомлений должны быть удалены при уничтожении ПДн!');

        // 4) Журнал приёмов удален в рамках права на забвение 152-ФЗ
        $this->assertEquals(0, DB::table('intake_logs')->where('intake_course_id', $this->course->id)->count(), 'Медицинские логи приёма должны быть удалены!');
    }

    /**
     * 3. AdminController не пишет email пользователя в системный лог.
     */
    public function test_admin_controller_does_not_log_user_email_on_block(): void
    {
        $admin = User::create([
            'email' => 'admin_privacy@family.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
        ]);

        $targetUser = User::create([
            'email' => 'confidential_patient@secret.test',
            'password' => Hash::make('password123'),
        ]);

        $loggedMessages = [];
        Log::listen(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = is_string($message) ? $message : ($message->message ?? '');
        });

        // Блокируем пользователя
        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$targetUser->id}/block", ['block' => true])
            ->assertStatus(200);

        $allLogs = implode("\n", $loggedMessages);

        // В логах НЕ должно быть email пользователя!
        $this->assertStringNotContainsString('confidential_patient@secret.test', $allLogs, 'Системный лог администратора не должен содержать email пользователя!');
    }
}
