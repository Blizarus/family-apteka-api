<?php

namespace Database\Seeders;

use App\IntakeLogStatus;
use App\IntakeScheduleFrequencyType;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\IntakeSchedule;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\NotificationLog;
use App\NotificationLogType;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with test scenarios according to diploma requirements.
     */
    public function run(): void
    {
        // 1. Основной пользователь (хеширование пароля через Argon2id, пароль из .env или случайный)
        $annaPassword = env('SEED_ANNA_PASSWORD', Str::random(16));
        $user = User::create([
            'email' => 'anna@family.test',
            'password' => Hash::make($annaPassword),
            'timezone' => 'Europe/Moscow',
            'sync_enabled' => true,
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        // 1.1. Администратор безопасности ИС (ролевой доступ RBAC согласно ЛР 2)
        $adminPassword = env('SEED_ADMIN_PASSWORD', Str::random(16));
        User::create([
            'email' => 'admin@family.test',
            'password' => Hash::make($adminPassword),
            'timezone' => 'Europe/Moscow',
            'sync_enabled' => true,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // 2. Три профиля членов семьи
        $annaProfile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Анна',
            'birth_date' => '1992-06-15',
            'avatar_url' => '👩',
            'notes' => 'Владелец семейного аккаунта',
            'allergies' => ['lactose'],
            'chronic_conditions' => [],
        ]);

        $elenaProfile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Бабушка Елена',
            'birth_date' => '1955-04-12',
            'avatar_url' => '👵',
            'notes' => 'Артериальная гипертензия II ст., хронический гастрит, контроль давления',
            'allergies' => [],
            'chronic_conditions' => ['hypertension', 'gastritis'],
        ]);

        $mishaProfile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Сын Миша',
            'birth_date' => '2016-08-20',
            'avatar_url' => '👦',
            'notes' => 'Аллергия на пенициллиновый ряд и цитрусовые',
            'allergies' => ['penicillin', 'citrus'],
            'chronic_conditions' => [],
        ]);

        // 3. Две аптечки: "Домашняя аптечка" и "Автомобильная"
        $homeBox = MedicineBox::create([
            'name' => 'Домашняя аптечка',
            'box_type' => MedicineBoxType::HOME,
            'description' => 'Шкаф в коридоре (полка 2)',
        ]);

        $carBox = MedicineBox::create([
            'name' => 'Автомобильная',
            'box_type' => MedicineBoxType::CAR,
            'description' => 'Бардачок автомобиля',
        ]);

        // Связываем пользователя как владельца аптечек
        MedicineBoxUser::create([
            'medicine_box_id' => $homeBox->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $carBox->id,
            'user_id' => $user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        // 4. Популярные справочные препараты (Единый справочник)
        $this->call(ReferenceMedicineSeeder::class);

        $refIbuprofen = ReferenceMedicine::where('barcode', '4601808001011')->first();
        $refAmoxi = ReferenceMedicine::where('barcode', '4601808001028')->first();
        $refNazivin = ReferenceMedicine::where('barcode', '4601808001035')->first();
        $refAspirin = ReferenceMedicine::where('barcode', '4601808001042')->first();
        $refOmeprazole = ReferenceMedicine::where('barcode', '4601808001059')->first();
        $refLoperamide = ReferenceMedicine::where('barcode', '4601808001066')->first();
        $refCardiomagnyl = ReferenceMedicine::where('barcode', '4601808001073')->first();

        // 5. 10-12 физических упаковок лекарств (включая 1 просроченное и 1 скоро истекающее)
        // 1. Ибупрофен (нормальный)
        $medIbuprofen = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refIbuprofen->id,
            'custom_trade_name' => 'Ибупрофен 400 мг',
            'barcode' => '4601808001011',
            'quantity' => 16.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(14)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Принимать после еды при головной боли или температуре',
        ]);

        // 2. Амоксициллин (нормальный, используется в текущем курсе)
        $medAmoxi = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refAmoxi->id,
            'custom_trade_name' => 'Амоксициллин 500 мг',
            'barcode' => '4601808001028',
            'quantity' => 8.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(8)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Антибиотик строго по назначению врача',
        ]);

        // 3. Називин — СКОРО ИСТЕКАЕТ (срок годности истекает через 5 дней)
        $medNazivin = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refNazivin->id,
            'custom_trade_name' => 'Спрей Називин 0.05%',
            'barcode' => '4601808001035',
            'quantity' => 1.0,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::now()->addDays(5)->toDateString(), // Скоро истекает!
            'opened_at' => Carbon::now()->subDays(20)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Вскрыт 20 дней назад, срок после вскрытия подходит к концу',
        ]);

        // 4. Аспирин Кардио — ПРОСРОЧЕН (срок истёк месяц назад)
        $medAspirin = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refAspirin->id,
            'custom_trade_name' => 'Аспирин Кардио 100 мг',
            'barcode' => '4601808001042',
            'quantity' => 4.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->subMonth()->toDateString(), // Просрочено!
            'status' => MedicineStatus::EXPIRED,
            'notes' => 'Препарат просрочен! Требуется утилизация.',
        ]);

        // 5. Омепразол (нормальный, курс Анны)
        $medOmeprazole = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refOmeprazole->id,
            'custom_trade_name' => 'Омепразол 20 мг',
            'barcode' => '4601808001059',
            'quantity' => 14.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(18)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Для защиты слизистой ЖКТ',
        ]);

        // 6. Лоперамид
        Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refLoperamide->id,
            'custom_trade_name' => 'Лоперамид 2 мг',
            'barcode' => '4601808001066',
            'quantity' => 18.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(24)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Симптоматическое средство при расстройстве',
        ]);

        // 7. Кардиомагнил (для Бабушки Елены)
        $medCardiomagnyl = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => $refCardiomagnyl->id,
            'custom_trade_name' => 'Кардиомагнил 75 мг',
            'barcode' => '4601808001073',
            'quantity' => 28.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(10)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Для разжижения крови, строго после еды',
        ]);

        // 8. Витаминный комплекс (кастомный препарат)
        $medVitamins = Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => null,
            'custom_trade_name' => 'Витаминный комплекс Multi-Tabs',
            'barcode' => '4601808009999',
            'quantity' => 30.0,
            'quantity_unit' => MedicineQuantityUnit::PILLS,
            'expiration_date' => Carbon::now()->addMonths(12)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Профилактика в осенне-весенний период',
        ]);

        // Препараты в Автомобильной аптечке
        // 9. Бинт стерильный
        Medicine::create([
            'medicine_box_id' => $carBox->id,
            'reference_medicine_id' => null,
            'custom_trade_name' => 'Бинт марлевый медицинский 5м х 10см',
            'barcode' => '4602000000011',
            'quantity' => 3.0,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::now()->addYears(3)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Стерильная перевязка в индивидуальной упаковке',
        ]);

        // 10. Хлоргексидин
        Medicine::create([
            'medicine_box_id' => $carBox->id,
            'reference_medicine_id' => null,
            'custom_trade_name' => 'Хлоргексидина биглюконат 0.05% 100 мл',
            'barcode' => '4602000000028',
            'quantity' => 1.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::now()->addMonths(16)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Антисептическая обработка ран и ссадин',
        ]);

        // 11. Пластырь бактерицидный
        Medicine::create([
            'medicine_box_id' => $carBox->id,
            'reference_medicine_id' => null,
            'custom_trade_name' => 'Лейкопластырь бактерицидный набор',
            'barcode' => '4602000000035',
            'quantity' => 10.0,
            'quantity_unit' => MedicineQuantityUnit::UNIT,
            'expiration_date' => Carbon::now()->addYears(2)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Водостойкий с антисептической подушечкой',
        ]);

        // 12. Парацетамол детский
        Medicine::create([
            'medicine_box_id' => $homeBox->id,
            'reference_medicine_id' => null,
            'custom_trade_name' => 'Парацетамол детский сироп 100 мл',
            'barcode' => '4601808001080',
            'quantity' => 80.0,
            'quantity_unit' => MedicineQuantityUnit::ML,
            'expiration_date' => Carbon::now()->addMonths(9)->toDateString(),
            'status' => MedicineStatus::ACTIVE,
            'notes' => 'Детский жаропонижающий сироп со вкусом клубники',
        ]);

        // 6. Курсы приёма (IntakeCourses)
        // Курс 1: Анна - Омепразол
        $courseAnnaOmeprazole = IntakeCourse::create([
            'profile_id' => $annaProfile->id,
            'medicine_id' => $medOmeprazole->id,
            'title' => 'Курс для ЖКТ',
            'start_date' => Carbon::today()->subDays(3)->toDateString(),
            'end_date' => Carbon::today()->addDays(11)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'До еды • 1 капсула',
            'is_active' => true,
            'notes' => 'Диагноз: обострение гастрита, назначение терапевта',
        ]);

        // Курс 2: Анна - Амоксициллин
        $courseAnnaAmoxi = IntakeCourse::create([
            'profile_id' => $annaProfile->id,
            'medicine_id' => $medAmoxi->id,
            'title' => 'Антибиотик (день 4 из 7)',
            'start_date' => Carbon::today()->subDays(3)->toDateString(),
            'end_date' => Carbon::today()->addDays(3)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'Во время еды • 1 таб.',
            'is_active' => true,
            'notes' => 'Принимать строго в одно и то же время через равные промежутки',
        ]);

        // Курс 3: Бабушка Елена - Кардиомагнил
        $courseElenaCardio = IntakeCourse::create([
            'profile_id' => $elenaProfile->id,
            'medicine_id' => $medCardiomagnyl->id,
            'title' => 'Постоянный прием (Кардио)',
            'start_date' => Carbon::today()->subMonths(2)->toDateString(),
            'end_date' => null,
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'После обеда • 1 таб.',
            'is_active' => true,
            'notes' => 'Профилактика тромбообразования, контроль кардиолога',
        ]);

        // Курс 4: Анна - Витамины
        $courseAnnaVitamins = IntakeCourse::create([
            'profile_id' => $annaProfile->id,
            'medicine_id' => $medVitamins->id,
            'title' => 'Профилактика',
            'start_date' => Carbon::today()->subDays(5)->toDateString(),
            'end_date' => Carbon::today()->addDays(25)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'После ужина • 1 драже',
            'is_active' => true,
            'notes' => 'Курс витаминов для укрепления иммунитета',
        ]);

        // Курс 5: Бабушка Елена - Ибупрофен (МНН Ибупрофен, АТХ M01AE01)
        $courseElenaIbu = IntakeCourse::create([
            'profile_id' => $elenaProfile->id,
            'medicine_id' => $medIbuprofen->id,
            'title' => 'Обезболивающая терапия (Ибупрофен)',
            'start_date' => Carbon::today()->subDays(1)->toDateString(),
            'end_date' => Carbon::today()->addDays(5)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'После еды • 1 таб.',
            'is_active' => true,
            'notes' => 'Короткий курс при обострении болей',
        ]);

        // 7. Расписания приёма (IntakeSchedules)
        $schedule1 = IntakeSchedule::create([
            'intake_course_id' => $courseAnnaOmeprazole->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['08:30'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $schedule2 = IntakeSchedule::create([
            'intake_course_id' => $courseAnnaAmoxi->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['13:00'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $schedule3 = IntakeSchedule::create([
            'intake_course_id' => $courseElenaCardio->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['14:00'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $schedule4 = IntakeSchedule::create([
            'intake_course_id' => $courseAnnaVitamins->id,
            'frequency_type' => IntakeScheduleFrequencyType::DAILY,
            'time_slots' => ['20:00'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        // 8. Логи приёма на сегодня (IntakeLogs)
        // Лог 1 (Утро): Омепразол — уже ПРИНЯТ
        IntakeLog::create([
            'intake_course_id' => $courseAnnaOmeprazole->id,
            'intake_schedule_id' => $schedule1->id,
            'medicine_id' => $medOmeprazole->id,
            'scheduled_time' => Carbon::today()->setTime(8, 30),
            'taken_time' => Carbon::today()->setTime(8, 32),
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::TAKEN,
        ]);

        // Лог 2 (День): Амоксициллин — запланирован (текущий)
        IntakeLog::create([
            'intake_course_id' => $courseAnnaAmoxi->id,
            'intake_schedule_id' => $schedule2->id,
            'medicine_id' => $medAmoxi->id,
            'scheduled_time' => Carbon::today()->setTime(13, 0),
            'taken_time' => null,
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Лог 3 (День): Кардиомагнил для Бабушки Елены — запланирован
        IntakeLog::create([
            'intake_course_id' => $courseElenaCardio->id,
            'intake_schedule_id' => $schedule3->id,
            'medicine_id' => $medCardiomagnyl->id,
            'scheduled_time' => Carbon::today()->setTime(14, 0),
            'taken_time' => null,
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // Лог 4 (Вечер): Витамины — запланирован
        IntakeLog::create([
            'intake_course_id' => $courseAnnaVitamins->id,
            'intake_schedule_id' => $schedule4->id,
            'medicine_id' => $medVitamins->id,
            'scheduled_time' => Carbon::today()->setTime(20, 0),
            'taken_time' => null,
            'quantity_taken' => 1.0,
            'status' => IntakeLogStatus::SCHEDULED,
        ]);

        // 7. Системные уведомления для владельца аккаунта
        NotificationLog::create([
            'user_id' => $user->id,
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'type' => NotificationLogType::EXPIRY_WARNING_7D,
            'title' => 'Истекает срок годности',
            'message' => 'Препарат Нурофен истекает через 3 дня.',
            'channel' => 'push',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
