<?php

namespace App\Services;

use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\Profile;
use App\Models\ReferenceMedicine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Сервис клинической безопасности медикаментозной терапии (Clinical Decision Support - CDS).
 * Разработан в рамках магистерской диссертации 09.04.04 «Программная инженерия».
 *
 * Обеспечивает превентивный аудит лекарственных назначений:
 * 1. Детекция дублирования действующих веществ (МНН) и лекарственного перекрытия.
 * 2. Выявление аллергии на активные фармацевтические субстанции (пенициллины, НПВП и др.).
 * 3. Контроль аллергий на вспомогательный состав, эксципиенты и ароматизаторы (цитрусовые, лактоза).
 * 4. Проверка клинических противопоказаний по коморбидному профилю (гастрит/язва vs НПВП, гипертензия vs деконгестанты).
 */
class MedicationSafetyService
{
    /**
     * Комплексный аудит безопасности назначения лекарственного средства для профиля.
     * Реализует fail-closed архитектуру с тремя исходами (safe, conflict, unverifiable).
     *
     * @param Profile $profile
     * @param ReferenceMedicine|Medicine $medicine
     * @return array{status: string, is_safe: bool, has_critical: bool, warnings: array<int, string>, unverified_checks: array<int, string>, disclaimer: string}
     */
    public function checkSafety(Profile $profile, ReferenceMedicine|Medicine $medicine): array
    {
        $warnings = [];
        $unverifiedChecks = [];
        $hasCritical = false;

        // Извлечение метаданных препарата
        $tradeName = $this->resolveTradeName($medicine);
        $inn = $this->resolveInn($medicine);
        $atcCode = $this->resolveAtcCode($medicine);
        $allergenTags = $this->resolveAllergenTags($medicine);
        $composition = $this->resolveComposition($medicine);

        // Проверка привязки к справочнику лекарств (для пользовательских препаратов из аптечки)
        if ($medicine instanceof Medicine && empty($medicine->reference_medicine_id)) {
            $unverifiedChecks[] = "Препарат «{$tradeName}» добавлен вручную и не привязан к справочнику лекарственных средств (отсутствуют верифицированные МНН, АТХ и состав).";
        }

        // Профиль пациента: аллергии и коморбидный статус
        $allergies = array_map('mb_strtolower', $profile->allergies ?? []);
        $chronicConditions = array_map('mb_strtolower', $profile->chronic_conditions ?? []);

        // 1. Детекция дублирования действующего вещества (МНН) по активным курсам приёма
        $overlapWarning = $this->checkSubstanceOverlap($profile, $medicine, $inn, $atcCode, $tradeName, $unverifiedChecks);
        if ($overlapWarning !== null) {
            $hasCritical = true;
            $warnings[] = $overlapWarning;
        }

        // 2. Проверка аллергических реакций на действующее вещество (Пенициллины, НПВП и др.)
        $substanceAllergies = $this->checkActiveSubstanceAllergy($allergies, $inn, $atcCode, $allergenTags, $tradeName);
        if (! empty($substanceAllergies)) {
            $hasCritical = true;
            $warnings = array_merge($warnings, $substanceAllergies);
        }

        // 3. Проверка вспомогательного состава, эксципиентов и ароматизаторов (Цитрусовые, лактоза, сахар)
        $excipientAllergies = $this->checkExcipientAllergies($allergies, $allergenTags, $composition, $tradeName);
        if (! empty($excipientAllergies['warnings'])) {
            if ($excipientAllergies['is_critical']) {
                $hasCritical = true;
            }
            $warnings = array_merge($warnings, $excipientAllergies['warnings']);
        }

        // Fail-closed проверка для эксципиентов: если у пациента есть аллергии, но у препарата пустой состав и теги
        if (! empty($allergies) && empty(trim($composition)) && empty($allergenTags)) {
            $unverifiedChecks[] = "Невозможно проверить аллергию на вспомогательные вещества (эксципиенты, красители, консерванты): у препарата «{$tradeName}» не указан подробный фармацевтический состав и аллергенные теги.";
        }

        // 4. Проверка противопоказаний по хроническим заболеваниям (Гастрит/язва vs НПВП, Гипертензия vs деконгестанты)
        $contraindications = $this->checkChronicContraindications($chronicConditions, $inn, $atcCode, $allergenTags, $composition, $tradeName);
        if (! empty($contraindications)) {
            $hasCritical = true;
            $warnings = array_merge($warnings, $contraindications);
        }

        // 5. Проверка прямых межлекарственных взаимодействий (Drug-Drug Interactions, DDI)
        $ddiResult = $this->checkDrugDrugInteractions($profile, $medicine, $inn, $atcCode, $tradeName, $unverifiedChecks);
        if (! empty($ddiResult['warnings'])) {
            $warnings = array_merge($warnings, $ddiResult['warnings']);
        }
        if ($ddiResult['has_critical']) {
            $hasCritical = true;
        }

        // Fail-closed проверка для коморбидного профиля: если есть хронические заболевания, но нет фармакологических данных
        if (! empty($chronicConditions) && empty($inn) && empty($atcCode) && empty(trim($composition)) && empty($allergenTags)) {
            $unverifiedChecks[] = "Невозможно проверить противопоказания по хроническим заболеваниям: у препарата «{$tradeName}» отсутствуют фармакологические данные (МНН, АТХ, состав).";
        }

        // Определение триады исходов: safe / conflict / unverifiable
        $status = ! empty($warnings) ? 'conflict' : (! empty($unverifiedChecks) ? 'unverifiable' : 'safe');
        $isSafe = $status === 'safe';

        return [
            'status' => $status,
            'is_safe' => $isSafe,
            'has_critical' => $hasCritical,
            'warnings' => array_values(array_unique($warnings)),
            'interactions' => $ddiResult['interactions'] ?? [],
            'unverified_checks' => array_values(array_unique($unverifiedChecks)),
            'disclaimer' => 'Автоматическая проверка взаимодействия лекарств носит информационный характер и не заменяет консультацию врача или провизора. При наличии сомнений проконсультируйтесь со специалистом.',
        ];
    }

