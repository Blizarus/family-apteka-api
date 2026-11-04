<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBox;
use App\Models\Profile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntakeTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $userMoscow;
    private Profile $profileMoscow;
    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userMoscow = User::factory()->create([
            'email' => 'moscow@family.test',
            'timezone' => 'Europe/Moscow',
        ]);

        $box = MedicineBox::create([
            'name' => 'Аптечка Москва',
            'box_type' => 'home',
        ]);
        $box->users()->attach($this->userMoscow->id, ['role' => 'owner']);

        $this->profileMoscow = Profile::create([
            'user_id' => $this->userMoscow->id,
            'name' => 'Иван Москва',
            'relationship' => 'self',
        ]);

        $this->medicine = Medicine::create([
            'medicine_box_id' => $box->id,
            'custom_trade_name' => 'Анальгин',
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'dosage' => '500мг',
            'status' => 'active',
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
        ]);
    }

    public function test_intake_schedule_generated_with_europe_moscow_timezone(): void
    {
        Sanctum::actingAs($this->userMoscow);

        $response = $this->postJson('/api/intake-courses', [
            'profile_id' => $this->profileMoscow->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Анальгин Москва',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'time_slots' => ['08:00'],
        ]);

        $response->assertStatus(201);

        $courseId = $response->json('data.id');
        $this->assertDatabaseHas('intake_logs', [
            'intake_course_id' => $courseId,
            // 08:00 MSK (UTC+3) must be stored in UTC as 05:00:00
            'scheduled_time' => '2026-11-10 05:00:00',
        ]);
    }

    public function test_intake_schedule_generated_with_utc_timezone(): void
    {
        $userUtc = User::factory()->create([
            'email' => 'utc@family.test',
            'timezone' => 'UTC',
        ]);
        $boxUtc = MedicineBox::create([
            'name' => 'Аптечка UTC',
            'box_type' => 'home',
        ]);
        $boxUtc->users()->attach($userUtc->id, ['role' => 'owner']);

        $profileUtc = Profile::create([
            'user_id' => $userUtc->id,
            'name' => 'John UTC',
            'relationship' => 'self',
        ]);

        $medUtc = Medicine::create([
            'medicine_box_id' => $boxUtc->id,
            'custom_trade_name' => 'Paracetamol UTC',
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'status' => 'active',
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
        ]);

        Sanctum::actingAs($userUtc);

        $response = $this->postJson('/api/intake-courses', [
            'profile_id' => $profileUtc->id,
            'medicine_id' => $medUtc->id,
            'title' => 'Курс UTC',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'time_slots' => ['08:00'],
        ]);

        $response->assertStatus(201);

        $courseId = $response->json('data.id');
        $this->assertDatabaseHas('intake_logs', [
            'intake_course_id' => $courseId,
            // 08:00 in UTC is 08:00:00
            'scheduled_time' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_intake_schedule_generated_with_asia_vladivostok_timezone(): void
    {
        $userVl = User::factory()->create([
            'email' => 'vladivostok@family.test',
            'timezone' => 'Asia/Vladivostok',
        ]);
        $boxVl = MedicineBox::create([
            'name' => 'Аптечка Владивосток',
            'box_type' => 'home',
        ]);
        $boxVl->users()->attach($userVl->id, ['role' => 'owner']);

        $profileVl = Profile::create([
            'user_id' => $userVl->id,
            'name' => 'Алексей Владивосток',
            'relationship' => 'self',
        ]);

        $medVl = Medicine::create([
            'medicine_box_id' => $boxVl->id,
            'custom_trade_name' => 'Цитрамон ВЛ',
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'status' => 'active',
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
        ]);

        Sanctum::actingAs($userVl);

        $response = $this->postJson('/api/intake-courses', [
            'profile_id' => $profileVl->id,
            'medicine_id' => $medVl->id,
            'title' => 'Курс Владивосток',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'time_slots' => ['08:00'],
        ]);

        $response->assertStatus(201);

        $courseId = $response->json('data.id');
        $this->assertDatabaseHas('intake_logs', [
            'intake_course_id' => $courseId,
            // 08:00 in Vladivostok (UTC+10) on 2026-11-10 is 2026-11-09 22:00:00 UTC
            'scheduled_time' => '2026-11-09 22:00:00',
        ]);
    }

    public function test_midnight_transition_intake_schedule(): void
    {
        // 1. Полночь в Москве: 00:30 на 2026-11-10 MSK -> 2026-11-09 21:30:00 UTC
        Sanctum::actingAs($this->userMoscow);

        $responseMoscow = $this->postJson('/api/intake-courses', [
            'profile_id' => $this->profileMoscow->id,
            'medicine_id' => $this->medicine->id,
            'title' => 'Курс Полночь Москва',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'time_slots' => ['00:30'],
        ]);

        $responseMoscow->assertStatus(201);
        $courseIdMsk = $responseMoscow->json('data.id');
        $this->assertDatabaseHas('intake_logs', [
            'intake_course_id' => $courseIdMsk,
            'scheduled_time' => '2026-11-09 21:30:00',
        ]);

        // 2. Полночь во Владивостоке: 01:00 на 2026-11-10 VLAT (UTC+10) -> 2026-11-09 15:00:00 UTC
        $userVl = User::factory()->create([
            'email' => 'vladivostok_midnight@family.test',
            'timezone' => 'Asia/Vladivostok',
        ]);
        $boxVl = MedicineBox::create([
            'name' => 'Аптечка ВЛ Полночь',
            'box_type' => 'home',
        ]);
        $boxVl->users()->attach($userVl->id, ['role' => 'owner']);

        $profileVl = Profile::create([
            'user_id' => $userVl->id,
            'name' => 'Ольга ВЛ',
            'relationship' => 'self',
        ]);

        $medVl = Medicine::create([
            'medicine_box_id' => $boxVl->id,
            'custom_trade_name' => 'Ночной препарат ВЛ',
            'quantity' => 20,
            'quantity_unit' => 'pill',
            'status' => 'active',
            'expiration_date' => Carbon::now()->addYear()->toDateString(),
        ]);

        Sanctum::actingAs($userVl);

        $responseVl = $this->postJson('/api/intake-courses', [
            'profile_id' => $profileVl->id,
            'medicine_id' => $medVl->id,
            'title' => 'Курс Ночной ВЛ',
            'start_date' => '2026-11-10',
            'dosage_quantity' => 1,
            'time_slots' => ['01:00'],
        ]);

        $responseVl->assertStatus(201);
        $courseIdVl = $responseVl->json('data.id');
        $this->assertDatabaseHas('intake_logs', [
            'intake_course_id' => $courseIdVl,
            'scheduled_time' => '2026-11-09 15:00:00',
        ]);
    }
}
