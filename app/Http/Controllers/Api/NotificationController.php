<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
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
}