    /**
     * Проверка на дублирование действующего вещества с уже принимаемыми препаратами.
     */
    protected function checkSubstanceOverlap(
        Profile $profile,
        ReferenceMedicine|Medicine $targetMedicine,
        string $targetInn,
        string $targetAtc,
        string $targetTradeName,
        array &$unverifiedChecks = []
    ): ?string {
        if (empty($targetInn) && empty($targetAtc)) {
            $unverifiedChecks[] = "Невозможно выполнить проверку дублирования веществ: у препарата «{$targetTradeName}» не указаны МНН (международное непатентованное наименование) и код АТХ.";
            return null;
        }

        $activeCourses = $profile->intakeCourses()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->with(['medicine.referenceMedicine'])
            ->get();

        $targetInnClean = mb_strtolower(trim($targetInn));
        $targetAtcPrefix = mb_substr(trim($targetAtc), 0, 4); // Группа АТХ (например M01A)
        $targetMedicineId = $targetMedicine instanceof Medicine ? $targetMedicine->id : null;
        $targetRefId = $targetMedicine instanceof ReferenceMedicine ? $targetMedicine->id : ($targetMedicine->reference_medicine_id ?? null);

        foreach ($activeCourses as $course) {
            $activeMed = $course->medicine;
            if (! $activeMed) {
                $unverifiedChecks[] = "Не удалось проверить пересечение с активным курсом «{$course->title}»: препарат курса не найден или был удален.";
                continue;
            }

            // Исключаем сравнение с самим собой при редактировании/повторной проверке того же медикамента
            if ($targetMedicineId && $activeMed->id === $targetMedicineId) {
                continue;
            }

            // Исключаем сравнение с эталонным препаратом, на котором основан этот же курс (self-exclusion)
            if ($targetMedicine instanceof ReferenceMedicine && $activeMed->reference_medicine_id === $targetMedicine->id) {
                continue;
            }

            $activeTradeName = $activeMed->custom_trade_name ?: ($activeMed->referenceMedicine?->trade_name ?: 'Лекарство');
            $activeInn = mb_strtolower(trim($activeMed->referenceMedicine?->inn ?? ''));
            $activeAtc = trim($activeMed->referenceMedicine?->atc_code ?? '');

            if (empty($activeInn) && empty($activeAtc)) {
                $unverifiedChecks[] = "Не удалось проверить дублирование с активным курсом «{$course->title}» («{$activeTradeName}»): отсутствуют данные МНН и АТХ.";
                continue;
            }

            // 1) Точное совпадение или пересечение МНН
            $hasInnMatch = ! empty($targetInnClean) && ! empty($activeInn) && (
                $targetInnClean === $activeInn ||
                str_contains($targetInnClean, $activeInn) ||
                str_contains($activeInn, $targetInnClean)
            );

            // 2) Совпадение группы АТХ на уровне подгруппы (например M01AE)
            $hasAtcMatch = ! empty($targetAtc) && ! empty($activeAtc) && (
                $targetAtc === $activeAtc ||
                (! empty($targetAtcPrefix) && str_starts_with($activeAtc, $targetAtcPrefix))
            );

            if ($hasInnMatch || $hasAtcMatch) {
                $matchedInn = $activeMed->referenceMedicine?->inn ?: $targetInn;
                return "Внимание: Обнаружено дублирование действующего вещества! Пациент уже принимает «{$activeTradeName}» (МНН: {$matchedInn}). Назначение «{$targetTradeName}» может привести к токсической передозировке.";
            }
        }

        return null;
    }

