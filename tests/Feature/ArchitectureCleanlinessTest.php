<?php

namespace Tests\Feature;

use App\IntakeLogStatus;
use App\IntakeScheduleFrequencyType;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\IntakeSchedule;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\NotificationLog;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\ReferenceMedicineProposal;
use App\Models\User;
use App\NotificationLogType;
use App\ReferenceMedicineProposalStatus;
use App\ReferenceMedicineStatus;
use App\StorageCondition;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ArchitectureCleanlinessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Список всех Eloquent-моделей приложения
     *
     * @return array<class-string<Model>>
     */
    protected function getAppModels(): array
    {
        return [
            IntakeCourse::class,
            IntakeLog::class,
            IntakeSchedule::class,
            Medicine::class,
            MedicineBox::class,
            MedicineBoxUser::class,
            NotificationLog::class,
            Profile::class,
            ReferenceMedicine::class,
            ReferenceMedicineProposal::class,
            User::class,
        ];
    }

    /**
     * Проверка а): Ни одна модель в приложении не использует пустой $guarded = []
     * и все модели строго определяют явный непустой $fillable (запрет неконтролируемого Mass Assignment).
     */
    public function test_no_model_uses_empty_guarded_and_all_define_explicit_fillable(): void
    {
        $models = $this->getAppModels();

        foreach ($models as $modelClass) {
            $ref = new ReflectionClass($modelClass);

            // Если в классе объявлено свойство guarded, оно не должно быть пустым массивом []
            if ($ref->hasProperty('guarded')) {
                $guardedProp = $ref->getProperty('guarded');
                $guardedProp->setAccessible(true);
                $guardedValue = $guardedProp->getValue(new $modelClass());

                $this->assertNotEquals(
                    [],
                    $guardedValue,
                    "Модель {$modelClass} не должна содержать уязвимый \$guarded = []. Используйте \$fillable!"
                );
            }

            /** @var Model $instance */
            $instance = new $modelClass();
            $fillable = $instance->getFillable();

            $this->assertNotEmpty(
                $fillable,
                "Модель {$modelClass} обязана определять непустой список \$fillable."
            );

            // Проверяем, что произвольный недопустимый атрибут отвергается
            $this->assertFalse(
                $instance->isFillable('unauthorized_admin_injection'),
                "Модель {$modelClass} не должна разрешать массовое заполнение произвольных атрибутов."
            );
        }
    }

    /**
     * Проверка б): Попытка Mass Assignment с недопустимым атрибутом блокируется Eloquent
     */
    public function test_mass_assignment_with_disallowed_attribute_is_blocked(): void
    {
        $models = $this->getAppModels();

        foreach ($models as $modelClass) {
            /** @var Model $instance */
            $instance = new $modelClass();

            // Пытаемся заполнить внедренный атрибут через fill
            $instance->fill([
                'malicious_exploit_field' => 'hacked_value',
                'is_admin' => true,
            ]);

            // Атрибут malicious_exploit_field не должен попасть в модель
            $this->assertNull(
                $instance->getAttribute('malicious_exploit_field'),
                "Модель {$modelClass} пропустила недопустимое поле malicious_exploit_field через Mass Assignment!"
            );

            $this->assertFalse(
                $instance->isFillable('malicious_exploit_field'),
                "Поле malicious_exploit_field не должно быть в fillable модели {$modelClass}."
            );
        }

        // Точечная проверка модели IntakeLog
        $log = new IntakeLog();
        $log->fill([
            'status' => 'taken',
            'id' => 999999,
            'forbidden_column' => 'attack',
        ]);
        $this->assertNull($log->getAttribute('forbidden_column'));
        $this->assertNull($log->getAttribute('id'));

        // Точечная проверка модели ReferenceMedicine
        $refMed = new ReferenceMedicine();
        $refMed->fill([
            'trade_name' => 'Парацетамол Тест',
            'injected_sql' => "'; DROP TABLE users; --",
        ]);
        $this->assertEquals('Парацетамол Тест', $refMed->trade_name);
        $this->assertNull($refMed->getAttribute('injected_sql'));
    }

    /**
     * Проверка в): Касты моделей (casts) работают корректно для всех ключевых моделей
     */
    public function test_model_casts_function_correctly(): void
    {
        // 1. IntakeSchedule casts
        $schedule = new IntakeSchedule();
        $schedule->frequency_type = 'daily';
        $schedule->time_slots = ['08:00', '14:00', '20:00'];
        $schedule->days_of_week = [1, 2, 3, 4, 5];
        $schedule->valid_from = '2026-11-01';
        $schedule->valid_until = '2026-11-30';

        $this->assertInstanceOf(IntakeScheduleFrequencyType::class, $schedule->frequency_type);
        $this->assertEquals(IntakeScheduleFrequencyType::DAILY, $schedule->frequency_type);
        $this->assertIsArray($schedule->time_slots);
        $this->assertCount(3, $schedule->time_slots);
        $this->assertIsArray($schedule->days_of_week);
        $this->assertInstanceOf(Carbon::class, $schedule->valid_from);
        $this->assertInstanceOf(Carbon::class, $schedule->valid_until);

        // 2. IntakeLog casts
        $intakeLog = new IntakeLog();
        $intakeLog->status = 'taken';
        $intakeLog->scheduled_time = '2026-11-01 09:00:00';
        $intakeLog->quantity_taken = '2.500';

        $this->assertInstanceOf(IntakeLogStatus::class, $intakeLog->status);
        $this->assertEquals(IntakeLogStatus::TAKEN, $intakeLog->status);
        $this->assertInstanceOf(Carbon::class, $intakeLog->scheduled_time);

        // 3. ReferenceMedicine casts
        $refMed = new ReferenceMedicine();
        $refMed->is_prescription = 1;
        $refMed->status = 'active';
        $refMed->allergen_tags = ['paracetamol', 'lactose'];
        $refMed->instructions = ['step1' => 'take with water'];
        $refMed->storage_condition = 'fridge';

        $this->assertTrue($refMed->is_prescription);
        $this->assertInstanceOf(ReferenceMedicineStatus::class, $refMed->status);
        $this->assertEquals('active', $refMed->status->value);
        $this->assertIsArray($refMed->allergen_tags);
        $this->assertIsArray($refMed->instructions);
        $this->assertInstanceOf(StorageCondition::class, $refMed->storage_condition);
        $this->assertEquals(StorageCondition::FRIDGE, $refMed->storage_condition);

        // 4. MedicineBox casts
        $box = new MedicineBox();
        $box->box_type = 'home';
        $this->assertInstanceOf(MedicineBoxType::class, $box->box_type);
        $this->assertEquals(MedicineBoxType::HOME, $box->box_type);

        // 5. MedicineBoxUser casts
        $boxUser = new MedicineBoxUser();
        $boxUser->role = 'editor';
        $this->assertInstanceOf(MedicineBoxUserRole::class, $boxUser->role);
        $this->assertEquals(MedicineBoxUserRole::EDITOR, $boxUser->role);

        // 6. NotificationLog casts
        $notif = new NotificationLog();
        $notif->type = 'expiry_critical';
        $notif->sent_at = '2026-11-01 12:00:00';
        $this->assertInstanceOf(NotificationLogType::class, $notif->type);
        $this->assertEquals(NotificationLogType::EXPIRY_CRITICAL, $notif->type);
        $this->assertInstanceOf(Carbon::class, $notif->sent_at);

        // 7. ReferenceMedicineProposal casts
        $proposal = new ReferenceMedicineProposal();
        $proposal->status = 'pending';
        $proposal->reviewed_at = '2026-11-01 15:30:00';
        $this->assertInstanceOf(ReferenceMedicineProposalStatus::class, $proposal->status);
        $this->assertEquals(ReferenceMedicineProposalStatus::PENDING, $proposal->status);
        $this->assertInstanceOf(Carbon::class, $proposal->reviewed_at);

        // 8. Profile casts (включая Data-at-Rest AES-256 шифрование notes)
        $user = User::create([
            'email' => 'cast_test@example.com',
            'password' => 'secret123',
            'sync_enabled' => true,
        ]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Иван Иванов',
            'birth_date' => '1990-05-15',
            'notes' => 'Секретная медицинская запись',
            'allergies' => ['Пенициллин'],
            'chronic_conditions' => ['Астма'],
        ]);

        $this->assertInstanceOf(Carbon::class, $profile->birth_date);
        $this->assertIsArray($profile->allergies);
        $this->assertIsArray($profile->chronic_conditions);
        $this->assertEquals('Секретная медицинская запись', $profile->notes);
    }
}
