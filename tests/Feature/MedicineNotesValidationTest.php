<?php

namespace Tests\Feature;

use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineNotesValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Medicine $medicine;
    protected IntakeCourse $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'notes-dos@family.test',
            'is_blocked' => false,
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка Тест',
            'box_type' => 'home',
        ]);
        $box->users()->attach($this->user->id, ['role' => 'owner']);

        $refMed = ReferenceMedicine::create([
            'trade_name' => 'Анальгин Тест',
            'inn' => 'Metamizole',
            'atc_code' => 'N02BB02',
            'dosage_form' => 'Таблетки',
            'dosage' => '500 мг',
            'pack_size' => 10,
            'manufacturer' => 'Фармстандарт',
            'country' => 'Россия',
            'is_prescription' => false,
            'status' => 'active',
            'is_verified' => true,
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'reference_medicine_id' => $refMed->id,
            'custom_trade_name' => 'Анальгин Тест',
            'form' => 'таблетки',
            'quantity' => 10.0,
            'quantity_unit' => 'pill',
            'expiration_date' => Carbon::now()->addYear(),
            'status' => 'active',
        ]);

        $profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Пациент Профиль',
            'relationship' => 'self',
        ]);

        $this->course = IntakeCourse::create([
            'profile_id' => $profile->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Анальгина',
            'start_date' => Carbon::today(),
            'dosage_quantity' => 1.0,
            'dosage_instruction' => '1 таб при боли',
            'is_active' => true,
        ]);
    }

    /**
     * DEF-46: MedicineController::update отвергает notes длиннее 1000 символов с кодом 422.
     */
    public function test_medicine_update_rejects_notes_exceeding_1000_chars(): void
    {
        $longNotes = str_repeat('A', 1001);

        $response = $this->actingAs($this->user)
            ->putJson("/api/medicines/{$this->medicine->id}", [
                'notes' => $longNotes,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }

    /**
     * DEF-46: UpdateIntakeCourseRequest отвергает notes длиннее 1000 символов с кодом 422.
     */
    public function test_intake_course_update_rejects_notes_exceeding_1000_chars(): void
    {
        $longNotes = str_repeat('B', 1001);

        $response = $this->actingAs($this->user)
            ->putJson("/api/intake-courses/{$this->course->id}", [
                'notes' => $longNotes,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }
}
