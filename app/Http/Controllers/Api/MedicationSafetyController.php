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
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'medicine_id' => ['nullable', 'required_without:reference_medicine_id', 'integer', 'exists:medicines,id'],
            'reference_medicine_id' => ['nullable', 'required_without:medicine_id', 'integer', 'exists:reference_medicines,id'],
        ]);

        $profile = Profile::findOrFail($validated['profile_id']);

        // Проверка IDOR: профиль должен принадлежать текущему пользователю
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR protection).'], 403);
        }

        // Определение целевого препарата
        if (! empty($validated['medicine_id'])) {
            $medicine = Medicine::with(['referenceMedicine', 'medicineBox.users'])->findOrFail($validated['medicine_id']);

            // Проверка IDOR: пользователь должен иметь доступ к аптечке, где лежит лекарство
            $hasAccess = $medicine->medicineBox->users()
                ->where('users.id', $request->user()->id)
                ->exists();

            if (! $hasAccess) {
                return response()->json(['message' => 'Доступ к указанной аптечке запрещен (IDOR protection).'], 403);
            }

            $target = $medicine;
        } else {
            $target = ReferenceMedicine::findOrFail($validated['reference_medicine_id']);
        }

        $report = $safetyService->checkSafety($profile, $target);

        return response()->json([
            'data' => $report,
        ], 200);
    }
}
