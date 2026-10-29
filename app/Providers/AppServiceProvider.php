<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Настройка защиты от перебора паролей (Brute-force): 5 попыток в минуту на связку email + IP
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)
                ->by($email . '|' . $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Превышен лимит попыток входа (Too Many Requests). Пожалуйста, повторите попытку через минуту.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429, $headers);
                });
        });
    }
}
