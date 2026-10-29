<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicineRequest;
use App\MedicineStatus;
use App\StorageCondition;
use App\Models\Medicine;
use App\Models\MedicineBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

class MedicineController extends Controller
{
    /**
     * Список лекарственных препаратов в аптечках пользователя.
     * Защита от IDOR: фильтрация только по аптечкам, к которым у пользователя есть доступ.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userBoxIds = $user->medicineBoxes()->pluck('medicine_boxes.id');

        $query = Medicine::whereIn('medicine_box_id', $userBoxIds)
            ->with(['referenceMedicine', 'medicineBox']);

        // Фильтрация по конкретной аптечке
        if ($request->has('medicine_box_id')) {
            $boxId = (int) $request->input('medicine_box_id');
            if (! $userBoxIds->contains($boxId)) {
                return response()->json(['message' => 'Доступ к указанной аптечке запрещен (IDOR).'], 403);
            }
            $query->where('medicine_box_id', $boxId);
        }

        // Фильтрация по статусу (active, expired, finished, disposed)
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Фильтр: истекающие препараты (в ближайшие 30 дней)
        if ($request->boolean('expiring')) {
            $query->where('expiration_date', '<=', now()->addDays(30))
                ->where('expiration_date', '>=', now()->toDateString())
                ->where('status', MedicineStatus::ACTIVE);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $perPage = max($perPage, 1);

        // Поиск по наименованию (с поддержкой расшифрованных кастомных наименований и справочника ГРЛС)
        if ($request->filled('q')) {
            $rawSearch = mb_strtolower(trim((string) $request->input('q')));

            $allMedicines = $query->orderBy('expiration_date')->get();

            // Post-query фильтрация по расшифрованному custom_trade_name и справочнику ГРЛС
            $filtered = $allMedicines->filter(function ($medicine) use ($rawSearch) {
                $customMatches = ! empty($medicine->custom_trade_name)
                    && str_contains(mb_strtolower($medicine->custom_trade_name), $rawSearch);

                $refMatches = $medicine->referenceMedicine && (
                    str_contains(mb_strtolower($medicine->referenceMedicine->trade_name ?? ''), $rawSearch) ||
                    str_contains(mb_strtolower($medicine->referenceMedicine->inn ?? ''), $rawSearch)
                );

                return $customMatches || $refMatches;
            })->values();

            $page = LengthAwarePaginator::resolveCurrentPage();
            $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

            $medicines = new LengthAwarePaginator(
                $items,
                $filtered->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $medicines = $query->orderBy('expiration_date')->paginate($perPage);
        }

        return response()->json($medicines, 200);
    }

    /**
     * Создание лекарственного препарата в аптечке.
     * Валидация входных данных осуществляется через StoreMedicineRequest.
     */
    public function store(StoreMedicineRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $box = MedicineBox::findOrFail($validated['medicine_box_id']);

        // Проверка права на добавление лекарств в аптечку
        Gate::authorize('update', $box);

        if (! isset($validated['status'])) {
            $validated['status'] = MedicineStatus::ACTIVE;
        }

        $medicine = Medicine::create($validated);

        return response()->json([
            'message' => 'Препарат успешно добавлен в аптечку.',
            'data' => $medicine->load('referenceMedicine'),
        ], 201);
    }

    /**
     * Просмотр детальной информации о препарате.
     */
    public function show(Medicine $medicine): JsonResponse
    {
        Gate::authorize('view', $medicine->medicineBox);

        return response()->json([
            'data' => $medicine->load(['referenceMedicine', 'medicineBox']),
        ], 200);
    }

    /**
     * Обновление данных о препарате (изменение количества, статуса, даты вскрытия).
     */
    public function update(Request $request, Medicine $medicine): JsonResponse
    {
        Gate::authorize('update', $medicine->medicineBox);

        $validated = $request->validate([
            'custom_trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'expiration_date' => ['sometimes', 'required', 'date'],
            'opened_at' => ['sometimes', 'nullable', 'date'],
            'days_after_opening' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'storage_condition' => ['sometimes', 'nullable', new Enum(StorageCondition::class)],
            'status' => ['sometimes', 'required', new Enum(MedicineStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $medicine->update($validated);

        return response()->json([
            'message' => 'Данные препарата успешно обновлены.',
            'data' => $medicine->load('referenceMedicine'),
        ], 200);
    }

    /**
     * Удаление препарата из аптечки.
     */
    public function destroy(Medicine $medicine): JsonResponse
    {
        Gate::authorize('update', $medicine->medicineBox);

        $medicine->delete();

        return response()->json([
            'message' => 'Препарат успешно удален.',
        ], 200);
    }
}
