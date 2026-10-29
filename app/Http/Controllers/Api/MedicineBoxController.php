<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MedicineBoxController extends Controller
{
    /**
     * Список аптечек, доступных текущему пользователю.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $boxes = $user->medicineBoxes()
            ->withPivot('role')
            ->get();

        return response()->json([
            'data' => $boxes,
        ], 200);
    }

    /**
     * Создание новой аптечки (пользователь автоматически становится 'owner').
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'box_type' => ['required', 'in:home,car,child,travel,custom'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $box = MedicineBox::create($validated);

        // Привязываем пользователя как владельца
        $box->users()->attach($user->id, ['role' => 'owner']);

        return response()->json([
            'message' => 'Аптечка успешно создана.',
            'data' => $box,
        ], 201);
    }

    /**
     * Просмотр содержимого аптечки.
     * Защита от IDOR: проверка политики MedicineBoxPolicy::view
     */
    public function show(MedicineBox $medicineBox): JsonResponse
    {
        // Проверка прав доступа. При нарушении выбрасывается 403 Forbidden
        Gate::authorize('view', $medicineBox);

        $medicineBox->load(['medicines']);

        return response()->json([
            'data' => $medicineBox,
        ], 200);
    }

    /**
     * Обновление информации об аптечке.
     * Защита от IDOR: только owner и editor.
     */
    public function update(Request $request, MedicineBox $medicineBox): JsonResponse
    {
        Gate::authorize('update', $medicineBox);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'box_type' => ['sometimes', 'required', 'in:home,car,child,travel,custom'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $medicineBox->update($validated);

        return response()->json([
            'message' => 'Аптечка успешно обновлена.',
            'data' => $medicineBox,
        ], 200);
    }

    /**
     * Удаление аптечки.
     * Защита от IDOR: только owner.
     */
    public function destroy(MedicineBox $medicineBox): JsonResponse
    {
        Gate::authorize('delete', $medicineBox);

        $medicineBox->delete();

        return response()->json([
            'message' => 'Аптечка успешно удалена.',
        ], 200);
    }
}
