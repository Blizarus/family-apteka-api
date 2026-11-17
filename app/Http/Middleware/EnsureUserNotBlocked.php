<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotBlocked
{
    /**
     * Защита от использования активных токенов Sanctum заблокированными пользователями (DEF-45).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ($user->is_blocked || $user->fresh()?->is_blocked)) {
            return response()->json([
                'message' => 'Учетная запись заблокирована администратором безопасности.',
            ], 403);
        }

        return $next($request);
    }
}
