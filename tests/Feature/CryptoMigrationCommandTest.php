<?php

namespace Tests\Feature;

use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CryptoMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicineBox $box;
    protected Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'cryptotest@family.test',
            'password' => Hash::make('secret123'),
            'terms_accepted' => true,
            'sync_enabled' => true,
        ]);

        $this->box = MedicineBox::create([
            'name' => 'Аптечка для крипто-теста',
            'box_type' => MedicineBoxType::HOME,
        ]);

        MedicineBoxUser::create([
            'medicine_box_id' => $this->box->id,
            'user_id' => $this->user->id,
            'role' => MedicineBoxUserRole::OWNER,
        ]);

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Миша',
            'birth_date' => '2015-05-10',
        ]);
    }

    /**
     * DEF-20: Режим --dry-run сканирует базу, находит открытые данные, но НЕ меняет их в БД.
     */
    public function test_dry_run_detects_plaintext_without_modifying_db(): void
    {
        // Прямая сырая вставка нешифрованного текста в обход геттеров/мутаторов Eloquent
        $medId = DB::table('medicines')->insertGetId([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Аспирин Нешифрованный',
            'notes' => 'Сырая историческая заметка',
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('security:re-encrypt-pdn --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN');

        $rawRecord = DB::table('medicines')->where('id', $medId)->first();
        $this->assertSame('Аспирин Нешифрованный', $rawRecord->custom_trade_name);
        $this->assertSame('Сырая историческая заметка', $rawRecord->notes);
    }

    /**
     * DEF-20: Команда миграции шифрует plaintext в AES-256 (Data at Rest) для medicines, profiles и intake_courses.
     */
    public function test_migration_converts_plaintext_to_valid_aes256(): void
    {
        $medId = DB::table('medicines')->insertGetId([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Аспирин Нешифрованный',
            'notes' => 'Тест заметка',
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profId = DB::table('profiles')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Бабушка Елена',
            'birth_date' => '1950-01-01',
            'notes' => 'Аллергик со стажем',
            'allergies' => json_encode(['penicillin', 'aspirin']),
            'chronic_conditions' => json_encode(['hypertension']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courseId = DB::table('intake_courses')->insertGetId([
            'profile_id' => $profId,
            'medicine_id' => $medId,
            'title' => 'Курс лечения',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => 'Утром после еды',
            'notes' => 'Принимать строго после еды',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('security:re-encrypt-pdn --force')
            ->assertExitCode(0);

        // 1. Проверяем Medicine через Eloquent (дешифрование работает без DecryptException)
        $med = Medicine::find($medId);
        $this->assertNotNull($med);
        $this->assertSame('Аспирин Нешифрованный', $med->custom_trade_name);
        $this->assertSame('Тест заметка', $med->notes);

        // 2. Проверяем Profile через Eloquent
        $prof = Profile::find($profId);
        $this->assertNotNull($prof);
        $this->assertSame('Аллергик со стажем', $prof->notes);
        $this->assertSame(['penicillin', 'aspirin'], $prof->allergies);
        $this->assertSame(['hypertension'], $prof->chronic_conditions);

        // 3. Проверяем IntakeCourse через Eloquent
        $course = IntakeCourse::find($courseId);
        $this->assertNotNull($course);
        $this->assertSame('Принимать строго после еды', $course->notes);
    }

    /**
     * DEF-20: Уже зашифрованные записи не подвергаются повторному шифрованию и остаются корректными.
     */
    public function test_already_encrypted_records_remain_intact(): void
    {
        $med = Medicine::create([
            'medicine_box_id' => $this->box->id,
            'custom_trade_name' => 'Нурофен Форте',
            'notes' => 'Зашифрованная заметка',
            'quantity' => 10,
            'quantity_unit' => 'pill',
            'expiration_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $this->artisan('security:re-encrypt-pdn --force')
            ->assertExitCode(0);

        $freshMed = Medicine::find($med->id);
        $this->assertSame('Нурофен Форте', $freshMed->custom_trade_name);
        $this->assertSame('Зашифрованная заметка', $freshMed->notes);
    }
}