    /**
     * Проверка аллергии на активные вещества (пенициллины, цефалоспорины, НПВП).
     *
     * @param array<int, string> $allergies
     * @return array<int, string>
     */
    protected function checkActiveSubstanceAllergy(
        array $allergies,
        string $inn,
        string $atcCode,
        array $allergenTags,
        string $tradeName
    ): array {
        $warnings = [];
        $innLower = mb_strtolower($inn);
        $atcUpper = mb_strtoupper($atcCode);

        // Пенициллиновый ряд
        $hasPenicillinAllergy = in_array('penicillin', $allergies, true)
            || in_array('пенициллин', $allergies, true)
            || in_array('пенициллины', $allergies, true)
            || in_array('beta-lactam', $allergies, true);

        if ($hasPenicillinAllergy) {
            $isPenicillinDrug = in_array('penicillin', $allergenTags, true)
                || str_starts_with($atcUpper, 'J01C')
                || str_contains($innLower, 'амоксициллин')
                || str_contains($innLower, 'ампициллин')
                || str_contains($innLower, 'пенициллин')
                || str_contains($innLower, 'клавулановая')
                || str_contains(mb_strtolower($tradeName), 'амоксиклав')
                || str_contains(mb_strtolower($tradeName), 'аугментин');

            if ($isPenicillinDrug) {
                $warnings[] = "КРИТИЧЕСКАЯ ОПАСНОСТЬ: У пациента зафиксирована аллергия на пенициллиновый ряд! Препарат «{$tradeName}» (МНН: {$inn}) относится к пенициллиновым антибиотикам. Приём строго противопоказан из-за риска анафилаксии.";
            }
        }

        // НПВП (Нестероидные противовоспалительные)
        $hasNsaidAllergy = in_array('nsaid', $allergies, true)
            || in_array('нпвп', $allergies, true)
            || in_array('аспирин', $allergies, true);

        if ($hasNsaidAllergy) {
            $isNsaidDrug = in_array('nsaid', $allergenTags, true)
                || str_starts_with($atcUpper, 'M01A')
                || str_starts_with($atcUpper, 'N02BA')
                || str_contains($innLower, 'ибупрофен')
                || str_contains($innLower, 'кеторолак')
                || str_contains($innLower, 'диклофенак')
                || str_contains($innLower, 'ацетилсалициловая');

            if ($isNsaidDrug) {
                $warnings[] = "КРИТИЧЕСКАЯ ОПАСНОСТЬ: У пациента зафиксирована непереносимость препаратов группы НПВП! Препарат «{$tradeName}» относится к нестероидным противовоспалительным средствам.";
            }
        }

        return $warnings;
    }

