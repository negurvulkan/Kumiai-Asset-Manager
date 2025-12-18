<?php
require_once __DIR__ . '/../classification.php';
require_once __DIR__ . '/ai_prepass.php';
require_once __DIR__ . '/prepass_scoring.php';

class EntityCandidateService
{
    public function rankEntityTypesFromObservation(array $entityTypes, array $visual, array $prepassFeatures = [], array $priors = []): array
    {
        if (empty($priors)) {
            $priors = (new PrepassScoringService())->derivePriors($prepassFeatures ?: AiPrepassService::emptyPrepassResult(), true);
        }

        $flags = $this->deriveObservationFlags($visual, $prepassFeatures);
        $candidates = [];
        $excluded = [];

        foreach ($entityTypes as $type) {
            $typeId = (int)($type['id'] ?? 0);
            $typeName = trim((string)($type['name'] ?? ''));
            if ($typeId <= 0 || $typeName === '') {
                continue;
            }

            $typeKey = entity_type_key($typeName);

            if ($flags['dominant'] === 'environment' && in_array($typeKey, ['character', 'creature'], true)) {
                $excluded[] = [
                    'type_id' => $typeId,
                    'name' => $typeName,
                    'reason' => 'Reines Environment erkannt – Typ passt nicht.',
                ];
                continue;
            }

            if ($flags['dominant'] === 'object' && in_array($typeKey, ['character', 'location', 'scene'], true) && !$flags['hasHumans']) {
                $excluded[] = [
                    'type_id' => $typeId,
                    'name' => $typeName,
                    'reason' => 'Objektfokus – Menschen/Environment fehlen.',
                ];
                continue;
            }

            $score = (float)($priors[$typeKey] ?? 0.1);
            $reasons = ['Soft-Prior: ' . number_format($score, 2)];

            if ($flags['hasHumans'] && $typeKey === 'character') {
                $score += 0.35;
                $reasons[] = 'Person erkannt → Character wahrscheinlicher.';
            }

            if ($flags['hasAnimals'] && in_array($typeKey, ['creature', 'character'], true)) {
                $score += 0.25;
                $reasons[] = 'Tier/Fantasy erkannt → Creature/Character plausibel.';
            }

            if ($flags['hasEnvironment'] && in_array($typeKey, ['location', 'scene', 'background'], true)) {
                $score += 0.25;
                $reasons[] = 'Sichtbare Umgebung → Location/Scene plausibel.';
            }

            if ($flags['dominant'] === 'object' && in_array($typeKey, ['prop', 'item', 'project_custom'], true)) {
                $score += 0.25;
                $reasons[] = 'Objektfokus → Props/Items bevorzugt.';
            }

            if ($flags['dominant'] === 'mixed' && $typeKey === 'scene') {
                $score += 0.15;
                $reasons[] = 'Gemischtes Motiv → Szene wahrscheinlich.';
            }

            if (!$flags['hasEnvironment'] && in_array($typeKey, ['location', 'scene', 'background'], true)) {
                $score *= 0.6;
                $reasons[] = 'Keine Umgebung sichtbar → abgewertet.';
            }

            $score = $this->clamp01($score);
            if ($score <= 0.0) {
                continue;
            }

            $candidates[] = [
                'type_id' => $typeId,
                'name' => $typeName,
                'type_key' => $typeKey,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'candidates' => $candidates,
            'excluded' => $excluded,
            'flags' => $flags,
        ];
    }

    public function rankEntityTypes(array $entityTypes, array $prepassFeatures, array $priors = []): array
    {
        if (empty($priors)) {
            $priors = (new PrepassScoringService())->derivePriors($prepassFeatures, true);
        }

        $flags = $this->deriveFlags($prepassFeatures);
        $candidates = [];
        $excluded = [];

        foreach ($entityTypes as $type) {
            $typeId = (int)($type['id'] ?? 0);
            $typeName = trim((string)($type['name'] ?? ''));
            if ($typeId <= 0 || $typeName === '') {
                continue;
            }

            $typeKey = entity_type_key($typeName);

            if (!$flags['hasAnimals'] && $typeKey === 'creature') {
                $excluded[] = [
                    'type_id' => $typeId,
                    'name' => $typeName,
                    'reason' => 'Kein Tier im Bild erkannt (Prepass).',
                ];
                continue;
            }

            $score = (float)($priors[$typeKey] ?? 0.1);
            $reasons = ['Soft-Prior: ' . number_format($score, 2)];

            if ($flags['hasHumans'] && $typeKey === 'character') {
                $score += 0.2;
                $reasons[] = 'Mensch erkannt → Charaktere plausibler.';
            } elseif (!$flags['hasHumans'] && $typeKey === 'character') {
                $score *= 0.6;
                $reasons[] = 'Keine Menschen erkannt → Gewichtung reduziert.';
            }

            if ($flags['hasEnvironment'] && in_array($typeKey, ['location', 'scene', 'background'], true)) {
                $score += 0.15;
                $reasons[] = 'Umgebung sichtbar → Locations/Scenes plausibler.';
            } elseif (!$flags['hasEnvironment'] && in_array($typeKey, ['location', 'scene', 'background'], true)) {
                $score *= 0.6;
                $reasons[] = 'Kein Environment → Gewichtung reduziert.';
            }

            if ($flags['hasObjects'] && in_array($typeKey, ['prop', 'item', 'project_custom'], true)) {
                $score += 0.1;
                $reasons[] = 'Objekte erkannt → Props/Items plausibler.';
            }

            if ($flags['isPlainBackground'] && in_array($typeKey, ['location', 'scene', 'background'], true)) {
                $score *= 0.75;
                $reasons[] = 'Freigestellter Hintergrund → Szenen/Hintergründe leicht abgewertet.';
            }

            $score = $this->clamp01($score);
            if ($score <= 0.0) {
                continue;
            }

            $candidates[] = [
                'type_id' => $typeId,
                'name' => $typeName,
                'type_key' => $typeKey,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return [
            'candidates' => $candidates,
            'excluded' => $excluded,
            'flags' => $flags,
        ];
    }

    private function deriveFlags(array $features): array
    {
        $subjects = $features['subjects_present'] ?? [];
        $counts = $features['counts'] ?? [];
        $background = $features['background_type'] ?? 'unknown';

        return [
            'hasHumans' => in_array('human', $subjects, true) || (int)($counts['humans'] ?? 0) > 0 || !empty($features['human_attributes']['present']),
            'hasAnimals' => in_array('animal', $subjects, true) || (int)($counts['animals'] ?? 0) > 0,
            'hasObjects' => in_array('object', $subjects, true) || (int)($counts['objects'] ?? 0) > 0,
            'hasEnvironment' => in_array('environment', $subjects, true) || $background === 'environment',
            'isPlainBackground' => in_array($background, ['plain', 'transparent'], true),
        ];
    }

    private function deriveObservationFlags(array $visual, array $prepass): array
    {
        $subject = strtolower((string)($visual['subject_overview'] ?? 'unknown'));
        $living = strtolower((string)($visual['living_kind'] ?? 'unknown'));
        $keywords = array_map('strtolower', $visual['subject_keywords'] ?? []);

        $dominant = 'unknown';
        if (in_array($subject, ['person', 'people', 'human', 'portrait'], true)) {
            $dominant = 'person';
        } elseif (in_array($subject, ['animal', 'creature'], true)) {
            $dominant = 'animal';
        } elseif (in_array($subject, ['object', 'item', 'prop'], true)) {
            $dominant = 'object';
        } elseif (in_array($subject, ['environment', 'location', 'scene', 'background'], true)) {
            $dominant = 'environment';
        } elseif ($subject !== 'unknown') {
            $dominant = $subject;
        }

        if ($dominant === 'unknown' && !empty($keywords)) {
            if (array_intersect($keywords, ['human', 'person', 'people'])) {
                $dominant = 'person';
            } elseif (array_intersect($keywords, ['animal', 'horse', 'dog', 'cat'])) {
                $dominant = 'animal';
            }
        }

        $prepassFlags = $this->deriveFlags($prepass ?: AiPrepassService::emptyPrepassResult());

        return [
            'hasHumans' => $prepassFlags['hasHumans'] || in_array($living, ['human'], true) || $dominant === 'person',
            'hasAnimals' => $prepassFlags['hasAnimals'] || in_array($living, ['animal', 'fantasy'], true) || $dominant === 'animal',
            'hasObjects' => $prepassFlags['hasObjects'] || $dominant === 'object',
            'hasEnvironment' => $prepassFlags['hasEnvironment'] || $dominant === 'environment',
            'isPlainBackground' => $prepassFlags['isPlainBackground'],
            'dominant' => $dominant ?: 'unknown',
        ];
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
