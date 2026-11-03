<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use App\Services\MedicationSafetyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicationSafetyController extends Controller
{
    /**
     * Превентивная проверка клинической безопасности перед назначением лекарственного средства.
     * Защита от IDOR: проверка принадлежности профиля и аптечки текущему пользователю.
     */
    public function check(Request $request, MedicationSafetyService $safetyService): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer'],
            'medicine_id' => ['nullable', 'required_without:reference_medicine_id', 'integer'],
            'reference_medicine_id' => ['nullable', 'required_without:medicine_id', 'integer'],
        ]);

        $profile = Profile::where('id', $validated['profile_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        // Защита от оракула 404/403: чужие и несуществующие профили отдают строго 403 Forbidden
        if (! $profile) {
            return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR protection).'], 403);
        }

        // Определение целевого препарата
        if (! empty($validated['medicine_id'])) {
            $medicine = Medicine::with(['referenceMedicine', 'medicineBox.users'])
                ->find($validated['medicine_id']);

            $hasAccess = false;
            if ($medicine && $medicine->medicineBox) {
                if (isset($medicine->medicineBox->user_id) && $medicine->medicineBox->user_id === $request->user()->id) {
                    $hasAccess = true;
                } elseif ($medicine->medicineBox->users()->where('users.id', $request->user()->id)->exists()) {
                    $hasAccess = true;
                }
            }

            if (! $hasAccess) {
                return response()->json(['message' => 'Доступ к указанной аптечке запрещен (IDOR protection).'], 403);
            }

            $target = $medicine;
        } else {
            $target = ReferenceMedicine::find($validated['reference_medicine_id']);
            if (! $target) {
                return response()->json(['message' => 'Препарат справочника не найден.'], 404);
            }
        }

        $report = $safetyService->checkSafety($profile, $target);

        return response()->json([
            'data' => $report,
        ], 200);
    }
}
