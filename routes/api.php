<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IntakeCourseController;
use App\Http\Controllers\Api\IntakeLogController;
use App\Http\Controllers\Api\MedicineBoxController;
use App\Http\Controllers\Api\MedicineBoxMemberController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\MedicationSafetyController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceMedicineController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Middleware\RestrictToInternalNetwork;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Проект «Семейная аптечка»
|--------------------------------------------------------------------------
|
| Здесь зарегистрированы защищенные эндпоинты REST API:
| - Rate Limiting для предотвращения брутфорса (throttle:login для /auth/login и /auth/register);
| - Аутентификация через токены Laravel Sanctum (Bearer токен);
| - RBAC/ABAC и защита от атак IDOR через Policies (MedicineBoxPolicy, IntakeCoursePolicy);
| - Сквозное шифрование чувствительных медицинских данных (AES-256).
|
*/

// Публичный доступ к справочнику медикаментов с защитой от скрапинга (Rate Limiter: не более 60 запросов в минуту)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/reference-medicines', [ReferenceMedicineController::class, 'index']);
    Route::get('/reference-medicines/{reference_medicine}', [ReferenceMedicineController::class, 'show']);
});

// Аутентификация: Rate Limiter строго 5 попыток в минуту на связку email + IP (с ответом 429 Too Many Requests)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/password', [AuthController::class, 'updatePassword']);
        Route::delete('/account', [AuthController::class, 'destroyAccount']);
    });
});

// Защищенные ресурсы приложения
Route::middleware('auth:sanctum')->group(function () {
    // 1. Сводный дашборд пользователя
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // 2. Системные уведомления
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // 3. Профили членов семьи (Анна, Бабушка Елена, Сын Миша)
    Route::apiResource('profiles', ProfileController::class);

    // 4. Семейный шеринг аптечки (управление участниками)
    Route::get('/medicine-boxes/{medicine_box}/members', [MedicineBoxMemberController::class, 'index']);
    Route::post('/medicine-boxes/{medicine_box}/members', [MedicineBoxMemberController::class, 'store']);
    Route::delete('/medicine-boxes/{medicine_box}/members/{user_id}', [MedicineBoxMemberController::class, 'destroy']);

    // 5. Аптечки (MedicineBoxPolicy: owner, editor, viewer)
    Route::apiResource('medicine-boxes', MedicineBoxController::class);

    // 6. Физические лекарства в аптечках (StoreMedicineRequest, шифрование полей)
    Route::post('/medicines/{medicine}/open', [MedicineController::class, 'open']);
    Route::apiResource('medicines', MedicineController::class);

    // 7. Курсы приёма (IntakeCoursePolicy, генерация расписания и 14-дневных логов)
    Route::apiResource('intake-courses', IntakeCourseController::class)->only(['index', 'show', 'store', 'destroy']);

    // 8. Клиническая безопасность (Clinical Decision Support - CDS)
    Route::post('/medication-safety/check', [MedicationSafetyController::class, 'check']);

    // 9. Логи и расписание приёма лекарств
    Route::prefix('intake-logs')->group(function () {
        Route::get('/', [IntakeLogController::class, 'index']);
        Route::get('/{id}', [IntakeLogController::class, 'show']);
        Route::post('/quick', [IntakeLogController::class, 'quickTake']);
        Route::post('/{id}/take', [IntakeLogController::class, 'take']);
        Route::post('/{id}/skip', [IntakeLogController::class, 'skip']);
        Route::post('/{id}/snooze', [IntakeLogController::class, 'snooze']);
    });

    // 10. Аналитические отчёты (комплаенс и приверженность лечению)
    Route::get('/reports/adherence', [ReportController::class, 'adherence']);
});

// 10. Панель администрирования (ЛР 2, Таблица 1, строка 5):
// Строгая IP-фильтрация (только внутренняя сеть) + RBAC проверка роли администратора
Route::prefix('admin')->middleware(['auth:sanctum', RestrictToInternalNetwork::class])->group(function () {
    // Управление пользователями и блокировка
    Route::get('/users', [AdminController::class, 'users']);
    Route::post('/users/{user}/block', [AdminController::class, 'toggleBlockUser']);

    // Редактирование эталонного справочника лекарственных средств
    Route::post('/reference-medicines', [AdminController::class, 'storeReferenceMedicine']);
    Route::put('/reference-medicines/{reference_medicine}', [AdminController::class, 'updateReferenceMedicine']);
    Route::delete('/reference-medicines/{reference_medicine}', [AdminController::class, 'destroyReferenceMedicine']);
});
