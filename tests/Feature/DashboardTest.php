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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make('password'),
        ]);

        $profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Анна',
        ]);

        $box = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 1. Нормальный препарат
        $medActive = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Ибупрофен',
            'quantity' => 10,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->addMonths(6)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Истекающий препарат (через 10 дней)
        Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Називин',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::today()->addDays(10)->toDateString(),
            'status' => MedicineStatus::EXPIRING,
        ]);

        // 3. Просроченный препарат
        Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Аспирин',
            'quantity' => 5,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::today()->subMonth()->toDateString(),
            'status' => MedicineStatus::EXPIRED,
        ]);

        // Курс и 2 лога приёма на сегодня (1 принят, 1 ожидает)
        $course = IntakeCourse::create([
            'profile_id' => $profile->id,
            'medicine_id' => $medActive->id,
            'title' => 'Курс лечения',
            'start_date' => Carbon::today()->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб.',
            'is_active' => true,
        ]);

        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $medActive->id,
            'scheduled_time' => Carbon::today()->setTime(8, 0),
            'taken_time' => Carbon::today()->setTime(8, 5),
            'status' => IntakeLogStatus::TAKEN,
            'quantity_taken' => 1.0,
        ]);

        IntakeLog::create([
            'intake_course_id' => $course->id,
            'medicine_id' => $medActive->id,
            'scheduled_time' => Carbon::today()->setTime(20, 0),
            'status' => IntakeLogStatus::SCHEDULED,
            'quantity_taken' => 1.0,
        ]);
    }

    public function test_dashboard_returns_correct_metrics(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_medicines', 3)
            ->assertJsonPath('data.expiring_medicines', 1)
            ->assertJsonPath('data.expired_medicines', 1)
            ->assertJsonPath('data.today_intakes.total', 2)
            ->assertJsonPath('data.today_intakes.taken', 1)
            ->assertJsonPath('data.today_intakes.remaining', 1)
            ->assertJsonPath('data.active_courses_count', 1);
    }

    /**
     * Клиническая безопасность FEFO-Opened (DEF-11):
     * Вскрытые препараты с истекшим сроком после вскрытия (opened_at + days_after_opening < today)
     * обязаны учитываться в expired_medicines, даже если заводской expiration_date еще в будущем.
     * Вскрытые препараты, истекающие в пределах 30 дней, обязаны попадать в expiring_medicines.
     */
    public function test_fefo_opened_medicines_correctly_classified_in_dashboard_metrics(): void
    {
        $box = MedicineBox::where('name', 'Домашняя аптечка')->first();

        // 1. Препарат, просроченный по сроку вскрытия (opened_at + 10 дней < today),
        // хотя заводской срок годности еще через 1 год
        Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Сироп от кашля (вскрыт)',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::today()->addYear()->toDateString(),
            'opened_at' => Carbon::today()->subDays(30)->toDateString(),
            'days_after_opening' => 10,
            'status' => MedicineStatus::ACTIVE,
        ]);

        // 2. Препарат, истекающий в пределах 30 дней по сроку вскрытия (истекает через 15 дней),
        // хотя заводской срок годности еще через 1 год
        Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Глазные капли (вскрыты)',
            'quantity' => 1,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::today()->addYear()->toDateString(),
            'opened_at' => Carbon::today()->subDays(10)->toDateString(),
            'days_after_opening' => 25,
            'status' => MedicineStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard');

        $response->assertStatus(200);

        // Всего: 3 (из setUp) + 2 (новых) = 5
        $response->assertJsonPath('data.total_medicines', 5);

        // Просроченные: 1 (Аспирин из setUp) + 1 (Сироп от кашля с истекшим сроком вскрытия) = 2
        $response->assertJsonPath('data.expired_medicines', 2);

        // Истекающие: 1 (Називин из setUp) + 1 (Глазные капли со сроком вскрытия через 15 дней) = 2
        $response->assertJsonPath('data.expiring_medicines', 2);
    }
}
