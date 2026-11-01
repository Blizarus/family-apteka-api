<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\User;
use App\NotificationLogType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Список системных уведомлений текущего пользователя.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = NotificationLog::where('user_id', $request->user()->id)
            ->latest('sent_at')
            ->get();

        return response()->json([
            'data' => $notifications,
        ], 200);
    }

    /**
     * Удаление уведомления.
     * Защита от IDOR: пользователь может удалять только свои уведомления.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $notification = NotificationLog::findOrFail($id);

        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Уведомление успешно удалено.',
        ], 200);
    }

    /**
     * Регистрация FCM-токена устройства пользователя.
     */
    public function registerFcmToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|max:255',
            'platform' => 'nullable|string|in:ios,android,web,flutter',
        ]);

        $platform = $validated['platform'] ?? 'flutter';

        $request->user()->update([
            'fcm_token' => $validated['fcm_token'],
            'device_platform' => $platform,
        ]);

        return response()->json([
            'message' => 'FCM token registered successfully',
            'fcm_token' => $validated['fcm_token'],
            'platform' => $platform,
        ], 200);
    }

    /**
     * Отправка тестового push-уведомления.
     */
    public function sendTestPush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $title = $validated['title'] ?? 'Тестовое уведомление';
        $body = $validated['body'] ?? 'Проверка push-уведомлений системы «Семейная аптечка».';

        NotificationLog::create([
            'user_id' => $user->id,
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'type' => NotificationLogType::PUSH_TEST,
            'title' => $title,
            'message' => $body,
            'channel' => 'push',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return response()->json([
            'message' => 'Test push notification queued/sent',
            'dispatched' => true,
        ], 200);
    }
}
