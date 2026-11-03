<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\IntakeLogStatus;
use App\MedicineStatus;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Сводный дашборд ключевых показателей пользователя и его семейной аптечки.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $boxIds = $user->medicineBoxes()->pluck('medicine_boxes.id');
        $profileIds = $user->profiles()->pluck('id');

        $today = Carbon::today();
        $in30Days = $today->copy()->addDays(30);
        $todayStr = $today->toDateString();
        $in30DaysStr = $in30Days->toDateString();
        $driver = DB::connection()->getDriverName();

        // 1. Препаратов всего в доступных пользователю аптечках
        $totalMedicines = Medicine::whereIn('medicine_box_id', $boxIds)->count();

        // 2. Истекающие препараты (статус expiring либо срок в пределах 30 дней, с учетом модели FEFO-Opened)
        $expiringMedicines = Medicine::whereIn('medicine_box_id', $boxIds)
            ->where(function ($q) use ($todayStr, $in30DaysStr, $driver) {
                $q->where('status', MedicineStatus::EXPIRING)
                  ->orWhere(function ($sub) use ($todayStr, $in30DaysStr, $driver) {
                      $sub->where('status', MedicineStatus::ACTIVE)
                          ->where('expiration_date', '>=', $todayStr)
                          ->where(function ($notExpiredOpenQ) use ($todayStr, $driver) {
                              $notExpiredOpenQ->whereNull('opened_at')
                                              ->whereNull('days_after_opening')
                                              ->orWhere(function ($validOpenQ) use ($todayStr, $driver) {
                                                  $validOpenQ->whereNotNull('opened_at')
                                                             ->whereNotNull('days_after_opening');
                                                  if ($driver === 'sqlite') {
                                                      $validOpenQ->whereRaw("date(opened_at, '+' || days_after_opening || ' day') >= ?", [$todayStr]);
                                                  } else {
                                                      $validOpenQ->whereRaw("DATE_ADD(opened_at, INTERVAL days_after_opening DAY) >= ?", [$todayStr]);
                                                  }
                                              });
                          })
                          ->where(function ($within30Q) use ($in30DaysStr, $driver) {
                              $within30Q->where('expiration_date', '<=', $in30DaysStr)
                                        ->orWhere(function ($openWithinQ) use ($in30DaysStr, $driver) {
                                            $openWithinQ->whereNotNull('opened_at')
                                                        ->whereNotNull('days_after_opening');
                                            if ($driver === 'sqlite') {
                                                $openWithinQ->whereRaw("date(opened_at, '+' || days_after_opening || ' day') <= ?", [$in30DaysStr]);
                                            } else {
                                                $openWithinQ->whereRaw("DATE_ADD(opened_at, INTERVAL days_after_opening DAY) <= ?", [$in30DaysStr]);
                                            }
                                        });
                          });
                  });
            })
            ->count();

        // 3. Просроченные препараты (статус expired либо срок уже прошёл / модель FEFO-Opened)
        $expiredMedicines = Medicine::whereIn('medicine_box_id', $boxIds)
            ->where(function ($q) use ($todayStr, $driver) {
                $q->where('status', MedicineStatus::EXPIRED)
                  ->orWhere(function ($sub) use ($todayStr, $driver) {
                      $sub->where('status', MedicineStatus::ACTIVE)
                          ->where(function ($dateQ) use ($todayStr, $driver) {
                              $dateQ->where('expiration_date', '<', $todayStr)
                                    ->orWhere(function ($openQ) use ($todayStr, $driver) {
                                        $openQ->whereNotNull('opened_at')
                                              ->whereNotNull('days_after_opening');
                                        if ($driver === 'sqlite') {
                                            $openQ->whereRaw("date(opened_at, '+' || days_after_opening || ' day') < ?", [$todayStr]);
                                        } else {
                                            $openQ->whereRaw("DATE_ADD(opened_at, INTERVAL days_after_opening DAY) < ?", [$todayStr]);
                                        }
                                    });
                          });
                  });
            })
            ->count();

        // 4. Приёмы лекарств на сегодня
        $todayLogs = IntakeLog::whereHas('intakeCourse', function ($q) use ($profileIds) {
                $q->whereIn('profile_id', $profileIds);
            })
            ->whereDate('scheduled_time', $today->toDateString())
            ->get();

        $todayTotal = $todayLogs->count();
        $todayTaken = $todayLogs->where('status', IntakeLogStatus::TAKEN)->count();
        $todayRemaining = max(0, $todayTotal - $todayTaken);

        // 5. Активные курсы лечения
        $activeCoursesCount = IntakeCourse::whereIn('profile_id', $profileIds)
            ->where('is_active', true)
            ->count();

        return response()->json([
            'data' => [
                'total_medicines' => $totalMedicines,
                'expiring_medicines' => $expiringMedicines,
                'expired_medicines' => $expiredMedicines,
                'today_intakes' => [
                    'total' => $todayTotal,
                    'taken' => $todayTaken,
                    'remaining' => $todayRemaining,
                ],
                'active_courses_count' => $activeCoursesCount,
            ],
        ], 200);
    }
}
