<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\IntakeLogStatus;
use App\Models\IntakeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Аналитический отчёт о приверженности лечению (комплаенс / Adherence Report).
     *
     * Параметры запроса:
     * - period: '7_days' (по умолчанию), '30_days', 'all'
     * - profile_id / family_member_id: опциональный ID профиля члена семьи (с защитой от IDOR)
     */
    public function adherence(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedProfileIds = $user->profiles()->pluck('id');

        // Проверка фильтрации по профилю (защита от IDOR)
        if ($request->filled('profile_id') || $request->filled('family_member_id')) {
            $requestedProfileId = (int) ($request->input('profile_id') ?? $request->input('family_member_id'));
            if (! $allowedProfileIds->contains($requestedProfileId)) {
                return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR).'], 403);
            }
            $targetProfileIds = [$requestedProfileId];
        } else {
            $targetProfileIds = $allowedProfileIds->all();
        }

        // Определение временного периода
        $periodType = $request->input('period', '7_days');
        $endDate = Carbon::today()->endOfDay();

        if ($periodType === '30_days') {
            $startDate = Carbon::today()->subDays(29)->startOfDay();
        } elseif ($periodType === 'all') {
            $firstLog = IntakeLog::whereHas('intakeCourse', function ($q) use ($targetProfileIds) {
                $q->whereIn('profile_id', $targetProfileIds);
            })->orderBy('scheduled_time', 'asc')->first();

            $startDate = $firstLog && $firstLog->scheduled_time
                ? Carbon::parse($firstLog->scheduled_time)->startOfDay()
                : Carbon::today()->subDays(29)->startOfDay();
        } else {
            $periodType = '7_days';
            $startDate = Carbon::today()->subDays(6)->startOfDay();
        }

        // Загрузка логов приёма за выбранный интервал
        $logs = IntakeLog::whereHas('intakeCourse', function ($q) use ($targetProfileIds) {
            $q->whereIn('profile_id', $targetProfileIds);
        })
        ->whereBetween('scheduled_time', [$startDate, $endDate])
        ->with(['medicine.referenceMedicine', 'intakeCourse.medicine.referenceMedicine'])
        ->orderBy('scheduled_time', 'asc')
        ->get();

        // Агрегированные метрики
        $scheduledCount = $logs->count();
        $takenCount = $logs->filter(function ($log) {
            $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
            return $status === IntakeLogStatus::TAKEN->value;
        })->count();

        $missedCount = $logs->filter(function ($log) {
            $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
            return in_array($status, [IntakeLogStatus::MISSED->value, IntakeLogStatus::SKIPPED->value], true);
        })->count();

        $adherenceRate = $scheduledCount > 0
            ? round(($takenCount / $scheduledCount) * 100, 1)
            : 100.0;

        // Посуточная статистика
        $dailyStats = [];
        $currentDate = $startDate->copy()->startOfDay();
        $finalDate = $endDate->copy()->startOfDay();

        $logsByDate = $logs->groupBy(function ($log) {
            return Carbon::parse($log->scheduled_time)->toDateString();
        });

        while ($currentDate->lessThanOrEqualTo($finalDate)) {
            $dateStr = $currentDate->toDateString();
            $dayLogs = $logsByDate->get($dateStr, collect());

            $dayScheduled = $dayLogs->count();
            $dayTaken = $dayLogs->filter(function ($log) {
                $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
                return $status === IntakeLogStatus::TAKEN->value;
            })->count();

            $dayMissed = $dayLogs->filter(function ($log) {
                $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
                return in_array($status, [IntakeLogStatus::MISSED->value, IntakeLogStatus::SKIPPED->value], true);
            })->count();

            $dailyStats[] = [
                'date' => $dateStr,
                'scheduled' => $dayScheduled,
                'taken' => $dayTaken,
                'missed' => $dayMissed,
            ];

            $currentDate->addDay();
        }

        // Расчёт непрерывной серии приёмов без единого пропуска (streak_days)
        $streakDays = 0;
        $todayStr = Carbon::today()->toDateString();
        $reversedStats = array_reverse($dailyStats);

        foreach ($reversedStats as $dayStat) {
            // Анализируем дни не позднее сегодняшней даты
            if ($dayStat['date'] > $todayStr) {
                continue;
            }

            // Дни без назначений не сбрасывают серию
            if ($dayStat['scheduled'] === 0) {
                continue;
            }

            // Наличие хотя бы одного пропуска сбрасывает серию
            if ($dayStat['missed'] > 0) {
                break;
            }

            // Все приёмы успешно выполнены
            if ($dayStat['taken'] > 0) {
                $streakDays++;
            } else {
                // Если сегодня ещё не было приёмов, но пропусков нет — не прерываем серию вчерашнего дня
                if ($dayStat['date'] === $todayStr) {
                    continue;
                }
                break;
            }
        }

        // Аналитика в разрезе лекарственных препаратов
        $medicinesSummary = [];
        $logsByMedicine = $logs->groupBy(function ($log) {
            $medicine = $log->medicine ?? $log->intakeCourse?->medicine;
            return $medicine?->id ?? 0;
        });

        foreach ($logsByMedicine as $medId => $medLogs) {
            $firstLog = $medLogs->first();
            $medicine = $firstLog->medicine ?? $firstLog->intakeCourse?->medicine;

            $medName = $medicine?->custom_trade_name
                ?: ($medicine?->referenceMedicine?->trade_name ?? ($medId ? "Препарат #{$medId}" : 'Неизвестный препарат'));

            $mScheduled = $medLogs->count();
            $mTaken = $medLogs->filter(function ($log) {
                $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
                return $status === IntakeLogStatus::TAKEN->value;
            })->count();

            $mMissed = $medLogs->filter(function ($log) {
                $status = $log->status instanceof IntakeLogStatus ? $log->status->value : (string) $log->status;
                return in_array($status, [IntakeLogStatus::MISSED->value, IntakeLogStatus::SKIPPED->value], true);
            })->count();

            $mAdherence = $mScheduled > 0
                ? round(($mTaken / $mScheduled) * 100, 1)
                : 100.0;

            $medicinesSummary[] = [
                'medicine_id' => $medId,
                'name' => $medName,
                'scheduled_count' => $mScheduled,
                'taken_count' => $mTaken,
                'missed_count' => $mMissed,
                'adherence_rate' => $mAdherence,
            ];
        }

        return response()->json([
            'period' => [
                'type' => $periodType,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'scheduled_count' => $scheduledCount,
            'taken_count' => $takenCount,
            'missed_count' => $missedCount,
            'adherence_rate' => $adherenceRate,
            'streak_days' => $streakDays,
            'daily_stats' => $dailyStats,
            'medicines_summary' => $medicinesSummary,
        ], 200);
    }
}
