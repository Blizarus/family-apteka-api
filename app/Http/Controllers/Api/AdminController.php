<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferenceMedicine;
use App\Models\User;
use App\ReferenceMedicineStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * Список пользователей системы со статусами учетных записей (для администратора).
     */
    public function users(Request $request): JsonResponse
    {
        $users = User::select('id', 'email', 'timezone', 'is_admin', 'is_blocked', 'blocked_at', 'created_at')
            ->orderBy('id', 'desc')
            ->paginate(25);

        return response()->json($users, 200);
    }

    /**
     * Блокировка / разблокировка учетной записи пользователя.
     * Реализует требование ЛР 2 (Таблица 1, строка 5):
     * «Администратор: Блокировка пользователей».
     */
    public function toggleBlockUser(Request $request, User $user): JsonResponse
    {
        // Запрет блокировки самого себя
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Администратор не может заблокировать собственную учетную запись.',
            ], 422);
        }

        $block = $request->boolean('block', ! $user->is_blocked);

        $user->update([
            'is_blocked' => $block,
            'blocked_at' => $block ? now() : null,
        ]);

        // Если пользователь заблокирован — немедленно отзываем все активные сессии и токены
        if ($block) {
            $user->tokens()->delete();
            Log::info("Администратор {$request->user()->id} заблокировал пользователя ID {$user->id}. Все активные токены аннулированы.");
        } else {
            Log::info("Администратор {$request->user()->id} разблокировал пользователя ID {$user->id}.");
        }

        return response()->json([
            'message' => $block ? 'Пользователь успешно заблокирован.' : 'Пользователь успешно разблокирован.',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'is_blocked' => $user->is_blocked,
                'blocked_at' => $user->blocked_at,
            ],
        ], 200);
    }

    /**
     * Добавление лекарственного препарата в эталонный справочник.
     * Реализует требование ЛР 2 (Таблица 1, строка 5):
     * «Администратор: Редактирование справочников».
     */
    public function storeReferenceMedicine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:255'],
            'dosage_form' => ['required', 'string', 'max:100'],
            'dosage' => ['required', 'string', 'max:100'],
            'pack_size' => ['required', 'integer', 'min:1'],
            'manufacturer' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:100'],
            'barcode' => ['required', 'string', 'max:50', 'unique:reference_medicines,barcode'],
            'atc_code' => ['nullable', 'string', 'max:20'],
            'is_prescription' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string'],
        ]);

        if (! isset($validated['status'])) {
            $validated['status'] = ReferenceMedicineStatus::ARHIVE;
        }

        $medicine = ReferenceMedicine::create($validated);

        Log::info("Администратор {$request->user()->id} добавил препарат '{$medicine->trade_name}' в справочник (ID {$medicine->id}).");

        return response()->json([
            'message' => 'Препарат успешно добавлен в справочник.',
            'data' => $medicine,
        ], 201);
    }

    /**
     * Редактирование записи справочника лекарственных препаратов.
     */
    public function updateReferenceMedicine(Request $request, ReferenceMedicine $referenceMedicine): JsonResponse
    {
        $validated = $request->validate([
            'trade_name' => ['sometimes', 'required', 'string', 'max:255'],
            'inn' => ['sometimes', 'required', 'string', 'max:255'],
            'dosage_form' => ['sometimes', 'required', 'string', 'max:100'],
            'dosage' => ['sometimes', 'required', 'string', 'max:100'],
            'pack_size' => ['sometimes', 'required', 'integer', 'min:1'],
            'manufacturer' => ['sometimes', 'required', 'string', 'max:255'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'barcode' => ['sometimes', 'required', 'string', 'max:50', 'unique:reference_medicines,barcode,' . $referenceMedicine->id],
            'atc_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_prescription' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string'],
        ]);

        $referenceMedicine->update($validated);

        Log::info("Администратор {$request->user()->id} обновил препарат в справочнике (ID {$referenceMedicine->id}).");

        return response()->json([
            'message' => 'Справочник успешно обновлен.',
            'data' => $referenceMedicine,
        ], 200);
    }

    /**
     * Удаление / архивация препарата из эталонного справочника.
     */
    public function destroyReferenceMedicine(Request $request, ReferenceMedicine $referenceMedicine): JsonResponse
    {
        $name = $referenceMedicine->trade_name;
        $referenceMedicine->delete();

        Log::info("Администратор {$request->user()->id} удалил препарат '{$name}' из справочника.");

        return response()->json([
            'message' => "Препарат '{$name}' успешно удален из справочника.",
        ], 200);
    }
}
