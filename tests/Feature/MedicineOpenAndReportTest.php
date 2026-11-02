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
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineOpenAndReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $attacker;
    protected Profile $profile;
    protected MedicineBox $box;
    protected Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Тестовый пользователь и его профиль
        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
        ]);

        // 2. Чужой пользователь для проверки IDOR
        $this->attacker = User::create([
            'email' => 'attacker@evil.test',
            'password' => Hash::make('password'),
        ]);

        // 3. Семейная аптечка
        $this->box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 4. Тестовый препарат (капли глазные)
        $this->medicine = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Офтальмологические капли',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::today()->addMonths(6)->toDateString(),
            'days_after_opening' => 28,
            'status' => MedicineStatus::ACTIVE,
        ]);
    }

    /**
     * 1. Успешное вскрытие флакона:
     * - Проверка фиксации opened_at;
     * - Корректность вычисления effective_expiration_date (opened_at + days_after_opening);
     * - Обновление days_after_opening из тела запроса.
     */
    public function test_successful_bottle_opening_and_effective_expiration_calculation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/medicines/{$this->medicine->id}/open", [
                'days_after_opening' => 30,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Флакон успешно вскрыт.')
            ->assertJsonPath('data.days_after_opening', 30);

        $this->medicine->refresh();

        $this->assertNotNull($this->medicine->opened_at);
        $this->assertEquals(Carbon::today()->toDateString(), $this->medicine->opened_at->toDateString());
        $this->assertEquals(30, $this->medicine->days_after_opening);

        // Эффективный срок годности равен today + 30 дней
        $expectedEffective = Carbon::today()->addDays(30)->toDateString();
        $this->assertNotNull($this->medicine->effective_expiration_date);
        $this->assertEquals($expectedEffective, $this->medicine->effective_expiration_date->toDateString());

        // В JSON-ответе также присутствует effective_expiration_date
        $resDate = Carbon::parse($response->json('data.effective_expiration_date'))->toDateString();
        $this->assertEquals($expectedEffective, $resDate);
    }

    /**
     * 2. Выбор минимального срока годности (FEFO-Opened):
     * Если заводской срок годности наступает раньше, чем срок после вскрытия,
     * effective_expiration_date должен быть равен заводскому сроку.
     */
    public function test_effective_expiration_picks_minimum_between_factory_and_opened(): void
    {
        $shortFactoryMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Сироп с коротким сроком',
            'quantity' => 100.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::today()->addDays(5)->toDateString(),
            'days_after_opening' => 30,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/medicines/{$shortFactoryMed->id}/open")
            ->assertStatus(200);

        $shortFactoryMed->refresh();

        // Заводской срок (5 дней) меньше срока после вскрытия (30 дней)
        $this->assertEquals(
            Carbon::today()->addDays(5)->toDateString(),
            $shortFactoryMed->effective_expiration_date->toDateString()
        );
    }

    /**
     * 3. Вскрытие просроченного препарата автоматически переводит его в статус EXPIRED.
     */
    public function test_opening_expired_medicine_sets_expired_status(): void
    {
        $expiredMed = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Старый раствор',
            'quantity' => 50.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::today()->subDays(3)->toDateString(),
            'days_after_opening' => 30,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/medicines/{$expiredMed->id}/open")
            ->assertStatus(200);

        $expiredMed->refresh();
        $this->assertEquals(MedicineStatus::EXPIRED, $expiredMed->status);
    }

    /**
     * 4. Защита от вскрытия чужого флакона (IDOR, 403 Forbidden).
     */
    public function test_idor_protection_prevents_opening_foreign_medicine(): void
    {
        $response = $this->actingAs($this->attacker)
            ->postJson("/api/medicines/{$this->medicine->id}/open", [
                'days_after_opening' => 15,
            ]);

        $response->assertStatus(403);

        $this->medicine->refresh();
        $this->assertNull($this->medicine->opened_at);
    }

    /**
     * 5. Успешное получение отчёта комплаенса за 7 и 30 дней.
     */
    public function test_adherence_report_generation_for_different_periods(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс капель',
            'dosage_instruction' => 'По 1 капле в каждый глаз',
            'start_date' => Carbon::today()->subDays(15),
            'end_date' => Carbon::today()->addDays(15),
            'dosage_quantity' => 1.0,
            'is_active' => true,
        ]);

        // Создаем логи за последние 7 дней (3 принято, 1 пропущен)
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->subDays(3)->setTime(9, 0),
            'status' => IntakeLogStatus::TAKEN,
        ]);
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->subDays(2)->setTime(9, 0),
            'status' => IntakeLogStatus::TAKEN,
        ]);
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->subDays(1)->setTime(9, 0),
            'status' => IntakeLogStatus::TAKEN,
        ]);
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(9, 0),
            'status' => IntakeLogStatus::MISSED,
        ]);

        // 1. Отчёт за 7 дней (по умолчанию)
        $response7 = $this->actingAs($this->user)
            ->getJson('/api/reports/adherence?period=7_days');

        $response7->assertStatus(200)
            ->assertJsonPath('period.type', '7_days')
            ->assertJsonPath('scheduled_count', 4)
            ->assertJsonPath('taken_count', 3)
            ->assertJsonPath('missed_count', 1)
            ->assertJsonCount(7, 'daily_stats')
            ->assertJsonCount(1, 'medicines_summary');

        $this->assertEquals(75.0, (float) $response7->json('adherence_rate'));

        // 2. Отчёт за 30 дней
        $response30 = $this->actingAs($this->user)
            ->getJson('/api/reports/adherence?period=30_days');

        $response30->assertStatus(200)
            ->assertJsonPath('period.type', '30_days')
            ->assertJsonPath('scheduled_count', 4)
            ->assertJsonCount(30, 'daily_stats');
    }

    /**
     * 6. Корректность подсчёта adherence_rate и streak_days.
     */
    public function test_adherence_rate_and_streak_days_calculation(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Ежедневный курс',
            'dosage_instruction' => 'По 1 таблетке 1 раз в день',
            'start_date' => Carbon::today()->subDays(10),
            'end_date' => null,
            'dosage_quantity' => 1.0,
            'is_active' => true,
        ]);

        // Создаем серию приёмов:
        // 4 дня назад: пропущен
        // 3 дня назад: принят
        // 2 дня назад: принят
        // 1 день назад: принят
        // сегодня: принят
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->subDays(4)->setTime(8, 0),
            'status' => IntakeLogStatus::MISSED,
        ]);

        for ($i = 3; $i >= 0; $i--) {
            IntakeLog::create([
                'intake_course_id' => $course->id,
                'medicine_id' => $this->medicine->id,
                'scheduled_time' => Carbon::today()->subDays($i)->setTime(8, 0),
                'status' => IntakeLogStatus::TAKEN,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/adherence?period=7_days');

        $response->assertStatus(200);

        // Всего запланировано: 5, принято: 4, пропущено: 1
        // adherence_rate = 4 / 5 * 100 = 80.0%
        $this->assertEquals(5, $response->json('scheduled_count'));
        $this->assertEquals(4, $response->json('taken_count'));
        $this->assertEquals(1, $response->json('missed_count'));
        $this->assertEquals(80.0, $response->json('adherence_rate'));

        // streak_days: сегодня (1), 1 дн назад (2), 2 дн назад (3), 3 дн назад (4). 4 дн назад был пропуск -> серия = 4 дня.
        $this->assertEquals(4, $response->json('streak_days'));
    }

    /**
     * 6.1. Тест строгого динамического вычисления процента приверженности из реальных записей intake_logs:
     * Значение вычисляется по формуле (taken / scheduled) * 100 и динамически меняется
     * при модификации записей в БД, подтверждая отсутствие статической заглушки.
     */
    public function test_adherence_rate_is_dynamically_calculated_from_actual_intake_logs_not_hardcoded(): void
    {
        $course = IntakeCourse::create([
            'profile_id' => $this->profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Динамический курс комплаенса',
            'dosage_instruction' => 'По 1 шт.',
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => null,
            'dosage_quantity' => 1.0,
            'is_active' => true,
        ]);

        // Инициализируем 10 записей приёма: 7 выполнено (TAKEN), 3 пропущено (MISSED)
        for ($i = 1; $i <= 7; $i++) {
            IntakeLog::create([
                'intake_course_id' => $course->id,
                'medicine_id' => $this->medicine->id,
                'scheduled_time' => Carbon::today()->subDays(2)->setTime($i, 0),
                'status' => IntakeLogStatus::TAKEN,
            ]);
        }

        $missedLogs = [];
        for ($j = 1; $j <= 3; $j++) {
            $missedLogs[] = IntakeLog::create([
                'intake_course_id' => $course->id,
                'medicine_id' => $this->medicine->id,
                'scheduled_time' => Carbon::today()->subDays(1)->setTime($j + 10, 0),
                'status' => IntakeLogStatus::MISSED,
            ]);
        }

        // Этап 1: 7 / 10 = 70.0%
        $res1 = $this->actingAs($this->user)->getJson('/api/reports/adherence?period=7_days');
        $res1->assertStatus(200)
            ->assertJsonPath('scheduled_count', 10)
            ->assertJsonPath('taken_count', 7)
            ->assertJsonPath('missed_count', 3);
        $this->assertEquals(70.0, (float) $res1->json('adherence_rate'), '7 из 10 должно давать ровно 70.0%');

        // Этап 2: Меняем 1 пропущенный приём на принятый (TAKEN): 8 / 10 = 80.0%
        $missedLogs[0]->update(['status' => IntakeLogStatus::TAKEN]);
        $res2 = $this->actingAs($this->user)->getJson('/api/reports/adherence?period=7_days');
        $res2->assertStatus(200)
            ->assertJsonPath('scheduled_count', 10)
            ->assertJsonPath('taken_count', 8)
            ->assertJsonPath('missed_count', 2);
        $this->assertEquals(80.0, (float) $res2->json('adherence_rate'), 'После отметки приёма процент обязан пересчитаться на 80.0%');

        // Этап 3: Добавляем новый запланированный приём со статусом SKIPPED: 8 / 11 = 72.7%
        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $this->medicine->id,
            'scheduled_time' => Carbon::today()->setTime(12, 0),
            'status' => IntakeLogStatus::SKIPPED,
        ]);
        $res3 = $this->actingAs($this->user)->getJson('/api/reports/adherence?period=7_days');
        $res3->assertStatus(200)
            ->assertJsonPath('scheduled_count', 11)
            ->assertJsonPath('taken_count', 8)
            ->assertJsonPath('missed_count', 3);
        $this->assertEquals(72.7, (float) $res3->json('adherence_rate'), '8 из 11 должно давать динамически рассчитанные 72.7%');
    }

    /**
     * 7. Защита от IDOR при запросе отчёта по чужому профилю.
     */
    public function test_idor_protection_on_adherence_report(): void
    {
        $stranger = User::create([
            'email' => 'other@family.test',
            'password' => Hash::make('password'),
        ]);

        $strangerProfile = Profile::create([
            'user_id' => $stranger->id,
            'name' => 'Чужой профиль',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/reports/adherence?profile_id={$strangerProfile->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Доступ к указанному профилю запрещен (IDOR).');
    }
}