    /**
     * Проверка на непереносимость вспомогательных веществ, красителей и ароматизаторов.
     *
     * @param array<int, string> $allergies
     * @param array<int, string> $allergenTags
     * @return array{is_critical: bool, warnings: array<int, string>}
     */
    protected function checkExcipientAllergies(
        array $allergies,
        array $allergenTags,
        string $composition,
        string $tradeName
    ): array {
        $warnings = [];
        $isCritical = false;
        $compLower = mb_strtolower($composition);

        // 1. Цитрусовые (апельсиновые/лимонные ароматизаторы, лимонная кислота, E330)
        $hasCitrusAllergy = in_array('citrus', $allergies, true)
            || in_array('цитрус', $allergies, true)
            || in_array('цитрусовые', $allergies, true)
            || in_array('апельсин', $allergies, true)
            || in_array('лимон', $allergies, true)
            || in_array('лимонная кислота', $allergies, true)
            || in_array('citric_acid', $allergies, true)
            || in_array('e330', $allergies, true);

        if ($hasCitrusAllergy) {
            $hasCitrusInDrug = in_array('citrus', $allergenTags, true)
                || in_array('citric_acid', $allergenTags, true)
                || in_array('e330', $allergenTags, true)
                || str_contains($compLower, 'апельсин')
                || str_contains($compLower, 'лимон')
                || str_contains($compLower, 'цитрус')
                || str_contains($compLower, 'orange')
                || str_contains($compLower, 'lemon')
                || str_contains($compLower, 'citric')
                || str_contains($compLower, 'e330')
                || str_contains($compLower, 'e-330')
                || str_contains($compLower, 'е330') // кириллическая е
                || str_contains($compLower, 'е-330');

            if ($hasCitrusInDrug) {
                $isCritical = true;
                $warnings[] = "Предостережение по аллергии: Препарат «{$tradeName}» содержит вспомогательные компоненты или ароматизаторы цитрусового ряда (лимонная кислота / E330). У пациента диагностирована аллергия на цитрусовые!";
            }
        }

        // 2. Лактоза (молочный сахар / эксципиент таблеток и капсул)
        $hasLactoseAllergy = in_array('lactose', $allergies, true)
            || in_array('лактоза', $allergies, true)
            || in_array('лактазная недостаточность', $allergies, true);

        if ($hasLactoseAllergy) {
            // Проверка на отрицания («без лактозы», «не содержит лактозу», «lactose-free»)
            $hasLactoseNegation = (bool) preg_match('/(без\s+лактоз|не\s+содержит\s+лактоз|отсутстви[ея]\s+лактоз|lactose[\s\-]?free|free\s+of\s+lactose)/iu', $composition);

            $hasLactoseInDrug = (
                in_array('lactose', $allergenTags, true)
                || str_contains($compLower, 'лактоз')
                || str_contains($compLower, 'молочный сахар')
                || str_contains($compLower, 'lactose')
            ) && ! $hasLactoseNegation;

            if ($hasLactoseInDrug) {
                $warnings[] = "Внимание: Препарат «{$tradeName}» содержит лактозу в качестве вспомогательного вещества. У пациента отмечена непереносимость лактозы (лактазная недостаточность).";
            }
        }

        // 3. Сахар / сахароза
        $hasSugarAllergy = in_array('sugar', $allergies, true)
            || in_array('сахар', $allergies, true)
            || in_array('диабет', $allergies, true);

        if ($hasSugarAllergy) {
            $hasSugarInDrug = in_array('sugar', $allergenTags, true)
                || str_contains($compLower, 'сахароза')
                || str_contains($compLower, 'глюкоза');

            if ($hasSugarInDrug) {
                $warnings[] = "Внимание: Препарат «{$tradeName}» содержит сахарозу/сахар в составе порошка или сиропа.";
            }
        }

        // 4. Глютен
        $hasGlutenAllergy = in_array('gluten', $allergies, true) || in_array('глютен', $allergies, true);
        if ($hasGlutenAllergy && (in_array('gluten', $allergenTags, true) || str_contains($compLower, 'глютен') || str_contains($compLower, 'пшеничн'))) {
            $warnings[] = "Внимание: Препарат «{$tradeName}» содержит следы глютена (пшеничный крахмал).";
        }

        // 5. Красители (азокрасители, тартразин E102, кармазин E122, диоксид титана)
        $hasDyeAllergy = in_array('dye', $allergies, true)
            || in_array('краситель', $allergies, true)
            || in_array('красители', $allergies, true)
            || in_array('tartrazine', $allergies, true);

        if ($hasDyeAllergy) {
            $hasDyeInDrug = in_array('dye', $allergenTags, true)
                || in_array('краситель', $allergenTags, true)
                || str_contains($compLower, 'красител')
                || str_contains($compLower, 'тартразин')
                || str_contains($compLower, 'e102')
                || str_contains($compLower, 'e110')
                || str_contains($compLower, 'e122');

            if ($hasDyeInDrug) {
                $warnings[] = "Внимание: Препарат «{$tradeName}» содержит синтетические красители. У пациента зафиксирована непереносимость красителей!";
            }
        }

        return [
            'is_critical' => $isCritical,
            'warnings' => $warnings,
        ];
    }

