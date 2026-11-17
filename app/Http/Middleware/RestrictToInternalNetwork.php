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
        $user = $request->user();
        $clientIp = $request->ip();
        $isInternal = $this->isInternalIp($clientIp);
        $adminInternalOnly = (bool) config('app.admin_internal_only', env('ADMIN_INTERNAL_ONLY', false));

        // 1. Режим строгой внутренней сети (ADR-001: ADMIN_INTERNAL_ONLY = true)
        if ($adminInternalOnly) {
            if (! $isInternal) {
                Log::warning("Блокировка доступа к панели администрирования из внешней сети (ADMIN_INTERNAL_ONLY=true): IP {$clientIp}");
                return response()->json([
                    'message' => 'Доступ запрещен: подключение к административным ресурсам разрешено только из доверенной внутренней сети (строгая IP-фильтрация).',
                    'client_ip' => $clientIp,
                ], 403);
            }

            if (! $user || ! $user->is_admin) {
                return response()->json([
                    'message' => 'Доступ запрещен: требуются права администратора системы.',
                ], 403);
            }

            return $next($request);
        }

        // 2. Режим гибридного доступа (ADR-001 / DEF-05: ADMIN_INTERNAL_ONLY = false)
        if ($user && $user->is_admin) {
            if (! $isInternal) {
                Log::info('[SECURITY] Вход администратора из внешней/мобильной сети', [
                    'ip' => $clientIp,
                    'user_id' => $user->id,
                    'route' => $request->path(),
                    'user_agent' => $request->userAgent(),
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
            return $next($request);
        }

        // 3. Для неаутентифицированных пользователей или не-администраторов
        if (! $isInternal) {
            Log::warning("Попытка несанкционированного доступа к панели администрирования из внешней сети: IP {$clientIp}");
            return response()->json([
                'message' => 'Доступ запрещен: подключение к административным ресурсам разрешено только из доверенной внутренней сети (строгая IP-фильтрация).',
                'client_ip' => $clientIp,
            ], 403);
        }

        return response()->json([
            'message' => 'Доступ запрещен: требуются права администратора системы.',
        ], 403);
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
