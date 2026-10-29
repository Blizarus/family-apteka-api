<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RestrictToInternalNetwork
{
    /**
     * Список доверенных IP-адресов и диапазонов внутренней сети (RFC 1918 + loopback).
     */
    protected array $trustedInternalIps = [
        '127.0.0.1',
        '::1',
    ];

    /**
     * Обработка входящего запроса.
     * Реализует требование ЛР 2 (Таблица 1, строка 5):
     * «Администратор: Учетные записи и справочники — доступ исключительно из внутренней сети с применением строгой IP-фильтрации».
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();

        // 1. Проверка IP-фильтрации (только внутренняя доверенная сеть)
        if (! $this->isInternalIp($clientIp)) {
            Log::warning("Попытка несанкционированного доступа к панели администрирования из внешней сети: IP {$clientIp}, URL: {$request->fullUrl()}");

            return response()->json([
                'message' => 'Доступ запрещен: подключение к административным ресурсам разрешено только из доверенной внутренней сети (строгая IP-фильтрация).',
                'client_ip' => $clientIp,
            ], 403);
        }

        // 2. Проверка аутентификации и роли администратора (RBAC)
        $user = $request->user();

        if (! $user || ! $user->is_admin) {
            Log::warning("Попытка доступа к панели администрирования пользователем без роли администратора: User ID " . ($user?->id ?? 'guest'));

            return response()->json([
                'message' => 'Доступ запрещен: требуются права администратора системы.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Определение, принадлежит ли IP-адрес доверенной внутренней сети.
     */
    protected function isInternalIp(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        // Прямое совпадение с loopback
        if (in_array($ip, $this->trustedInternalIps, true)) {
            return true;
        }

        // Частные подсети RFC 1918
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
            return true;
        }

        // Возможность переопределения через переменную окружения INTERNAL_ADMIN_IPS
        $customIps = array_filter(array_map('trim', explode(',', (string) env('INTERNAL_ADMIN_IPS', ''))));
        if (in_array($ip, $customIps, true)) {
            return true;
        }

        return false;
    }
}
