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

        // 1. Препаратов всего в доступных пользователю аптечках
        $totalMedicines = Medicine::whereIn('medicine_box_id', $boxIds)->count();

        // 2. Истекающие препараты (статус expiring либо срок в пределах 30 дней)
        $expiringMedicines = Medicine::whereIn('medicine_box_id', $boxIds)
            ->where(function ($q) use ($today, $in30Days) {
                $q->where('status', MedicineStatus::EXPIRING)
                  ->orWhere(function ($sub) use ($today, $in30Days) {
                      $sub->where('status', MedicineStatus::ACTIVE)
                          ->where('expiration_date', '<=', $in30Days->toDateString())
                          ->where('expiration_date', '>=', $today->toDateString());
                  });
            })
            ->count();

        // 3. Просроченные препараты (статус expired либо срок уже прошёл)
        $expiredMedicines = Medicine::whereIn('medicine_box_id', $boxIds)
            ->where(function ($q) use ($today) {
                $q->where('status', MedicineStatus::EXPIRED)
                  ->orWhere(function ($sub) use ($today) {
                      $sub->where('status', MedicineStatus::ACTIVE)
                          ->where('expiration_date', '<', $today->toDateString());
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
