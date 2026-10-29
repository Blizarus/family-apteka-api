<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\IntakeLogStatus;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\Profile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntakeLogController extends Controller
{
    /**
     * Список приемов лекарств (по умолчанию: на сегодня).
     * Поддерживает фильтрацию по профилю (?profile_id=X) и дате (?date=YYYY-MM-DD).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $isHistory = $request->boolean('history')
            || $request->input('date') === 'all'
            || $request->input('date') === 'history';

        // Базовый запрос с жадной загрузкой
        $query = IntakeLog::whereHas('intakeCourse', function ($courseQ) use ($userProfileIds) {
            $courseQ->whereIn('profile_id', $userProfileIds);
        })
        ->with([
            'intakeCourse.profile',
            'intakeCourse.medicine',
            'medicine',
            'intakeSchedule',
        ]);

        // Фильтрация по конкретному члену семьи с защитой от IDOR
        if ($request->filled('profile_id')) {
            $profileId = (int) $request->input('profile_id');
            if (! $userProfileIds->contains($profileId)) {
                return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR).'], 403);
            }
            $query->whereHas('intakeCourse', function ($q) use ($profileId) {
                $q->where('profile_id', $profileId);
            });
        }

        // Фильтрация по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 1. Режим истории: пагинация по 50 записей
        if ($isHistory) {
            $paginated = $query->orderByDesc('scheduled_time')->paginate(50);
            return response()->json($paginated, 200);
        }

        // 2. Режим диапазона или конкретной даты
        $rawDate = $request->input('date');

        if ($request->filled('from') && $request->filled('to')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->whereBetween('scheduled_time', [$from, $to]);
            $date = $request->input('from') . ' - ' . $request->input('to');
        } elseif ($request->filled('date') && $rawDate !== 'today' && $rawDate !== 'window') {
            $date = $rawDate;
            $query->whereDate('scheduled_time', $date);
        } elseif ($rawDate === 'today') {
            $date = Carbon::today()->toDateString();
            $query->whereDate('scheduled_time', $date);
        } else {
            // По умолчанию (когда дата не указана):
            // Возвращаем скользящее окно расписания (от -7 дней назад до +14 дней вперед).
            // Это позволяет мобильному календарю видеть приёмы на всю неделю/две недели вперёд.
            $windowStart = Carbon::today()->subDays(7)->startOfDay();
            $windowEnd = Carbon::today()->addDays(14)->endOfDay();
            $query->whereBetween('scheduled_time', [$windowStart, $windowEnd]);
            $date = Carbon::today()->toDateString();
        }

        $logs = $query->orderBy('scheduled_time')
            ->get();

        return response()->json([
            'date' => $date,
            'data' => $logs,
        ], 200);
    }

    /**
     * Фиксация разового симптоматического приёма лекарства по требованию (PRN / Quick Intake).
     * Создает или находит системный PRN-курс, фиксирует лог со статусом 'taken'
     * и атомарно списывает дозу из физического остатка препарата в аптечке.
     */
    public function quickTake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicine_id' => 'required|integer|exists:medicines,id',
            'profile_id'  => 'required|integer|exists:profiles,id',
            'quantity'    => 'nullable|numeric|min:0.1|max:10',
            'symptom'     => 'nullable|string|max:255',
            'taken_at'    => 'nullable|date',
        ]);

        $user = $request->user();
        $profile = Profile::findOrFail($validated['profile_id']);

        // Проверка доступа к профилю (IDOR)
        if ($profile->user_id !== $user->id) {
            return response()->json(['message' => 'Доступ к указанному профилю запрещен.'], 403);
        }

        $medicine = Medicine::with(['medicineBox.users', 'referenceMedicine'])->findOrFail($validated['medicine_id']);

        // Проверка доступа к аптечке (IDOR)
        $hasBoxAccess = false;
        if ($medicine->medicineBox) {
            if (isset($medicine->medicineBox->user_id) && $medicine->medicineBox->user_id === $user->id) {
                $hasBoxAccess = true;
            } elseif ($medicine->medicineBox->users()->where('users.id', $user->id)->exists()) {
                $hasBoxAccess = true;
            }
        }

        if (! $hasBoxAccess) {
            return response()->json(['message' => 'Доступ к аптечке запрещен.'], 403);
        }

        $dose = (float) ($validated['quantity'] ?? 1.0);
        $takenAt = ! empty($validated['taken_at']) ? Carbon::parse($validated['taken_at']) : Carbon::now();
        $symptom = $validated['symptom'] ?? 'По требованию (симптом)';

        [$log, $newRemaining] = DB::transaction(function () use ($profile, $medicine, $dose, $takenAt, $symptom) {
            // 1. Курс "По требованию" (PRN)
            $course = IntakeCourse::firstOrCreate(
                [
                    'profile_id' => $profile->id,
                    'medicine_id' => $medicine->id,
                    'dosage_instruction' => 'По требованию (PRN)',
                ],
                [
                    'title' => 'По требованию: ' . ($medicine->custom_trade_name ?: ($medicine->referenceMedicine?->trade_name ?? 'Медикамент')),
                    'start_date' => Carbon::today(),
                    'end_date' => null,
                    'dosage_quantity' => $dose,
                    'notes' => 'Симптоматический приём по необходимости',
                    'is_active' => true,
                ]
            );

            // 2. Лог приёма
            $log = IntakeLog::create([
                'intake_course_id' => $course->id,
                'intake_schedule_id' => null,
                'medicine_id' => $medicine->id,
                'scheduled_time' => $takenAt,
                'taken_time' => $takenAt,
                'quantity_taken' => $dose,
                'status' => IntakeLogStatus::TAKEN,
                'skipped_reason' => $symptom,
            ]);

            // 3. Списание остатка в аптечке
            $newQty = max(0.0, ((float) $medicine->quantity) - $dose);
            $medicine->quantity = $newQty;
            if ($newQty <= 0) {
                $medicine->status = MedicineStatus::FINISHED;
            }
            $medicine->save();

            return [$log, $newQty];
        });

        return response()->json([
            'message' => 'Разовый приём успешно зафиксирован, остаток списан.',
            'data' => $log->load(['medicine', 'intakeCourse.profile']),
            'remaining_quantity' => $newRemaining,
        ], 201);
    }

    /**
     * Отметка о приеме лекарства (Take).
     * Требование ТЗ:
     * 1) Менять статус лога на 'taken' и фиксировать taken_time;
     * 2) Автоматически вычитать 1 (или назначенную дозу) из остатка упаковки (medicines.quantity).
     */
    public function take(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $intakeLog = IntakeLog::with(['intakeCourse.medicine', 'medicine'])->findOrFail($id);

        // Проверка прав доступа: лог должен относиться к профилю текущего пользователя
        if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
            return response()->json(['message' => 'Доступ к данному приему запрещен (IDOR).'], 403);
        }

        $medicine = null;

        // Атомарная транзакция: обновление статуса лога и списание остатка медикамента
        DB::transaction(function () use ($intakeLog, &$medicine) {
            $intakeLog->status = IntakeLogStatus::TAKEN;
            $intakeLog->taken_time = Carbon::now();
            if (! $intakeLog->quantity_taken) {
                $intakeLog->quantity_taken = $intakeLog->intakeCourse->dosage_quantity ?? 1;
            }
            $intakeLog->save();

            // Списание физического остатка препарата
            $medicine = $intakeLog->medicine ?? $intakeLog->intakeCourse->medicine;
            if ($medicine) {
                $amountToDeduct = $intakeLog->quantity_taken ?? 1;
                $medicine->decrement('quantity', $amountToDeduct);

                // Если препарат закончился — обновляем статус
                if ($medicine->quantity <= 0) {
                    $medicine->status = MedicineStatus::FINISHED;
                    $medicine->save();
                }
            }
        });

        return response()->json([
            'message' => 'Приём лекарства успешно зафиксирован. Остаток списан.',
            'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
            'remaining_quantity' => (float) $medicine?->fresh()->quantity,
        ], 200);
    }

    /**
     * Пропуск приема лекарства с указанием причины.
     */
    public function skip(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $intakeLog = IntakeLog::findOrFail($id);

        if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
            return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $intakeLog->status = IntakeLogStatus::SKIPPED;
        $intakeLog->skipped_reason = $validated['reason'] ?? 'Пропущено пользователем';
        $intakeLog->save();

        return response()->json([
            'message' => 'Приём лекарства отмечен как пропущенный.',
            'data' => $intakeLog,
        ], 200);
    }

    /**
     * Откладывание приёма лекарства на N минут (+15 минут по умолчанию).
     */
    public function snooze(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);
        $minutes = (int) ($validated['minutes'] ?? 15);

        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $intakeLog = IntakeLog::with(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine'])->findOrFail($id);

        if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
            return response()->json(['message' => 'Доступ к данному приему запрещен (IDOR).'], 403);
        }

        $baseTime = $intakeLog->scheduled_time->isPast() ? Carbon::now() : $intakeLog->scheduled_time;
        $intakeLog->scheduled_time = $baseTime->copy()->addMinutes($minutes);
        $intakeLog->save();

        return response()->json([
            'message' => "Приём лекарства успешно отложен на {$minutes} мин.",
            'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
        ], 200);
    }

    /**
     * Просмотр конкретного лога приёма.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $intakeLog = IntakeLog::with(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine'])->findOrFail($id);

        if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
            return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
        }

        return response()->json([
            'data' => $intakeLog,
        ], 200);
    }
}
