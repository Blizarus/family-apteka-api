<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntakeCourseRequest;
use App\Http\Requests\UpdateIntakeCourseRequest;
use App\IntakeLogStatus;
use App\IntakeScheduleFrequencyType;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\IntakeSchedule;
use App\Models\Medicine;
use App\Models\Profile;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IntakeCourseController extends Controller
{
    /**
     * Список курсов приёма лекарств текущего пользователя.
     * Защита от IDOR: фильтрация только по профилям пользователя.
     */
    public function index(Request $request): JsonResponse
    {
        $userProfileIds = $request->user()->profiles()->pluck('id');

        $query = IntakeCourse::whereIn('profile_id', $userProfileIds)
            ->with(['profile', 'medicine.referenceMedicine', 'medicine.medicineBox', 'intakeSchedules']);

        // Опциональный фильтр по члену семьи
        if ($request->filled('profile_id')) {
            $profileId = (int) $request->input('profile_id');
            if (! $userProfileIds->contains($profileId)) {
                return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR).'], 403);
            }
            $query->where('profile_id', $profileId);
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $courses = $query->latest()->paginate(25);

        return response()->json($courses, 200);
    }

    /**
     * Просмотр конкретного курса приёма.
     */
    public function show(IntakeCourse $intakeCourse): JsonResponse
    {
        Gate::authorize('view', $intakeCourse);

        return response()->json([
            'data' => $intakeCourse->load(['profile', 'medicine.referenceMedicine', 'intakeSchedules', 'intakeLogs']),
        ], 200);
    }

    /**
     * Создание нового курса приёма с автоматической генерацией расписаний и логов на 14 дней вперед.
     * Защита от IDOR через IntakeCoursePolicy::create.
     */
    public function store(StoreIntakeCourseRequest $request, MedicationSafetyService $safetyService): JsonResponse
    {
        $validated = $request->validated();

        $profile = Profile::findOrFail($validated['profile_id']);
        $medicine = Medicine::with(['medicineBox', 'referenceMedicine'])->findOrFail($validated['medicine_id']);

        // 1. Проверка прав доступа через политику
        Gate::authorize('create', [IntakeCourse::class, $profile, $medicine]);

        // 2. Клинический аудит безопасности медикаментозного назначения
        $safetyReport = $safetyService->checkSafety($profile, $medicine);

        // 3. Транзакционное создание курса, расписаний и 14-дневных логов
        [$course, $logsCreated] = DB::transaction(function () use ($validated, $profile, $medicine) {
            $title = $validated['title'] ?? ('Курс: ' . ($medicine->custom_trade_name ?: $medicine->referenceMedicine?->trade_name ?: 'Лекарство'));
            $dosageInstruction = $validated['dosage_instruction'] ?? $validated['rule'] ?? 'По назначению';

            // 1) Создание записи курса
            $course = IntakeCourse::create([
                'profile_id' => $profile->id,
                'medicine_id' => $medicine->id,
                'title' => $title,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'dosage_quantity' => $validated['dosage_quantity'],
                'dosage_instruction' => $dosageInstruction,
                'notes' => $validated['notes'] ?? null,
                'is_active' => true,
            ]);

            // 2) Создание записей intake_schedules на каждое указанное время дня
            $timeSlots = $validated['time_slots'] ?? $validated['times'] ?? ['08:00'];
            if (is_string($timeSlots)) {
                $timeSlots = json_decode($timeSlots, true) ?: [$timeSlots];
            }
            sort($timeSlots);

            $schedules = [];
            foreach ($timeSlots as $slot) {
                $schedules[] = IntakeSchedule::create([
                    'intake_course_id' => $course->id,
                    'frequency_type' => IntakeScheduleFrequencyType::DAILY,
                    'time_slots' => [$slot],
                    'valid_from' => $course->start_date,
                    'valid_until' => $course->end_date,
                ]);
            }

            // 3) Автоматическая генерация логов intake_logs на ближайшие 14 дней вперед со статусом scheduled
            $userTimezone = $profile->user?->timezone ?? 'Europe/Moscow';
            $startDate = Carbon::parse($course->start_date, $userTimezone)->startOfDay();
            $today = Carbon::now($userTimezone)->startOfDay();
            $beginDate = $startDate->greaterThan($today) ? $startDate->copy() : $today->copy();
            $targetEndDate = $beginDate->copy()->addDays(13); // 14 дней включительно

            if (! empty($course->end_date)) {
                $courseEnd = Carbon::parse($course->end_date, $userTimezone)->startOfDay();
                if ($courseEnd->lessThan($targetEndDate)) {
                    $targetEndDate = $courseEnd;
                }
            }

            $logsCount = 0;
            if ($beginDate->lessThanOrEqualTo($targetEndDate)) {
                for ($date = $beginDate->copy(); $date->lessThanOrEqualTo($targetEndDate); $date->addDay()) {
                    foreach ($schedules as $schedule) {
                        $slotTime = $schedule->time_slots[0] ?? '08:00';
                        [$hour, $minute] = explode(':', $slotTime);
                        $scheduledDateTime = Carbon::createFromFormat(
                            'Y-m-d H:i:s',
                            $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', (int) $hour, (int) $minute),
                            $userTimezone
                        )->setTimezone('UTC');

                        IntakeLog::create([
                            'intake_course_id' => $course->id,
                            'intake_schedule_id' => $schedule->id,
                            'medicine_id' => $medicine->id,
                            'scheduled_time' => $scheduledDateTime,
                            'taken_time' => null,
                            'quantity_taken' => $course->dosage_quantity,
                            'status' => IntakeLogStatus::SCHEDULED,
                        ]);
                        $logsCount++;
                    }
                }
            }

            return [$course, $logsCount];
        });

        return response()->json([
            'message' => 'Курс приёма успешно создан. Сгенерировано напоминаний: ' . $logsCreated,
            'data' => $course->load(['profile', 'medicine.referenceMedicine', 'intakeSchedules']),
            'logs_created' => $logsCreated,
            'warnings' => $safetyReport['warnings'],
            'safety' => $safetyReport,
        ], 201);
    }

    /**
     * Обновление существующего курса приёма.
     * Защита от IDOR через IntakeCoursePolicy::update.
     */
    public function update(UpdateIntakeCourseRequest $request, IntakeCourse $intakeCourse): JsonResponse
    {
        Gate::authorize('update', $intakeCourse);

        $validated = $request->validated();
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $intakeCourse->update($validated);

        // Если курс завершен досрочно (is_active = false), отменяем (удаляем) все будущие запланированные приёмы
        if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            $intakeCourse->intakeLogs()
                ->where('status', IntakeLogStatus::SCHEDULED)
                ->where('scheduled_time', '>=', Carbon::now())
                ->delete();
        }

        return response()->json([
            'message' => 'Курс приёма успешно обновлен.',
            'data' => $intakeCourse->fresh(['profile', 'medicine.referenceMedicine', 'intakeSchedules', 'intakeLogs']),
        ], 200);
    }

    /**
     * Продление активного курса приёма: генерация следующих 14 дней логов.
     * Защита от IDOR через IntakeCoursePolicy::update.
     */
    public function extend(Request $request, IntakeCourse $intakeCourse): JsonResponse
    {
        Gate::authorize('update', $intakeCourse);

        if (! $intakeCourse->is_active) {
            return response()->json([
                'message' => 'Нельзя продлить завершенный курс приёма.',
            ], 422);
        }

        $logsCreated = DB::transaction(function () use ($intakeCourse) {
            $today = Carbon::today();
            $lastLog = $intakeCourse->intakeLogs()->orderByDesc('scheduled_time')->first();

            if ($lastLog) {
                $lastLogDate = Carbon::parse($lastLog->scheduled_time)->startOfDay();
                $nextDay = $lastLogDate->copy()->addDay();
                $beginDate = $nextDay->greaterThan($today) ? $nextDay : $today->copy();
            } else {
                $beginDate = $today->copy();
            }

            $targetEndDate = $beginDate->copy()->addDays(13); // 14 дней включительно

            if (! empty($intakeCourse->end_date)) {
                $courseEnd = Carbon::parse($intakeCourse->end_date)->startOfDay();
                if ($courseEnd->lessThan($targetEndDate)) {
                    $targetEndDate = $courseEnd;
                }
            }

            $schedules = $intakeCourse->intakeSchedules;
            if ($schedules->isEmpty()) {
                $schedules = collect([
                    IntakeSchedule::create([
                        'intake_course_id' => $intakeCourse->id,
                        'frequency_type' => IntakeScheduleFrequencyType::DAILY,
                        'time_slots' => ['08:00'],
                        'valid_from' => $intakeCourse->start_date,
                        'valid_until' => $intakeCourse->end_date,
                    ]),
                ]);
            }

            $userTimezone = $intakeCourse->profile?->user?->timezone ?? 'Europe/Moscow';
            $logsCount = 0;
            if ($beginDate->lessThanOrEqualTo($targetEndDate)) {
                for ($date = $beginDate->copy(); $date->lessThanOrEqualTo($targetEndDate); $date->addDay()) {
                    foreach ($schedules as $schedule) {
                        $timeSlots = is_array($schedule->time_slots) ? $schedule->time_slots : ['08:00'];
                        foreach ($timeSlots as $slotTime) {
                            [$hour, $minute] = array_pad(explode(':', (string) $slotTime), 2, '00');
                            $scheduledDateTime = Carbon::createFromFormat(
                                'Y-m-d H:i:s',
                                $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', (int) $hour, (int) $minute),
                                $userTimezone
                            )->setTimezone('UTC');

                            IntakeLog::create([
                                'intake_course_id' => $intakeCourse->id,
                                'intake_schedule_id' => $schedule->id,
                                'medicine_id' => $intakeCourse->medicine_id,
                                'scheduled_time' => $scheduledDateTime,
                                'taken_time' => null,
                                'quantity_taken' => $intakeCourse->dosage_quantity,
                                'status' => IntakeLogStatus::SCHEDULED,
                            ]);
                            $logsCount++;
                        }
                    }
                }
            }

            return $logsCount;
        });

        return response()->json([
            'message' => 'Курс приёма успешно продлен. Сгенерировано напоминаний: ' . $logsCreated,
            'logs_created' => $logsCreated,
            'data' => $intakeCourse->fresh(['profile', 'medicine.referenceMedicine', 'intakeSchedules']),
        ], 200);
    }

    /**
     * Удаление курса приёма.
     */
    public function destroy(IntakeCourse $intakeCourse): JsonResponse
    {
        Gate::authorize('delete', $intakeCourse);

        $intakeCourse->delete();

        return response()->json([
            'message' => 'Курс приёма успешно удален.',
        ], 200);
    }
}
