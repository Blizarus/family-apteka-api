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
     *
     * @param Profile $profile
     * @param ReferenceMedicine|Medicine $medicine
     * @return array{is_safe: bool, has_critical: bool, warnings: array<int, string>}
     */
    public function checkSafety(Profile $profile, ReferenceMedicine|Medicine $medicine): array
    {
        $warnings = [];
        $hasCritical = false;

        // Извлечение метаданных препарата
        $tradeName = $this->resolveTradeName($medicine);
        $inn = $this->resolveInn($medicine);
        $atcCode = $this->resolveAtcCode($medicine);
        $allergenTags = $this->resolveAllergenTags($medicine);
        $composition = $this->resolveComposition($medicine);

        // Профиль пациента: аллергии и коморбидный статус
        $allergies = array_map('mb_strtolower', $profile->allergies ?? []);
        $chronicConditions = array_map('mb_strtolower', $profile->chronic_conditions ?? []);

        // 1. Детекция дублирования действующего вещества (МНН) по активным курсам приёма
        $overlapWarning = $this->checkSubstanceOverlap($profile, $medicine, $inn, $atcCode, $tradeName);
        if ($overlapWarning !== null) {
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

        // 4. Проверка противопоказаний по хроническим заболеваниям (Гастрит/язва vs НПВП, Гипертензия vs деконгестанты)
        $contraindications = $this->checkChronicContraindications($chronicConditions, $inn, $atcCode, $allergenTags, $composition, $tradeName);
        if (! empty($contraindications)) {
            $hasCritical = true;
            $warnings = array_merge($warnings, $contraindications);
        }

        return [
            'is_safe' => count($warnings) === 0,
            'has_critical' => $hasCritical,
            'warnings' => array_values(array_unique($warnings)),
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
        string $targetTradeName
    ): ?string {
        if (empty($targetInn) && empty($targetAtc)) {
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

        foreach ($activeCourses as $course) {
            $activeMed = $course->medicine;
            if (! $activeMed) {
                continue;
            }

            // Исключаем сравнение с самим собой при редактировании/повторном назначении того же курса
            if ($targetMedicineId && $activeMed->id === $targetMedicineId) {
                continue;
            }

            $activeTradeName = $activeMed->custom_trade_name ?: ($activeMed->referenceMedicine?->trade_name ?: 'Лекарство');
            $activeInn = mb_strtolower(trim($activeMed->referenceMedicine?->inn ?? ''));
            $activeAtc = trim($activeMed->referenceMedicine?->atc_code ?? '');

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

        // 1. Цитрусовые (апельсиновые/лимонные ароматизаторы, лимонная кислота)
        $hasCitrusAllergy = in_array('citrus', $allergies, true)
            || in_array('цитрус', $allergies, true)
            || in_array('цитрусовые', $allergies, true)
            || in_array('апельсин', $allergies, true);

        if ($hasCitrusAllergy) {
            $hasCitrusInDrug = in_array('citrus', $allergenTags, true)
                || in_array('citric_acid', $allergenTags, true)
                || str_contains($compLower, 'апельсин')
                || str_contains($compLower, 'лимон')
                || str_contains($compLower, 'цитрус')
                || str_contains($compLower, 'orange')
                || str_contains($compLower, 'lemon');

            if ($hasCitrusInDrug) {
                $isCritical = true;
                $warnings[] = "Предостережение по аллергии: Препарат «{$tradeName}» содержит вспомогательные компоненты или ароматизаторы цитрусового ряда. У пациента диагностирована аллергия на цитрусовые!";
            }
        }

        // 2. Лактоза (молочный сахар / эксципиент таблеток и капсул)
        $hasLactoseAllergy = in_array('lactose', $allergies, true)
            || in_array('лактоза', $allergies, true)
            || in_array('лактазная недостаточность', $allergies, true);

        if ($hasLactoseAllergy) {
            $hasLactoseInDrug = in_array('lactose', $allergenTags, true)
                || str_contains($compLower, 'лактоз')
                || str_contains($compLower, 'молочный сахар')
                || str_contains($compLower, 'lactose');

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
