<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\IntakeLogStatus;
use App\MedicineStatus;
use App\NotificationLogType;
use App\Models\IntakeCourse;
use App\Models\IntakeLog;
use App\Models\Medicine;
use App\Models\NotificationLog;
use App\Models\Profile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IntakeLogController extends Controller
{
    /**
     * Список приемов лекарств (по умолчанию: на сегодня).
     * Поддерживает фильтрацию по профилю (?profile_id=X) и дате (?date=YYYY-MM-DD).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $isHistory = $request->boolean('history')
            || $request->input('date') === 'all'
            || $request->input('date') === 'history';

        // Базовый запрос с жадной загрузкой
        $query = IntakeLog::whereHas('intakeCourse', function ($courseQ) use ($userProfileIds) {
            $courseQ->whereIn('profile_id', $userProfileIds);
        })
        ->with([
            'intakeCourse.profile',
            'intakeCourse.medicine',
            'medicine',
            'intakeSchedule',
        ]);

        // Фильтрация по конкретному члену семьи с защитой от IDOR
        if ($request->filled('profile_id')) {
            $profileId = (int) $request->input('profile_id');
            if (! $userProfileIds->contains($profileId)) {
                return response()->json(['message' => 'Доступ к указанному профилю запрещен (IDOR).'], 403);
            }
            $query->whereHas('intakeCourse', function ($q) use ($profileId) {
                $q->where('profile_id', $profileId);
            });
        }

        // Фильтрация по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 1. Режим истории: пагинация по 50 записей
        if ($isHistory) {
            $paginated = $query->orderByDesc('scheduled_time')->paginate(50);
            return response()->json($paginated, 200);
        }

        // 2. Режим диапазона или конкретной даты
        $rawDate = $request->input('date');

        if ($request->filled('from') && $request->filled('to')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->whereBetween('scheduled_time', [$from, $to]);
            $date = $request->input('from') . ' - ' . $request->input('to');
        } elseif ($request->filled('date') && $rawDate !== 'today' && $rawDate !== 'window') {
            $date = $rawDate;
            $query->whereDate('scheduled_time', $date);
        } elseif ($rawDate === 'today') {
            $date = Carbon::today()->toDateString();
            $query->whereDate('scheduled_time', $date);
        } else {
            // По умолчанию (когда дата не указана):
            // Возвращаем скользящее окно расписания (от -7 дней назад до +14 дней вперед).
            // Это позволяет мобильному календарю видеть приёмы на всю неделю/две недели вперёд.
            $windowStart = Carbon::today()->subDays(7)->startOfDay();
            $windowEnd = Carbon::today()->addDays(14)->endOfDay();
            $query->whereBetween('scheduled_time', [$windowStart, $windowEnd]);
            $date = Carbon::today()->toDateString();
        }

        $logs = $query->orderBy('scheduled_time')
            ->get();

        return response()->json([
            'date' => $date,
            'data' => $logs,
        ], 200);
    }

    /**
     * Вычисление детерминированного хеша тела запроса (без служебных ключей).
     */
    protected function computeRequestHash(Request $request): string
    {
        $payload = $request->except(['idempotency_key', '_token']);
        ksort($payload);
        return hash('sha256', json_encode($payload));
    }

    /**
     * Попытка резервирования ключа идемпотентности или возврат сохраненного ответа.
     * Реализует IETF-совместимый протокол идемпотентности с ожиданием (spin-lock):
     * 1) Если запись уже существует:
     *    - при несовпадении хеша запроса -> строго 422 Unprocessable Entity;
     *    - при совпадении хеша и наличии response_code -> возврат сохраненного ответа;
     *    - при совпадении хеша и response_code === null (идет обработка первым потоком) ->
     *      ожидание завершения первой транзакции через spin-lock до 4 секунд.
     * 2) Если записи нет:
     *    - атомарная вставка с response_code = null.
     *    - при коллизии 1062 (гонка) -> переход в spin-lock ожидания ответа.
     */
    protected function reserveOrWaitIdempotency(int $userId, ?string $key, string $requestHash): array
    {
        if (empty($key)) {
            return ['action' => 'process'];
        }

        // 1. Проверяем, существует ли уже запись
        $record = DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if ($record) {
            if ($record->request_hash !== $requestHash) {
                return [
                    'action' => 'response',
                    'response' => response()->json([
                        'message' => 'Идемпотентный ключ уже использован с другим телом запроса.',
                    ], 422),
                ];
            }

            if ($record->response_code !== null) {
                return [
                    'action' => 'response',
                    'response' => response()->json(
                        json_decode($record->response_body, true),
                        $record->response_code
                    ),
                ];
            }

            // Идет обработка первым потоком -> ожидаем завершения
            return [
                'action' => 'response',
                'response' => $this->waitForIdempotentResponse($userId, $key, $requestHash),
            ];
        }

        // 2. Попытка атомарного резервирования
        try {
            DB::table('idempotency_keys')->insert([
                'user_id' => $userId,
                'key' => $key,
                'request_hash' => $requestHash,
                'response_code' => null,
                'response_body' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['action' => 'process'];
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'idempotency_keys')) {
                return [
                    'action' => 'response',
                    'response' => $this->waitForIdempotentResponse($userId, $key, $requestHash),
                ];
            }
            throw $e;
        }
    }

    /**
     * Ожидание завершения конкурирующей транзакции для того же ключа идемпотентности.
     * Опрашивает БД каждые 50 мс с таймаутом до 4 секунд.
     * Все 10 параллельных потоков гарантированно получают HTTP 200 с идентичным ответом.
     */
    protected function waitForIdempotentResponse(int $userId, string $key, string $requestHash, int $timeoutSeconds = 4): JsonResponse
    {
        $startTime = microtime(true);
        while ((microtime(true) - $startTime) < $timeoutSeconds) {
            usleep(50000); // 50 мс
            $record = DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('key', $key)
                ->first();

            if (! $record) {
                continue;
            }

            if ($record->request_hash !== $requestHash) {
                return response()->json([
                    'message' => 'Идемпотентный ключ уже использован с другим телом запроса.',
                ], 422);
            }

            if ($record->response_code !== null) {
                return response()->json(
                    json_decode($record->response_body, true),
                    $record->response_code
                );
            }
        }

        return response()->json([
            'message' => 'Время ожидания обработки идемпотентного запроса истекло.',
        ], 409);
    }

    /**
     * Проверка существующего ключа идемпотентности (совместимость).
     */
    protected function checkIdempotency(int $userId, ?string $key, string $requestHash): ?JsonResponse
    {
        if (empty($key)) {
            return null;
        }
        $res = $this->reserveOrWaitIdempotency($userId, $key, $requestHash);
        return $res['action'] === 'response' ? $res['response'] : null;
    }

    /**
     * Сохранение фактического ответа для зарезервированного ключа идемпотентности.
     */
    protected function recordIdempotency(int $userId, ?string $key, string $requestHash, int $code, array $body): void
    {
        if (empty($key)) {
            return;
        }

        try {
            DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('key', $key)
                ->update([
                    'response_code' => $code,
                    'response_body' => json_encode($body),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            try {
                DB::table('idempotency_keys')->insert([
                    'user_id' => $userId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'response_code' => $code,
                    'response_body' => json_encode($body),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $insertError) {
                Log::warning('Не удалось сохранить ответ для ключа идемпотентности', [
                    'user_id' => $userId,
                    'key' => $key,
                    'response_code' => $code,
                    'error' => $insertError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Освобождение зарезервированного ключа при ошибке транзакции.
     */
    protected function releaseIdempotencyReservation(int $userId, ?string $key): void
    {
        if (empty($key)) {
            return;
        }

        try {
            DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('key', $key)
                ->whereNull('response_code')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Не удалось удалить резервирование ключа идемпотентности', [
                'user_id' => $userId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Создает или находит системный PRN-курс, фиксирует лог со статусом 'taken'
     * и атомарно списывает дозу из физического остатка препарата в аптечке.
     */
    public function quickTake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicine_id' => 'required|integer|exists:medicines,id',
            'profile_id'  => 'required|integer|exists:profiles,id',
            'quantity'    => 'nullable|numeric|min:0.1|max:10',
            'dose'        => 'nullable|numeric|min:0.1|max:10',
            'symptom'     => 'nullable|string|max:255',
            'taken_at'    => 'nullable|date',
            'confirm_without_stock' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        $profile = Profile::findOrFail($validated['profile_id']);

        // Проверка доступа к профилю (IDOR)
        if ($profile->user_id !== $user->id) {
            return response()->json(['message' => 'Доступ к указанному профилю запрещен.'], 403);
        }

        $medicine = Medicine::with(['medicineBox.users', 'referenceMedicine'])->findOrFail($validated['medicine_id']);

        // Проверка доступа к аптечке (IDOR)
        $hasBoxAccess = false;
        if ($medicine->medicineBox) {
            if (isset($medicine->medicineBox->user_id) && $medicine->medicineBox->user_id === $user->id) {
                $hasBoxAccess = true;
            } elseif ($medicine->medicineBox->users()->where('users.id', $user->id)->exists()) {
                $hasBoxAccess = true;
            }
        }

        if (! $hasBoxAccess) {
            return response()->json(['message' => 'Доступ к аптечке запрещен.'], 403);
        }

        $dose = (float) ($validated['dose'] ?? ($validated['quantity'] ?? 1.0));
        $takenAt = ! empty($validated['taken_at']) ? Carbon::parse($validated['taken_at']) : Carbon::now();
        $symptom = $validated['symptom'] ?? 'По требованию (симптом)';
        $confirmWithoutStock = $request->boolean('confirm_without_stock');

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        $requestHash = $this->computeRequestHash($request);

        if ($idempotencyKey) {
            $existing = $this->checkIdempotency($user->id, $idempotencyKey, $requestHash);
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($profile, $validated, $dose, $takenAt, $symptom, $confirmWithoutStock, $idempotencyKey, $requestHash, $user) {
                $medicine = Medicine::lockForUpdate()->with(['referenceMedicine'])->findOrFail($validated['medicine_id']);
                $currentStock = (float) $medicine->quantity;

                // Проверка наличия физического остатка
                if ($currentStock < $dose) {
                    if (! $confirmWithoutStock) {
                        return response()->json([
                            'message' => 'Недостаточно препарата в аптечке для списания остатка. Подтвердите приём без списания.',
                            'stock_warning' => true,
                            'requires_confirmation' => true,
                            'remaining_quantity' => $currentStock,
                        ], 409);
                    }

                    // Подтверждено пользователем: фиксируем приём без списания остатка
                    $course = IntakeCourse::firstOrCreate(
                        [
                            'profile_id' => $profile->id,
                            'medicine_id' => $medicine->id,
                            'dosage_instruction' => 'По требованию (PRN)',
                        ],
                        [
                            'title' => 'По требованию: ' . ($medicine->custom_trade_name ?: ($medicine->referenceMedicine?->trade_name ?? 'Медикамент')),
                            'start_date' => Carbon::today(),
                            'end_date' => null,
                            'dosage_quantity' => $dose,
                            'notes' => 'Симптоматический приём по необходимости',
                            'is_active' => true,
                        ]
                    );

                    $log = IntakeLog::create([
                        'intake_course_id' => $course->id,
                        'intake_schedule_id' => null,
                        'medicine_id' => $medicine->id,
                        'scheduled_time' => $takenAt,
                        'taken_time' => $takenAt,
                        'quantity_taken' => $dose,
                        'deducted_quantity' => 0.000,
                        'status' => IntakeLogStatus::TAKEN,
                        'skipped_reason' => $symptom,
                    ]);

                    $responseBody = [
                        'message' => 'Приём зафиксирован без списания остатка (подтверждено пользователем)',
                        'stock_warning' => true,
                        'data' => $log->load(['medicine', 'intakeCourse.profile']),
                        'remaining_quantity' => $currentStock,
                    ];

                    $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 201, $responseBody);

                    return response()->json($responseBody, 201);
                }

                // Остатка достаточно: фиксируем курс и лог, атомарно списываем остаток
                $course = IntakeCourse::firstOrCreate(
                    [
                        'profile_id' => $profile->id,
                        'medicine_id' => $medicine->id,
                        'dosage_instruction' => 'По требованию (PRN)',
                    ],
                    [
                        'title' => 'По требованию: ' . ($medicine->custom_trade_name ?: ($medicine->referenceMedicine?->trade_name ?? 'Медикамент')),
                        'start_date' => Carbon::today(),
                        'end_date' => null,
                        'dosage_quantity' => $dose,
                        'notes' => 'Симптоматический приём по необходимости',
                        'is_active' => true,
                    ]
                );

                $log = IntakeLog::create([
                    'intake_course_id' => $course->id,
                    'intake_schedule_id' => null,
                    'medicine_id' => $medicine->id,
                    'scheduled_time' => $takenAt,
                    'taken_time' => $takenAt,
                    'quantity_taken' => $dose,
                    'deducted_quantity' => $dose,
                    'status' => IntakeLogStatus::TAKEN,
                    'skipped_reason' => $symptom,
                ]);

                DB::table('medicines')
                    ->where('id', $medicine->id)
                    ->where('quantity', '>=', $dose)
                    ->decrement('quantity', $dose);

                $medicine->refresh();
                if ($medicine->quantity <= 0) {
                    $medicine->status = MedicineStatus::FINISHED;
                    $medicine->save();
                }

                $responseData = [
                    'message' => 'Разовый приём успешно зафиксирован, остаток списан.',
                    'data' => $log->load(['medicine', 'intakeCourse.profile']),
                    'remaining_quantity' => (float) $medicine->quantity,
                ];

                $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 201, $responseData);

                return response()->json($responseData, 201);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($idempotencyKey && ($e->getCode() == 23000 || str_contains($e->getMessage(), 'idempotency_keys'))) {
                $retry = $this->checkIdempotency($user->id, $idempotencyKey, $requestHash);
                if ($retry !== null) {
                    return $retry;
                }
            }
            throw $e;
        }
    }

    /**
     * Отметка о приеме лекарства (Take).
     * Требование ТЗ:
     * 1) Менять статус лога на 'taken' и фиксировать taken_time;
     * 2) Использовать DB::transaction с пессимистической блокировкой lockForUpdate;
     * 3) Проверка идемпотентности через таблицу idempotency_keys;
     * 4) Сохранять deducted_quantity фактически списанного количества;
     * 5) Проверка физического остатка: при нехватке требовать confirm_without_stock (409)
     *    либо фиксировать приём без списания при наличии флага (200).
     */
    public function take(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        $requestHash = $this->computeRequestHash($request);

        if ($idempotencyKey) {
            $existing = $this->checkIdempotency($user->id, $idempotencyKey, $requestHash);
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($request, $id, $userProfileIds, $user, $idempotencyKey, $requestHash) {
                $intakeLog = IntakeLog::lockForUpdate()
                    ->with('intakeCourse')
                    ->findOrFail($id);

                // Проверка прав доступа: лог должен относиться к профилю текущего пользователя
                if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
                    return response()->json(['message' => 'Доступ к данному приему запрещен (IDOR).'], 403);
                }

                $medicineId = $intakeLog->medicine_id ?? $intakeLog->intakeCourse?->medicine_id;
                $medicine = $medicineId ? Medicine::lockForUpdate()->find($medicineId) : null;

                // Идемпотентность: если прием уже был зафиксирован ранее, не списывать повторно
                if ($intakeLog->status === IntakeLogStatus::TAKEN) {
                    $responseBody = [
                        'message' => 'Приём лекарства уже был зафиксирован ранее.',
                        'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                        'remaining_quantity' => (float) ($medicine?->quantity ?? 0),
                    ];
                    $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 200, $responseBody);
                    return response()->json($responseBody, 200);
                }

                $amountToDeduct = (float) ($intakeLog->quantity_taken ?: ($intakeLog->intakeCourse?->dosage_quantity ?? 1.0));
                if (! $intakeLog->quantity_taken) {
                    $intakeLog->quantity_taken = $amountToDeduct;
                }

                // Если физическое лекарство не привязано к логу или курсу
                if (! $medicine) {
                    $intakeLog->status = IntakeLogStatus::TAKEN;
                    $intakeLog->taken_time = Carbon::now();
                    $intakeLog->deducted_quantity = 0.000;
                    $intakeLog->save();

                    $responseBody = [
                        'message' => 'Приём лекарства успешно зафиксирован.',
                        'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                        'remaining_quantity' => 0.0,
                    ];
                    $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 200, $responseBody);
                    return response()->json($responseBody, 200);
                }

                $currentStock = (float) $medicine->quantity;

                // Проверка наличия физического остатка
                if ($currentStock < $amountToDeduct) {
                    // Если передан флаг confirm_without_stock:
                    if ($request->boolean('confirm_without_stock')) {
                        $intakeLog->status = IntakeLogStatus::TAKEN;
                        $intakeLog->taken_time = Carbon::now();
                        $intakeLog->deducted_quantity = 0.000;
                        $intakeLog->save();

                        $responseBody = [
                            'message' => 'Приём зафиксирован без списания остатка (подтверждено пользователем)',
                            'stock_warning' => true,
                            'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                            'remaining_quantity' => $currentStock,
                        ];
                        $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 200, $responseBody);
                        return response()->json($responseBody, 200);
                    }

                    // Если флаг confirm_without_stock НЕ передан:
                    return response()->json([
                        'message' => 'Недостаточно препарата в аптечке для списания остатка. Подтвердите приём без списания.',
                        'stock_warning' => true,
                        'requires_confirmation' => true,
                        'remaining_quantity' => $currentStock,
                    ], 409);
                }

                // Если остатка достаточно: атомарно списать остаток
                $intakeLog->status = IntakeLogStatus::TAKEN;
                $intakeLog->taken_time = Carbon::now();
                $intakeLog->deducted_quantity = $amountToDeduct;
                $intakeLog->save();

                DB::table('medicines')
                    ->where('id', $medicine->id)
                    ->where('quantity', '>=', $amountToDeduct)
                    ->decrement('quantity', $amountToDeduct);

                $medicine->refresh();
                if ($medicine->quantity <= 0) {
                    $medicine->status = MedicineStatus::FINISHED;
                    $medicine->save();
                }

                $responseBody = [
                    'message' => 'Приём лекарства успешно зафиксирован. Остаток списан.',
                    'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                    'remaining_quantity' => (float) $medicine->quantity,
                ];

                $this->recordIdempotency($user->id, $idempotencyKey, $requestHash, 200, $responseBody);

                return response()->json($responseBody, 200);
            }, 5);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($idempotencyKey && ($e->getCode() == 23000 || str_contains($e->getMessage(), 'idempotency_keys'))) {
                $retry = $this->checkIdempotency($user->id, $idempotencyKey, $requestHash);
                if ($retry !== null) {
                    return $retry;
                }
            }
            throw $e;
        }
    }

    /**
     * Пропуск приема лекарства с указанием причины.
     * Защищено DB::transaction, lockForUpdate и запретом отмены завершенных приёмов.
     */
    public function skip(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($id, $userProfileIds, $validated) {
            $intakeLog = IntakeLog::lockForUpdate()
                ->with('intakeCourse')
                ->findOrFail($id);

            if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
                return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
            }

            // Запрет перехода из состояния TAKEN: нельзя пометить пропущенным уже принятый приём
            if ($intakeLog->status === IntakeLogStatus::TAKEN) {
                return response()->json([
                    'message' => 'Невозможно пропустить уже принятый препарат. Сначала выполните отмену приёма (undo).',
                ], 409);
            }

            // Идемпотентность: если уже пропущен, возвращаем 200
            if ($intakeLog->status === IntakeLogStatus::SKIPPED) {
                return response()->json([
                    'message' => 'Приём лекарства уже отмечен как пропущенный.',
                    'data' => $intakeLog,
                ], 200);
            }

            $intakeLog->status = IntakeLogStatus::SKIPPED;
            $intakeLog->skipped_reason = $validated['reason'] ?? 'Пропущено пользователем';
            $intakeLog->save();

            return response()->json([
                'message' => 'Приём лекарства отмечен как пропущенный.',
                'data' => $intakeLog,
            ], 200);
        });
    }

    /**
     * Откладывание приёма лекарства на N минут (+15 минут по умолчанию).
     * Защищено транзакцией и запретом откладывания завершенных приёмов.
     */
    public function snooze(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ]);
        $minutes = (int) ($validated['minutes'] ?? 15);

        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        return DB::transaction(function () use ($id, $userProfileIds, $minutes) {
            $intakeLog = IntakeLog::lockForUpdate()
                ->with(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine'])
                ->findOrFail($id);

            if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
                return response()->json(['message' => 'Доступ к данному приему запрещен (IDOR).'], 403);
            }

            if ($intakeLog->status === IntakeLogStatus::TAKEN) {
                return response()->json(['message' => 'Невозможно отложить уже принятый препарат.'], 409);
            }

            if ($intakeLog->status === IntakeLogStatus::SKIPPED) {
                return response()->json(['message' => 'Невозможно отложить пропущенный приём.'], 409);
            }

            $baseTime = $intakeLog->scheduled_time->isPast() ? Carbon::now() : $intakeLog->scheduled_time;
            $intakeLog->scheduled_time = $baseTime->copy()->addMinutes($minutes);
            $intakeLog->save();

            return response()->json([
                'message' => "Приём лекарства успешно отложен на {$minutes} мин.",
                'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
            ], 200);
        });
    }

    /**
     * Сброс отложенного приёма назад к исходному времени слота (reset-snooze).
     */
    public function resetSnooze(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        return DB::transaction(function () use ($id, $userProfileIds) {
            $intakeLog = IntakeLog::lockForUpdate()
                ->with(['intakeCourse.profile.user', 'intakeSchedule'])
                ->findOrFail($id);

            if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
                return response()->json(['message' => 'Доступ к данному приему запрещен (IDOR).'], 403);
            }

            if ($intakeLog->status === IntakeLogStatus::TAKEN) {
                return response()->json(['message' => 'Невозможно отменить перенос уже принятого препарата.'], 409);
            }

            if ($intakeLog->status === IntakeLogStatus::SKIPPED) {
                return response()->json(['message' => 'Невозможно отменить перенос пропущенного приёма.'], 409);
            }

            $userTimezone = $intakeLog->intakeCourse?->profile?->user?->timezone ?? 'Europe/Moscow';
            $slotTime = $intakeLog->intakeSchedule?->time_slots[0] ?? '08:00';
            [$hour, $minute] = explode(':', $slotTime);

            $currentLocal = Carbon::parse($intakeLog->scheduled_time)->setTimezone($userTimezone);
            $restoredTime = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $currentLocal->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', (int) $hour, (int) $minute),
                $userTimezone
            )->setTimezone('UTC');

            $intakeLog->scheduled_time = $restoredTime;
            $intakeLog->save();

            return response()->json([
                'message' => 'Перенос приёма отменён, возвращено исходное время слота.',
                'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
            ], 200);
        });
    }

    /**
     * Отмена фиксации приёма лекарства (Undo).
     * Возвращает ТОЛЬКО фактически списанный остаток (deducted_quantity) физическому препарату,
     * сбрасывает статус в SCHEDULED под lockForUpdate, работает идемпотентно и с проверкой прав.
     */
    public function undo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        return DB::transaction(function () use ($user, $id, $userProfileIds) {
            $intakeLog = IntakeLog::lockForUpdate()
                ->with(['intakeCourse', 'medicine'])
                ->findOrFail($id);

            // Проверка прав доступа (IDOR)
            if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
                return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
            }

            $medicineId = $intakeLog->medicine_id ?? $intakeLog->intakeCourse?->medicine_id;
            $medicine = $medicineId ? Medicine::lockForUpdate()->find($medicineId) : null;
            $currentStock = (float) ($medicine?->quantity ?? 0.0);

            // Идемпотентность: повторный откат лога, уже находящегося в SCHEDULED, возвращает 200
            if ($intakeLog->status === IntakeLogStatus::SCHEDULED) {
                return response()->json([
                    'message' => 'Приём уже находится в статусе ожидания (scheduled).',
                    'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                    'remaining_quantity' => $currentStock,
                    'refunded_quantity' => 0.0,
                ], 200);
            }

            $refundedQuantity = 0.0;

            if ($intakeLog->status === IntakeLogStatus::TAKEN) {
                // Возвращаем строго фактически списанное количество (deducted_quantity)
                $amountToRefund = (float) ($intakeLog->deducted_quantity ?? 0.0);

                if ($amountToRefund > 0 && $medicine) {
                    DB::table('medicines')
                        ->where('id', $medicine->id)
                        ->increment('quantity', $amountToRefund);

                    $medicine->refresh();
                    if ($medicine->status === MedicineStatus::FINISHED && $medicine->quantity > 0) {
                        $medicine->status = MedicineStatus::ACTIVE;
                        $medicine->save();
                    }
                    $currentStock = (float) $medicine->quantity;
                    $refundedQuantity = $amountToRefund;
                }

                $intakeLog->status = IntakeLogStatus::SCHEDULED;
                $intakeLog->taken_time = null;
                $intakeLog->deducted_quantity = 0.000;
                $intakeLog->save();

                NotificationLog::create([
                    'user_id' => $user->id,
                    'entity_type' => IntakeLog::class,
                    'entity_id' => $intakeLog->id,
                    'title' => 'Отмена приёма лекарства',
                    'message' => "Приём препарата #{$intakeLog->id} отменён. На остаток возвращено {$refundedQuantity} ед.",
                    'type' => NotificationLogType::INTAKE_UNDO,
                    'channel' => 'system',
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                \Illuminate\Support\Facades\Log::info("IntakeLog #{$id} status TAKEN undone by user #{$user->id}. Restored {$refundedQuantity} units.");
            } elseif ($intakeLog->status === IntakeLogStatus::SKIPPED || $intakeLog->status === IntakeLogStatus::MISSED) {
                $intakeLog->status = IntakeLogStatus::SCHEDULED;
                $intakeLog->skipped_reason = null;
                $intakeLog->deducted_quantity = 0.000;
                $intakeLog->save();

                \Illuminate\Support\Facades\Log::info("IntakeLog #{$id} status {$intakeLog->status->value} undone by user #{$user->id}.");
            }

            return response()->json([
                'message' => 'Статус приёма успешно отменён, остаток препарата восстановлен.',
                'data' => $intakeLog->fresh(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine']),
                'remaining_quantity' => $currentStock,
                'refunded_quantity' => $refundedQuantity,
            ], 200);
        });
    }

    /**
     * Просмотр конкретного лога приёма.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userProfileIds = $user->profiles()->pluck('id');

        $intakeLog = IntakeLog::with(['intakeCourse.profile', 'intakeCourse.medicine', 'medicine'])->findOrFail($id);

        if (! $userProfileIds->contains($intakeLog->intakeCourse->profile_id)) {
            return response()->json(['message' => 'Доступ запрещен (IDOR).'], 403);
        }

        return response()->json([
            'data' => $intakeLog,
        ], 200);
    }
}
