<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\MedicineBoxUserRole;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MedicineBoxMemberController extends Controller
{
    /**
     * Список участников аптечки с ролями (owner, editor, viewer).
     */
    public function index(MedicineBox $medicineBox): JsonResponse
    {
        Gate::authorize('view', $medicineBox);

        $members = $medicineBox->users()
            ->select('users.id', 'users.email', 'users.timezone')
            ->withPivot('role', 'invited_by_user_id', 'created_at')
            ->get();

        return response()->json([
            'data' => $members,
        ], 200);
    }

    /**
     * Приглашение / добавление участника в аптечку.
     * Защита: только владелец аптечки (Gate::authorize('invite', $medicineBox)).
     */
    public function store(Request $request, MedicineBox $medicineBox): JsonResponse
    {
        Gate::authorize('invite', $medicineBox);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'role' => ['required', 'string', 'in:editor,viewer'],
        ]);

        $invitedUser = User::where('email', $validated['email'])->first();
        if (! $invitedUser) {
            return response()->json([
                'message' => 'Если пользователь с указанным адресом зарегистрирован в системе, приглашение в аптечку отправлено.',
            ], 200);
        }

        // Проверка согласия 152-ФЗ на облачную синхронизацию данных для семейного шеринга
        if (! $request->user()->sync_enabled || ! $invitedUser->sync_enabled) {
            return response()->json([
                'message' => 'Для использования семейного доступа необходимо включить облачную синхронизацию в настройках профиля.',
            ], 403);
        }

        // Проверяем, не является ли пользователь уже участником
        $existing = MedicineBoxUser::where('medicine_box_id', $medicineBox->id)
            ->where('user_id', $invitedUser->id)
            ->first();

        if ($existing) {
            $existingRole = $existing->role instanceof MedicineBoxUserRole ? $existing->role->value : (string) $existing->role;
            if ($existingRole === 'owner') {
                return response()->json(['message' => 'Пользователь уже является владельцем данной аптечки.'], 422);
            }

            // Обновляем роль
            $existing->update([
                'role' => $validated['role'],
                'invited_by_user_id' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Роль участника успешно обновлена.',
                'data' => [
                    'user_id' => $invitedUser->id,
                    'email' => $invitedUser->email,
                    'role' => $validated['role'],
                ],
            ], 200);
        }

        // Прикрепляем нового участника
        $medicineBox->users()->attach($invitedUser->id, [
            'role' => $validated['role'],
            'invited_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Пользователь успешно добавлен в аптечку.',
            'data' => [
                'user_id' => $invitedUser->id,
                'email' => $invitedUser->email,
                'role' => $validated['role'],
            ],
        ], 201);
    }

    /**
     * Исключение участника из аптечки.
     * Защита: только владелец аптечки, запрещено удалять себя как единственного владельца.
     */
    public function destroy(MedicineBox $medicineBox, int $userId): JsonResponse
    {
        Gate::authorize('removeMember', $medicineBox);

        $pivot = MedicineBoxUser::where('medicine_box_id', $medicineBox->id)
            ->where('user_id', $userId)
            ->first();

        if (! $pivot) {
            return response()->json(['message' => 'Пользователь не найден в данной аптечке.'], 404);
        }

        $roleValue = $pivot->role instanceof MedicineBoxUserRole ? $pivot->role->value : (string) $pivot->role;
        if ($roleValue === 'owner') {
            return response()->json(['message' => 'Запрещено удалять владельца аптечки.'], 422);
        }

        $medicineBox->users()->detach($userId);

        return response()->json([
            'message' => 'Пользователь успешно исключен из аптечки.',
        ], 200);
    }
}
