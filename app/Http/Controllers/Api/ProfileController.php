<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Список профилей членов семьи текущего пользователя.
     */
    public function index(Request $request): JsonResponse
    {
        $profiles = $request->user()->profiles()
            ->withCount(['intakeCourses as active_courses_count' => function ($query) {
                $query->where('is_active', true);
            }])
            ->get();

        return response()->json([
            'data' => $profiles,
        ], 200);
    }

    /**
     * Создание нового профиля члена семьи.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:100'],
            'chronic_conditions' => ['nullable', 'array'],
            'chronic_conditions.*' => ['string', 'max:100'],
        ]);

        $profile = $request->user()->profiles()->create($validated);

        return response()->json([
            'message' => 'Профиль члена семьи успешно создан.',
            'data' => $profile,
        ], 201);
    }

    /**
     * Просмотр конкретного профиля.
     * Защита от IDOR: доступ только к профилям своего аккаунта.
     */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ запрещен (IDOR protection).'], 403);
        }

        $profile->load(['intakeCourses.medicine', 'intakeCourses.intakeSchedules']);

        return response()->json([
            'data' => $profile,
        ], 200);
    }

    /**
     * Редактирование профиля.
     * Защита от IDOR: доступ только к профилям своего аккаунта.
     */
    public function update(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ запрещен (IDOR protection).'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:100'],
            'chronic_conditions' => ['nullable', 'array'],
            'chronic_conditions.*' => ['string', 'max:100'],
        ]);

        $profile->update($validated);

        return response()->json([
            'message' => 'Профиль успешно обновлен.',
            'data' => $profile,
        ], 200);
    }

    /**
     * Удаление профиля (Soft delete).
     * Защита от IDOR: доступ только к профилям своего аккаунта.
     */
    public function destroy(Request $request, Profile $profile): JsonResponse
    {
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ запрещен (IDOR protection).'], 403);
        }

        $profile->delete();

        return response()->json([
            'message' => 'Профиль успешно удален.',
        ], 200);
    }
}