    /**
     * Проверка клинических противопоказаний по коморбидному профилю (хроническим заболеваниям).
     *
     * @param array<int, string> $conditions
     * @return array<int, string>
     */
    protected function checkChronicContraindications(
        array $conditions,
        string $inn,
        string $atcCode,
        array $allergenTags,
        string $composition,
        string $tradeName
    ): array {
        $warnings = [];
        $atcUpper = mb_strtoupper($atcCode);
        $innLower = mb_strtolower($inn);

        $isNsaid = in_array('nsaid', $allergenTags, true)
            || str_starts_with($atcUpper, 'M01A')
            || str_starts_with($atcUpper, 'N02BA')
            || str_contains($innLower, 'ибупрофен')
            || str_contains($innLower, 'кеторолак')
            || str_contains($innLower, 'ацетилсалициловая')
            || str_contains($innLower, 'диклофенак');

        // 1. Гастрит и язвенная болезнь ЖКТ vs НПВП
        $hasGastroCondition = in_array('gastritis', $conditions, true)
            || in_array('гастрит', $conditions, true)
            || in_array('ulcer', $conditions, true)
            || in_array('язва', $conditions, true)
            || in_array('язвенная болезнь', $conditions, true);

        if ($hasGastroCondition && $isNsaid) {
            $warnings[] = "КЛИНИЧЕСКОЕ ПРОТИВОПОКАЗАНИЕ: Препарат «{$tradeName}» относится к группе НПВП и обладает ульцерогенным действием на слизистую ЖКТ. У пациента в анамнезе диагностирован гастрит/язвенная болезнь. Приём может спровоцировать обострение или кровотечение.";
        }

        // 2. Бронхиальная астма vs НПВП (аспириновая триада)
        $hasAsthma = in_array('asthma', $conditions, true)
            || in_array('астма', $conditions, true)
            || in_array('бронхиальная астма', $conditions, true);

        if ($hasAsthma && $isNsaid) {
            $warnings[] = "Предостережение: При бронхиальной астме приём препаратов группы НПВП («{$tradeName}») сопряжен с риском развития бронхоспазма (аспириновая астма).";
        }

        // 3. Артериальная гипертензия vs сосудосуживающие компоненты (фенилэфрин)
        $hasHypertension = in_array('hypertension', $conditions, true)
            || in_array('гипертензия', $conditions, true)
            || in_array('гипертония', $conditions, true);

        $hasVasoconstrictor = str_contains($innLower, 'фенилэфрин')
            || str_contains($innLower, 'псевдоэфедрин')
            || str_contains($innLower, 'оксиметазолин')
            || str_contains($innLower, 'ксилометазолин')
            || str_contains($innLower, 'нафазолин')
            || str_starts_with($atcUpper, 'R01AA')
            || str_starts_with($atcUpper, 'R01AB')
            || in_array('decongestant', $allergenTags, true)
            || in_array('vasoconstrictor', $allergenTags, true)
            || str_contains(mb_strtolower($tradeName), 'називин')
            || str_contains(mb_strtolower($tradeName), 'снуп')
            || str_contains(mb_strtolower($tradeName), 'тизин')
            || str_contains(mb_strtolower($tradeName), 'отривин');

        if ($hasHypertension && $hasVasoconstrictor) {
            $warnings[] = "Клиническое предостережение: Препарат «{$tradeName}» содержит адреномиметики (фенилэфрин/оксиметазолин/ксилометазолин), вызывающие вазоконстрикцию и подъем АД у пациентов с артериальной гипертензией.";
        }

        return $warnings;
    }

