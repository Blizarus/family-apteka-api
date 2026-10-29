<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\MedicineBoxType;
use App\MedicineBoxUserRole;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Регистрация нового пользователя с автоматической инициализацией семейного профиля и базовой аптечки.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$user, $profile, $box, $token] = DB::transaction(function () use ($validated) {
            $syncEnabled = isset($validated['sync_enabled']) ? (bool) $validated['sync_enabled'] : false;

            // 1. Создание пользователя с хешированием пароля через Argon2id и фиксацией согласия 152-ФЗ
            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'timezone' => $validated['timezone'] ?? 'Europe/Moscow',
                'sync_enabled' => $syncEnabled,
                'consent_given_at' => now(),
                'consent_version' => '1.0',
                'email_verified_at' => now(),
            ]);

            // 2. Создание первого семейного профиля
            $profile = Profile::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'notes' => 'Основной профиль владельца',
                'avatar_url' => '👩',
            ]);

            // 3. Создание базовой "Домашней аптечки"
            $box = MedicineBox::create([
                'name' => 'Домашняя аптечка',
                'box_type' => MedicineBoxType::HOME,
                'description' => 'Основная домашняя аптечка',
            ]);

            // 4. Назначение роли владельца аптечки
            MedicineBoxUser::create([
                'medicine_box_id' => $box->id,
                'user_id' => $user->id,
                'role' => MedicineBoxUserRole::OWNER,
            ]);

            // 5. Выпуск Sanctum Bearer токена
            $token = $user->createToken('api_access_token')->plainTextToken;

            return [$user, $profile, $box, $token];
        });

        return response()->json([
            'message' => 'Регистрация успешно завершена.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'is_admin' => (bool) $user->is_admin,
            ],
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
            ],
            'default_box' => [
                'id' => $box->id,
                'name' => $box->name,
                'box_type' => $box->box_type,
            ],
        ], 201);
    }

    /**
     * Аутентификация пользователя и выдача Bearer токена.
     * Защищено с помощью RateLimiter (throttle:login - не более 5 попыток в минуту).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        // Проверка пароля алгоритмом Argon2id с постоянным временем выполнения
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Неверный логин или пароль.',
            ], 401);
        }

        // Проверка блокировки учетной записи администратором безопасности
        if ($user->is_blocked) {
            return response()->json([
                'message' => 'Учетная запись заблокирована администратором безопасности.',
            ], 403);
        }

        // В случае необходимости обновления параметров хеша (например, повышение требований памяти Argon2id)
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($credentials['password']);
            $user->save();
        }

        // Генерация Bearer-токена Laravel Sanctum
        $token = $user->createToken('api_access_token')->plainTextToken;

        return response()->json([
            'message' => 'Успешная аутентификация',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'is_admin' => (bool) $user->is_admin,
            ],
        ], 200);
    }

    /**
     * Получение информации о текущем аутентифицированном пользователе.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'sync_enabled' => $user->sync_enabled,
            'timezone' => $user->timezone,
            'is_admin' => (bool) $user->is_admin,
        ], 200);
    }

    /**
     * Завершение сессии и отзыв активных токенов доступа.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && method_exists($user, 'currentAccessToken')) {
            $user->currentAccessToken()?->delete();
        }

        return response()->json([
            'message' => 'Сессия успешно завершена.',
        ], 200);
    }

    /**
     * Смена пароля текущего пользователя с валидацией текущего пароля (Argon2id).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'Необходимо указать текущий пароль.',
            'password.required' => 'Новый пароль обязателен для заполнения.',
            'password.min' => 'Новый пароль должен содержать не менее 8 символов.',
            'password.confirmed' => 'Подтверждение нового пароля не совпадает.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль указан неверно.',
                'errors' => [
                    'current_password' => ['Текущий пароль указан неверно.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Пароль успешно изменен.',
        ], 200);
    }

    /**
     * Удаление учетной записи пользователя в соответствии с требованиями 152-ФЗ.
     * Отзывает токены и выполняет soft-delete профилей, аптечек и пользователя.
     */
    public function destroyAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Для подтверждения удаления аккаунта необходимо ввести пароль.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Неверный пароль для подтверждения удаления аккаунта.',
                'errors' => [
                    'current_password' => ['Неверный пароль для подтверждения удаления аккаунта.'],
                ],
            ], 422);
        }

        DB::transaction(function () use ($user) {
            // 1. Отзыв всех Sanctum токенов
            $user->tokens()->delete();

            // 2. Soft-delete профилей членов семьи
            $user->profiles()->delete();

            // 3. Soft-delete аптечек, где пользователь является владельцем
            $ownerBoxes = $user->medicineBoxes()
                ->wherePivot('role', MedicineBoxUserRole::OWNER)
                ->get();

            foreach ($ownerBoxes as $box) {
                $box->delete();
            }

            // 4. Soft-delete самой учетной записи
            $user->delete();
        });

        return response()->json([
            'message' => 'Аккаунт и все связанные персональные данные успешно удалены.',
        ], 200);
    }
}
