<?php

namespace App\Console\Commands;

use App\Models\ReferenceMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportGrlsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'medicines:import-grls 
                            {file? : Путь к файлу выгрузки реестра ГРЛС/МДЛП (JSON или CSV)}
                            {--truncate : Предварительно очистить справочник}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Импорт открытых данных Государственного реестра лекарственных средств (ГРЛС / Честный Знак)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file') ?? base_path('database/data/grls_registry_sample.json');

        if (! File::exists($filePath)) {
            $this->error("Файл реестра не найден по пути: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("==========================================================");
        $this->info("Импорт эталонного справочника лекарственных средств (ГРЛС)");
        $this->info("Файл источника: {$filePath}");
        $this->info("==========================================================");

        if ($this->option('truncate')) {
            $this->warn("Очистка таблицы reference_medicines...");
            ReferenceMedicine::truncate();
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            return $this->importFromJson($filePath);
        } elseif ($extension === 'csv') {
            return $this->importFromCsv($filePath);
        } else {
            $this->error("Неподдерживаемый формат файла (.{$extension}). Поддерживаются .json и .csv");
            return Command::FAILURE;
        }
    }

    /**
     * Импорт из JSON-структуры ГРЛС / ЕСКЛП.
     */
    protected function importFromJson(string $filePath): int
    {
        $content = File::get($filePath);
        $records = json_decode($content, true);

        if (! is_array($records)) {
            $this->error("Ошибка парсинга JSON: некорректная структура файла.");
            return Command::FAILURE;
        }

        $total = count($records);
        $this->info("Найдено записей для обработки: {$total}");

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $imported = 0;
        $updated = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            foreach ($records as $item) {
                // Базовая валидация обязательных полей ГРЛС
                if (empty($item['trade_name']) || empty($item['inn'])) {
                    $failed++;
                    $progressBar->advance();
                    continue;
                }

                $barcode = ! empty($item['barcode']) ? (string) $item['barcode'] : null;

                $data = [
                    'trade_name' => (string) $item['trade_name'],
                    'inn' => (string) $item['inn'],
                    'dosage_form' => (string) ($item['dosage_form'] ?? 'Таблетки'),
                    'dosage' => (string) ($item['dosage'] ?? 'Стандартная'),
                    'pack_size' => (int) ($item['pack_size'] ?? 10),
                    'manufacturer' => (string) ($item['manufacturer'] ?? 'Не указан'),
                    'country' => (string) ($item['country'] ?? 'Россия'),
                    'atc_code' => (string) ($item['atc_code'] ?? 'A01A'),
                    'barcode' => $barcode,
                    'is_prescription' => (bool) ($item['is_prescription'] ?? false),
                    'image_url' => $item['image_url'] ?? null,
                    'composition' => $item['composition'] ?? null,
                    'allergen_tags' => $item['allergen_tags'] ?? null,
                    'instructions' => $item['instructions'] ?? null,
                    'leaflet_pdf_url' => $item['leaflet_pdf_url'] ?? null,
                    'status' => 'active',
                ];

                // Upsert: если есть штрихкод — ищем по нему, иначе по названию и дозировке
                $existing = null;
                if ($barcode) {
                    $existing = ReferenceMedicine::where('barcode', $barcode)->first();
                }

                if (! $existing) {
                    $existing = ReferenceMedicine::where('trade_name', $data['trade_name'])
                        ->where('dosage', $data['dosage'])
                        ->where('manufacturer', $data['manufacturer'])
                        ->first();
                }

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    ReferenceMedicine::create($data);
                    $imported++;
                }

                $progressBar->advance();
            }

            DB::commit();
            $progressBar->finish();
            $this->newLine(2);

            $this->table(
                ['Метрика импорта', 'Значение'],
                [
                    ['Всего обработано', $total],
                    ['Новых записей добавлено', $imported],
                    ['Существующих обновлено', $updated],
                    ['Пропущено с ошибками', $failed],
                    ['Итого в базе reference_medicines', ReferenceMedicine::count()],
                ]
            );

            $this->info("✅ Импорт открытых данных успешно завершен!");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error("Критическая ошибка при импорте: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Потоковый импорт из CSV для больших дампов ГРЛС (20 000+ строк).
     */
    protected function importFromCsv(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error("Не удалось открыть CSV файл.");
            return Command::FAILURE;
        }

        $header = fgetcsv($handle, 4096, ';');
        $this->info("Заголовки CSV: " . implode(', ', $header));

        $count = 0;
        DB::beginTransaction();

        while (($row = fgetcsv($handle, 4096, ';')) !== false) {
            if (count($row) < 5) continue;

            ReferenceMedicine::updateOrCreate(
                ['barcode' => $row[8] ?? null],
                [
                    'trade_name' => $row[0] ?? 'Без названия',
                    'inn' => $row[1] ?? 'Не указано',
                    'dosage_form' => $row[2] ?? 'Таблетки',
                    'dosage' => $row[3] ?? 'Стандартная',
                    'pack_size' => (int) ($row[4] ?? 10),
                    'manufacturer' => $row[5] ?? 'Не указан',
                    'country' => $row[6] ?? 'Россия',
                    'atc_code' => $row[7] ?? 'A01A',
                    'is_prescription' => false,
                    'status' => 'active',
                ]
            );
            $count++;
        }

        fclose($handle);
        DB::commit();

        $this->info("✅ Успешно импортировано {$count} строк из CSV.");
        return Command::SUCCESS;
    }
}