    /**
     * Разрешение торгового наименования.
     */
    protected function resolveTradeName(ReferenceMedicine|Medicine $medicine): string
    {
        if ($medicine instanceof ReferenceMedicine) {
            return $medicine->trade_name;
        }

        return $medicine->custom_trade_name
            ?: ($medicine->referenceMedicine?->trade_name ?: 'Лекарственный препарат');
    }

    /**
     * Разрешение международного непатентованного наименования (МНН).
     */
    protected function resolveInn(ReferenceMedicine|Medicine $medicine): string
    {
        if ($medicine instanceof ReferenceMedicine) {
            return $medicine->inn ?? '';
        }

        return $medicine->referenceMedicine?->inn ?? '';
    }

    /**
     * Разрешение кода анатомо-терапевтическо-химической классификации (АТХ).
     */
    protected function resolveAtcCode(ReferenceMedicine|Medicine $medicine): string
    {
        if ($medicine instanceof ReferenceMedicine) {
            return $medicine->atc_code ?? '';
        }

        return $medicine->referenceMedicine?->atc_code ?? '';
    }

    /**
     * Проверка прямых межлекарственных взаимодействий (DDI) между назначаемым препаратом
     * и активными курсами терапии пациента.
     *
     * @return array{warnings: array<int, string>, has_critical: bool}
     */
    protected function checkDrugDrugInteractions(
        Profile $profile,
        ReferenceMedicine|Medicine $targetMedicine,
        string $targetInn,
        string $targetAtc,
        string $targetTradeName,
        array &$unverifiedChecks = []
    ): array {
        $warnings = [];
        $interactions = [];
        $hasCritical = false;

        $targetInnClean = mb_strtolower(trim($targetInn));
        $targetAtcClean = mb_strtoupper(trim($targetAtc));

        $activeCourses = $profile->intakeCourses()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->with(['medicine.referenceMedicine'])
            ->get();

        if ($activeCourses->isEmpty()) {
            return ['warnings' => [], 'interactions' => [], 'has_critical' => false];
        }

        if (empty($targetInnClean) && empty($targetAtcClean)) {
            $unverifiedChecks[] = "Клинический аудит межлекарственных взаимодействий (DDI) для препарата «{$targetTradeName}» не может быть гарантирован: отсутствуют верифицированные фармакологические коды МНН и АТХ.";
            return ['warnings' => [], 'interactions' => [], 'has_critical' => false];
        }

        $targetMedicineId = $targetMedicine instanceof Medicine ? $targetMedicine->id : null;

        foreach ($activeCourses as $course) {
            $activeMed = $course->medicine;
            if (! $activeMed) {
                continue;
            }

            if ($targetMedicineId && $activeMed->id === $targetMedicineId) {
                continue;
            }

            $activeTradeName = $this->resolveTradeName($activeMed);
            $activeInn = mb_strtolower(trim($this->resolveInn($activeMed)));
            $activeAtc = mb_strtoupper(trim($this->resolveAtcCode($activeMed)));

            if (empty($activeInn) && empty($activeAtc)) {
                $unverifiedChecks[] = "Клинический аудит межлекарственных взаимодействий (DDI) для препарата «{$activeTradeName}» не может быть гарантирован: отсутствуют верифицированные фармакологические коды МНН и АТХ.";
                continue;
            }

            $isNsaid = fn ($inn, $atc) => str_starts_with($atc, 'M01A')
                || in_array($inn, ['ибупрофен', 'диклофенак', 'кеторолак', 'кетопрофен', 'мелоксикам', 'нимесулид', 'индометацин', 'целекоксиб', 'ацеклофенак', 'лорноксикам']);

            $isAnticoagulant = fn ($inn, $atc) => str_starts_with($atc, 'B01')
                || in_array($inn, ['варфарин', 'ривароксабан', 'апиксабан', 'дабигатран', 'клопидогрел', 'гепарин', 'эноксапарин']);

            $isNitrate = fn ($inn, $atc) => str_starts_with($atc, 'C01DA')
                || in_array($inn, ['нитроглицерин', 'изосорбида динитрат', 'изосорбида мононитрат']);

            $isPde5 = fn ($inn, $atc) => str_starts_with($atc, 'G04BE')
                || in_array($inn, ['силденафил', 'тадалафил', 'варденафил', 'уденафил']);

            $isPotassiumSparing = fn ($inn, $atc) => str_starts_with($atc, 'C03DA') || str_starts_with($atc, 'C03D')
                || in_array($inn, ['спиронолактон', 'эплеренон']);

            $isPotassiumOrAce = fn ($inn, $atc) => str_starts_with($atc, 'A12BA') || str_starts_with($atc, 'C09A')
                || in_array($inn, ['эналаприл', 'лизиноприл', 'периндоприл', 'рамиприл', 'каптоприл', 'калия хлорид', 'калия и магния аспарагинат']);

            $isAceOrArb = fn ($inn, $atc) => str_starts_with($atc, 'C09A') || str_starts_with($atc, 'C09C')
                || in_array($inn, ['эналаприл', 'лизиноприл', 'периндоприл', 'рамиприл', 'каптоприл', 'лозартан', 'валсартан', 'кандесартан', 'телмисартан']);

            // Мощные ингибиторы CYP3A4 среди макролидов
            $isCyp3a4Macrolide = fn ($inn, $atc) => in_array($atc, ['J01FA01', 'J01FA09'], true)
                || in_array($inn, ['кларитромицин', 'эритромицин'], true);

            // Субстраты CYP3A4 среди статинов (высокий риск рабдомиолиза)
            $isCyp3a4Statin = fn ($inn, $atc) => in_array($atc, ['C10AA01', 'C10AA02', 'C10AA05'], true)
                || in_array($inn, ['аторвастатин', 'симвастатин', 'ловастатин'], true);

            // 1. НПВП + Антикоагулянты / Антиагреганты (Критический)
            if (($isNsaid($targetInnClean, $targetAtcClean) && $isAnticoagulant($activeInn, $activeAtc)) ||
                ($isAnticoagulant($targetInnClean, $targetAtcClean) && $isNsaid($activeInn, $activeAtc))) {
                $hasCritical = true;
                $msg = "Критический конфликт DDI: совместный приём НПВП («{$targetTradeName}») и антикоагулянта/антиагреганта («{$activeTradeName}») резко потенцирует риск профузных желудочно-кишечных кровотечений.";
                $warnings[] = $msg;
                $interactions[] = [
                    'target_medicine' => $targetTradeName,
                    'interacting_medicine' => $activeTradeName,
                    'severity' => 'contraindicated',
                    'source' => 'Минздрав РФ / ГРЛС / FDA',
                    'description' => $msg,
                ];
            }

            // 2. Нитраты + Ингибиторы ФДЭ-5 (Критический)
            if (($isNitrate($targetInnClean, $targetAtcClean) && $isPde5($activeInn, $activeAtc)) ||
                ($isPde5($targetInnClean, $targetAtcClean) && $isNitrate($activeInn, $activeAtc))) {
                $hasCritical = true;
                $msg = "Критический конфликт DDI: совместный приём органических нитратов («{$targetTradeName}») и ингибиторов ФДЭ-5 («{$activeTradeName}») вызывает неконтролируемую вазодилатацию, сосудистый коллапс и угрожающую жизни артериальную гипотензию.";
                $warnings[] = $msg;
                $interactions[] = [
                    'target_medicine' => $targetTradeName,
                    'interacting_medicine' => $activeTradeName,
                    'severity' => 'contraindicated',
                    'source' => 'Минздрав РФ / ГРЛС / FDA',
                    'description' => $msg,
                ];
            }

            // 3. Калийсберегающие диуретики + Препараты калия / иАПФ (Критический)
            if (($isPotassiumSparing($targetInnClean, $targetAtcClean) && $isPotassiumOrAce($activeInn, $activeAtc)) ||
                ($isPotassiumOrAce($targetInnClean, $targetAtcClean) && $isPotassiumSparing($activeInn, $activeAtc))) {
                $hasCritical = true;
                $msg = "Критический конфликт DDI: сочетание калийсберегающих диуретиков («{$targetTradeName}») с препаратами калия или ингибиторами АПФ («{$activeTradeName}») несёт высокий риск тяжёлой гиперкалиемии и фатальных нарушений сердечного ритма.";
                $warnings[] = $msg;
                $interactions[] = [
                    'target_medicine' => $targetTradeName,
                    'interacting_medicine' => $activeTradeName,
                    'severity' => 'contraindicated',
                    'source' => 'Минздрав РФ / ГРЛС / FDA',
                    'description' => $msg,
                ];
            }

            // 4. НПВП + Ингибиторы АПФ / БРА (Сартаны) (Предостережение)
            if (($isNsaid($targetInnClean, $targetAtcClean) && $isAceOrArb($activeInn, $activeAtc)) ||
                ($isAceOrArb($targetInnClean, $targetAtcClean) && $isNsaid($activeInn, $activeAtc))) {
                $msg = "Предостережение DDI: совместный приём НПВП («{$targetTradeName}») и ингибиторов АПФ/БРА («{$activeTradeName}») нивелирует антигипертензивный эффект и повышает риск нефротоксичности/острой почечной недостаточности.";
                $warnings[] = $msg;
                $interactions[] = [
                    'target_medicine' => $targetTradeName,
                    'interacting_medicine' => $activeTradeName,
                    'severity' => 'warning',
                    'source' => 'Минздрав РФ / ГРЛС / FDA',
                    'description' => $msg,
                ];
            }

            // 5. Макролиды (ингибиторы CYP3A4) + Статины (субстраты CYP3A4) (Предостережение)
            if (($isCyp3a4Macrolide($targetInnClean, $targetAtcClean) && $isCyp3a4Statin($activeInn, $activeAtc)) ||
                ($isCyp3a4Statin($targetInnClean, $targetAtcClean) && $isCyp3a4Macrolide($activeInn, $activeAtc))) {
                $msg = "Предостережение DDI (фармакокинетическое): кларитромицин/эритромицин («{$targetTradeName}») блокирует метаболизм статина («{$activeTradeName}») через изофермент CYP3A4, повышая его концентрацию в крови и риск поражения мышц (миопатия, рабдомиолиз).";
                $warnings[] = $msg;
                $interactions[] = [
                    'target_medicine' => $targetTradeName,
                    'interacting_medicine' => $activeTradeName,
                    'severity' => 'warning',
                    'source' => 'Минздрав РФ / ГРЛС / FDA',
                    'description' => $msg,
                ];
            }
        }

        return [
            'warnings' => array_values(array_unique($warnings)),
            'interactions' => $interactions,
            'has_critical' => $hasCritical,
        ];
    }

    /**
     * Разрешение списка аллергенных тегов.
     *
     * @return array<int, string>
     */
    protected function resolveAllergenTags(ReferenceMedicine|Medicine $medicine): array
    {
        $tags = [];

        if ($medicine instanceof ReferenceMedicine) {
            $tags = $medicine->allergen_tags ?: [];
        } elseif ($medicine->referenceMedicine) {
            $tags = $medicine->referenceMedicine->allergen_tags ?: [];
        }

        return is_array($tags) ? array_map('mb_strtolower', $tags) : [];
    }

    /**
     * Разрешение описания химико-фармацевтического состава.
     */
    protected function resolveComposition(ReferenceMedicine|Medicine $medicine): string
    {
        if ($medicine instanceof ReferenceMedicine) {
            return $medicine->composition ?? '';
        }

        return $medicine->referenceMedicine?->composition ?? '';
    }
}
