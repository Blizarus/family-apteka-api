<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReencryptPdnCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:re-encrypt-pdn 
                           {--dry-run : Просканировать базу и вывести отчет без изменения данных} 
                           {--force : Выполнить операцию без интерактивного подтверждения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Шифрование открытых исторических данных ПДн (AES-256) и валидация целостности шифротекстов';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun) {
            $this->warn('Режим [DRY-RUN]: сканирование базы без изменения данных.');
        } elseif (! $force && ! $this->confirm('Вы уверены, что хотите выполнить шифрование открытых ПДн в базе данных?')) {
            $this->info('Операция отменена пользователем.');
            return 0;
        }

        // Конфигурация сканируемых таблиц и их полей
        $targets = [
            'medicines' => [
                ['field' => 'custom_trade_name', 'type' => 'string'],
                ['field' => 'notes', 'type' => 'string'],
            ],
            'profiles' => [
                ['field' => 'notes', 'type' => 'string'],
                ['field' => 'allergies', 'type' => 'array'],
                ['field' => 'chronic_conditions', 'type' => 'array'],
            ],
            'intake_courses' => [
                ['field' => 'notes', 'type' => 'string'],
            ],
        ];

        $summaryTable = [];
        $totalMigrated = 0;
        $totalErrors = 0;

        foreach ($targets as $table => $fields) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            foreach ($fields as $fieldConfig) {
                $field = $fieldConfig['field'];
                $type = $fieldConfig['type'];

                if (! DB::getSchemaBuilder()->hasColumn($table, $field)) {
                    continue;
                }

                $checked = 0;
                $alreadyEncrypted = 0;
                $migrated = 0;
                $errors = 0;

                // Читаем сырые данные напрямую через DB::table во избежание DecryptException в Eloquent
                $rows = DB::table($table)->select('id', $field)->get();

                foreach ($rows as $row) {
                    $rawVal = $row->$field;

                    if ($rawVal === null || $rawVal === '') {
                        continue;
                    }

                    $checked++;

                    // 1. Проверяем, зашифровано ли значение уже
                    $isEncrypted = false;
                    try {
                        Crypt::decryptString((string) $rawVal);
                        $isEncrypted = true;
                    } catch (DecryptException $e) {
                        $isEncrypted = false;
                    } catch (Throwable $e) {
                        $isEncrypted = false;
                    }

                    if ($isEncrypted) {
                        $alreadyEncrypted++;
                        continue;
                    }

                    // 2. Значение является открытым текстом (legacy plaintext)
                    try {
                        if ($type === 'array') {
                            $arrayData = [];
                            if (is_array($rawVal)) {
                                $arrayData = $rawVal;
                            } else {
                                $decoded = json_decode((string) $rawVal, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $arrayData = $decoded;
                                } elseif (is_string($rawVal) && trim($rawVal) !== '') {
                                    $arrayData = array_values(array_filter(array_map('trim', explode(',', $rawVal))));
                                }
                            }
                            $payload = json_encode($arrayData, JSON_UNESCAPED_UNICODE);
                            $encrypted = Crypt::encryptString($payload);
                        } else {
                            $encrypted = Crypt::encryptString((string) $rawVal);
                        }

                        if (! $dryRun) {
                            DB::table($table)->where('id', $row->id)->update([
                                $field => $encrypted,
                            ]);
                        }

                        $migrated++;
                        $totalMigrated++;
                    } catch (Throwable $e) {
                        $errors++;
                        $totalErrors++;
                        $this->error("Ошибка обработки {$table}.{$field} (id={$row->id}): " . $e->getMessage());
                    }
                }

                $summaryTable[] = [
                    'table_field' => "{$table}.{$field}",
                    'checked' => $checked,
                    'valid' => $alreadyEncrypted,
                    'migrated' => $dryRun ? "{$migrated} (требует)" : $migrated,
                    'errors' => $errors,
                ];
            }
        }

        $this->table(
            ['Таблица / Поле', 'Всего записей', 'Уже зашифровано', $dryRun ? 'Требует миграции' : 'Мигрировано', 'Ошибок'],
            $summaryTable
        );

        if ($dryRun) {
            $this->info("[DRY-RUN] Сканирование завершено. Требует шифрования записей: {$totalMigrated}. Изменения в БД не вносились.");
        } else {
            $this->info("[OK] Миграция шифрования ПДн (AES-256) успешно завершена. Зашифровано записей: {$totalMigrated}. Ошибок: {$totalErrors}.");
        }

        return $totalErrors === 0 ? 0 : 1;
    }
}
