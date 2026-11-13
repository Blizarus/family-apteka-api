<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Исключение: папку /.well-known/ не закрывать никакими правилами (Let's Encrypt ACME)
        if ($request->is('.well-known/*')) {
            return $next($request);
        }

        // 2. Редирект с HTTP на HTTPS на проде (если Nginx/панель хостинга его не выполняет)
        if (! $request->isSecure() && ! app()->environment('local', 'testing')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        /** @var Response $response */
        $response = $next($request);

        // 3. Заголовок HSTS с коротким сроком (1 день = 86400 сек) для безопасного начала перехода
        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=86400; includeSubDomains');
        }

        // 4. Защита от MIME-sniffing и Clickjacking
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
