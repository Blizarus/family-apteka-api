<?php

namespace App\Console\Commands;

use App\MedicineStatus;
use App\NotificationLogType;
use App\Models\Medicine;
use App\Models\NotificationLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckMedicineExpiryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'medicines:check-expiry';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка сроков годности лекарственных препаратов (включая FEFO-Opened), минимальных остатков и рассылка уведомлений';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();
        $criticalDate = $today->copy()->addDays(30);

        $medicines = Medicine::with(['medicineBox.users', 'referenceMedicine'])->get();
        $scannedCount = $medicines->count();
        $expiredCount = 0;
        $expiringCount = 0;
        $lowStockCount = 0;

        $rows = [];

        $medicineIds = $medicines->pluck('id')->all();

        // Оптимизация N+1: пакетная предвыборка уведомлений за последние 7 дней (для антиспама)
        $recentNotifications = NotificationLog::where('entity_type', Medicine::class)
            ->whereIn('entity_id', $medicineIds)
            ->where('sent_at', '>=', Carbon::now()->subDays(7))
            ->get()
            ->groupBy(function ($notif) {
                $typeStr = $notif->type instanceof NotificationLogType ? $notif->type->value : (string) $notif->type;
                return "{$notif->entity_id}_{$typeStr}_{$notif->user_id}";
            });

        // Пакетная предвыборка всех уведомлений (для эмуляции firstOrCreate без N+1 запросов)
        $allExistingNotifications = NotificationLog::where('entity_type', Medicine::class)
            ->whereIn('entity_id', $medicineIds)
            ->get()
            ->groupBy(function ($notif) {
                $typeStr = $notif->type instanceof NotificationLogType ? $notif->type->value : (string) $notif->type;
                return "{$notif->entity_id}_{$typeStr}_{$notif->user_id}";
            });

        $hasRecentNotification = function (int $medId, string $typeVal, int $userId) use ($recentNotifications): bool {
            return $recentNotifications->has("{$medId}_{$typeVal}_{$userId}");
        };

        $hasExistingNotification = function (int $medId, string $typeVal, int $userId) use ($allExistingNotifications): bool {
            return $allExistingNotifications->has("{$medId}_{$typeVal}_{$userId}");
        };

        $recordNotification = function (array $attributes) use ($recentNotifications, $allExistingNotifications) {
            $notif = NotificationLog::create($attributes);
            $typeStr = $notif->type instanceof NotificationLogType ? $notif->type->value : (string) $notif->type;
            $key = "{$notif->entity_id}_{$typeStr}_{$notif->user_id}";
            $recentNotifications->put($key, collect([$notif]));
            $allExistingNotifications->put($key, collect([$notif]));
            return $notif;
        };

        foreach ($medicines as $medicine) {
            $tradeName = $medicine->custom_trade_name ?: ($medicine->referenceMedicine?->trade_name ?? 'Без названия');

            // Находим владельца аптечки для логирования уведомления
            $owner = $medicine->medicineBox?->users
                ?->first(fn ($u) => ($u->pivot?->role?->value ?? (string) $u->pivot?->role) === 'owner')
                ?? $medicine->medicineBox?->users?->first();

            $ownerId = $owner?->id;
            $userTz = $owner?->timezone ?: config('app.timezone', 'UTC');
            $today = Carbon::now($userTz)->startOfDay();
            $criticalDate = $today->copy()->addDays(30);

            $expDateStr = $medicine->expiration_date instanceof Carbon
                ? $medicine->expiration_date->toDateString()
                : (string) $medicine->expiration_date;
            $expDate = Carbon::parse($expDateStr, $userTz)->startOfDay();
            $isAlreadyExpired = false;

            // 1. Модель FEFO-Opened: проверка срока годности после вскрытия
            $daysAfterOpening = $medicine->days_after_opening ?? $medicine->referenceMedicine?->days_after_opening;

            if ($medicine->opened_at && $daysAfterOpening !== null) {
                $openedDateStr = $medicine->opened_at instanceof Carbon
                    ? $medicine->opened_at->toDateString()
                    : (string) $medicine->opened_at;
                $openedDate = Carbon::parse($openedDateStr, $userTz)->startOfDay();
                $openedExpiry = $openedDate->copy()->addDays((int) $daysAfterOpening);

                if ($today->greaterThanOrEqualTo($openedExpiry)) {
                    $expiredCount++;
                    $isAlreadyExpired = true;

                    if ($medicine->status !== MedicineStatus::EXPIRED && $medicine->status !== MedicineStatus::FINISHED) {
                        $medicine->status = MedicineStatus::EXPIRED;
                        $medicine->save();
                    }

                    if ($ownerId) {
                        if (! $hasRecentNotification($medicine->id, NotificationLogType::OPENED_EXPIRED->value, $ownerId)) {
                            $recordNotification([
                                'entity_type' => Medicine::class,
                                'entity_id' => $medicine->id,
                                'type' => NotificationLogType::OPENED_EXPIRED,
                                'user_id' => $ownerId,
                                'sent_at' => Carbon::now(),
                                'message' => "Истёк срок годности после вскрытия препарата {$tradeName} (вскрыт {$openedDate->format('d.m.Y')}, срок хранения {$daysAfterOpening} дн.)",
                            ]);
                        }
                    }

                    $rows[] = [
                        $medicine->id,
                        $tradeName,
                        $medicine->medicineBox?->name ?? '—',
                        $openedExpiry->format('Y-m-d') . ' (вскрыт)',
                        'OPENED_EXPIRED',
                    ];
                }
            }

            // 2. Стандартный срок годности (если препарат еще не признан просроченным по вскрытию)
            if (! $isAlreadyExpired) {
                $effExpiry = $medicine->effective_expiration_date;
                $effectiveDate = $effExpiry
                    ? Carbon::parse($effExpiry instanceof Carbon ? $effExpiry->toDateString() : (string) $effExpiry, $userTz)->startOfDay()
                    : $expDate;

                if ($effectiveDate->lessThan($today)) {
                    $expiredCount++;
                    if ($medicine->status !== MedicineStatus::EXPIRED && $medicine->status !== MedicineStatus::FINISHED) {
                        $medicine->status = MedicineStatus::EXPIRED;
                        $medicine->save();
                    }

                    if ($ownerId) {
                        if (! $hasExistingNotification($medicine->id, NotificationLogType::EXPIRY_CRITICAL->value, $ownerId)) {
                            $recordNotification([
                                'entity_type' => Medicine::class,
                                'entity_id' => $medicine->id,
                                'type' => NotificationLogType::EXPIRY_CRITICAL,
                                'user_id' => $ownerId,
                                'sent_at' => Carbon::now(),
                                'message' => "Истёк срок годности препарата {$tradeName} ({$effectiveDate->format('d.m.Y')})",
                            ]);
                        }
                    }

                    $rows[] = [
                        $medicine->id,
                        $tradeName,
                        $medicine->medicineBox?->name ?? '—',
                        $effectiveDate->format('Y-m-d'),
                        'EXPIRED',
                    ];
                } elseif ($effectiveDate->lessThanOrEqualTo($criticalDate)) {
                    $expiringCount++;
                    if ($medicine->status !== MedicineStatus::EXPIRING && $medicine->status !== MedicineStatus::FINISHED) {
                        $medicine->status = MedicineStatus::EXPIRING;
                        $medicine->save();
                    }

                    if ($ownerId) {
                        $daysLeft = $today->diffInDays($effectiveDate, false);
                        $notifType = ($daysLeft <= 7)
                            ? NotificationLogType::EXPIRY_WARNING_7D
                            : NotificationLogType::EXPIRY_WARNING_30D;

                        if (! $hasExistingNotification($medicine->id, $notifType->value, $ownerId)) {
                            $recordNotification([
                                'entity_type' => Medicine::class,
                                'entity_id' => $medicine->id,
                                'type' => $notifType,
                                'user_id' => $ownerId,
                                'sent_at' => Carbon::now(),
                                'message' => "Истекает срок годности препарата {$tradeName} через {$daysLeft} дн. ({$effectiveDate->format('d.m.Y')})",
                            ]);
                        }
                    }

                    $rows[] = [
                        $medicine->id,
                        $tradeName,
                        $medicine->medicineBox?->name ?? '—',
                        $effectiveDate->format('Y-m-d'),
                        'EXPIRING',
                    ];
                }
            }

            // 3. Логика Low Stock (контроль остатка для активных препаратов)
            $threshold = (float) ($medicine->low_stock_threshold ?? 2.0);
            $currentQty = (float) $medicine->quantity;

            if ($currentQty <= $threshold && $medicine->status === MedicineStatus::ACTIVE) {
                if ($ownerId) {
                    if (! $hasRecentNotification($medicine->id, NotificationLogType::LOW_STOCK->value, $ownerId)) {
                        $recordNotification([
                            'entity_type' => Medicine::class,
                            'entity_id' => $medicine->id,
                            'type' => NotificationLogType::LOW_STOCK,
                            'user_id' => $ownerId,
                            'sent_at' => Carbon::now(),
                            'message' => "Заканчивается препарат {$tradeName}, осталось {$currentQty}",
                        ]);
                        $lowStockCount++;

                        $rows[] = [
                            $medicine->id,
                            $tradeName,
                            $medicine->medicineBox?->name ?? '—',
                            "Остаток: {$currentQty} (порог: {$threshold})",
                            'LOW_STOCK',
                        ];
                    }
                }
            }
        }

        if (! empty($rows)) {
            $this->table(['ID', 'Препарат', 'Аптечка', 'Срок / Остаток', 'Статус'], $rows);
        }

        $summary = "[OK] Scanned {$scannedCount} medicines. Found {$expiredCount} expired, {$expiringCount} expiring.";
        if ($lowStockCount > 0) {
            $summary .= " Found {$lowStockCount} low stock.";
        }
        $this->info($summary);

        return Command::SUCCESS;
    }
}
