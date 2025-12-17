<?php

class PrepassScoringService
{
    public function derivePriors(array $prepass, bool $normalize = true): array
    {
        $priors = [
            'character' => 0.0,
            'location' => 0.0,
            'scene' => 0.0,
            'prop' => 0.0,
            'effect' => 0.0,
        ];

        $primary = $prepass['primary_subject'] ?? 'unknown';
        $subjects = $prepass['subjects_present'] ?? [];
        $counts = $prepass['counts'] ?? [];
        $notes = $prepass['notes'] ?? [];
        $imageKind = $prepass['image_kind'] ?? 'unknown';
        $background = $prepass['background_type'] ?? 'unknown';

        if ($primary === 'human') {
            $priors['character'] += 0.45;
        }

        if ($background === 'plain' || $background === 'transparent') {
            $priors['location'] = max(0.0, $priors['location'] - 0.25);
        }

        $hasEnvironment = in_array('environment', $subjects, true);
        $humanCount = max(0, (int)($counts['humans'] ?? 0));

        if ($hasEnvironment && $humanCount === 0) {
            $priors['location'] += 0.40;
        }

        if ($hasEnvironment && $humanCount > 0) {
            $priors['scene'] += 0.35;
        }

        $isCharacterSheet = in_array($imageKind, ['reference_sheet', 'lineart', 'manga'], true);
        if ($isCharacterSheet && !empty($notes['is_single_character_fullbody'])) {
            $priors['character'] += 0.20;
        }

        $priors = array_map(fn($value) => min(1.0, max(0.0, (float)$value)), $priors);

        if ($normalize) {
            $sum = array_sum($priors);
            if ($sum > 1.0) {
                foreach ($priors as $key => $value) {
                    $priors[$key] = $value / $sum;
                }
            }
        }

        return $priors;
    }
}
