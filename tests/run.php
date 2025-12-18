<?php
require_once __DIR__ . '/../includes/services/prepass_scoring.php';
require_once __DIR__ . '/../includes/services/ai_prepass.php';
require_once __DIR__ . '/../includes/services/entity_candidates.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = [
    'priors_rules' => function () {
        $features = [
            'primary_subject' => 'human',
            'subjects_present' => ['human', 'environment'],
            'counts' => ['humans' => 1, 'animals' => 0, 'objects' => 0],
            'human_attributes' => [
                'present' => true,
                'apparent_age' => 'adult',
                'gender_presentation' => 'female',
            ],
            'image_kind' => 'manga',
            'background_type' => 'plain',
            'notes' => [
                'is_single_character_fullbody' => true,
                'is_scene_establishing_shot' => false,
                'contains_multiple_panels' => false,
            ],
            'free_caption' => 'isolated manga character on white',
            'confidence' => ['overall' => 0.8, 'primary_subject' => 0.9],
        ];
        $priors = (new PrepassScoringService())->derivePriors($features, true);

        ensure(abs($priors['character'] - 0.65) < 0.001, 'Character prior sollte 0.65 sein');
        ensure(abs($priors['location'] - 0.0) < 0.0001, 'Location prior sollte durch plain Background 0 sein');
        ensure(abs($priors['scene'] - 0.35) < 0.001, 'Scene prior sollte 0.35 sein');
    },
    'normalize_prepass_fallback' => function () {
        $raw = [
            'primary_subject' => 'invalid',
            'subjects_present' => ['logo', 'unknown', 'human'],
            'counts' => ['humans' => -1, 'animals' => 2, 'objects' => '3'],
            'human_attributes' => ['present' => 'yes', 'apparent_age' => 'elderly', 'gender_presentation' => 'none'],
            'image_kind' => 'sketch',
            'background_type' => 'invalid',
            'notes' => ['is_single_character_fullbody' => null],
            'free_caption' => null,
            'confidence' => ['overall' => 1.5, 'primary_subject' => -0.2],
        ];

        $normalized = AiPrepassService::normalizePrepassResult($raw);

        ensure($normalized['primary_subject'] === 'unknown', 'Primary Subject fällt auf unknown zurück');
        ensure($normalized['counts']['humans'] === 0, 'Negative Humans werden auf 0 gesetzt');
        ensure($normalized['counts']['objects'] === 3, 'Objects werden auf int gecastet');
        ensure($normalized['human_attributes']['apparent_age'] === 'unknown', 'Ungültiges Age -> unknown');
        ensure($normalized['background_type'] === 'unknown', 'Invalid Background -> unknown');
        ensure(abs($normalized['confidence']['overall'] - 1.0) < 0.001, 'Confidence wird auf 1 begrenzt');
        ensure(in_array('human', $normalized['subjects_present'], true), 'Gültige Subjects bleiben erhalten');
    },
    'entity_type_candidates' => function () {
        $features = [
            'primary_subject' => 'human',
            'subjects_present' => ['human', 'environment'],
            'counts' => ['humans' => 1, 'animals' => 0, 'objects' => 0],
            'human_attributes' => ['present' => true, 'apparent_age' => 'adult', 'gender_presentation' => 'female'],
            'image_kind' => 'manga',
            'background_type' => 'plain',
            'notes' => ['is_single_character_fullbody' => true, 'is_scene_establishing_shot' => false, 'contains_multiple_panels' => false],
            'free_caption' => 'isolated manga character on white',
            'confidence' => ['overall' => 0.8, 'primary_subject' => 0.9],
        ];
        $priors = (new PrepassScoringService())->derivePriors($features, true);
        $service = new EntityCandidateService();

        $result = $service->rankEntityTypes(
            [
                ['id' => 1, 'name' => 'Character'],
                ['id' => 2, 'name' => 'Creature'],
                ['id' => 3, 'name' => 'Location'],
            ],
            $features,
            $priors
        );

        ensure(count($result['excluded']) === 1 && (int)$result['excluded'][0]['type_id'] === 2, 'Creature wird ausgeschlossen, wenn kein Tier erkennbar ist');
        ensure(!empty($result['candidates']), 'Es sollten Kandidaten geliefert werden');
        ensure((int)$result['candidates'][0]['type_id'] === 1, 'Character sollte den höchsten Score haben');
        ensure((int)$result['candidates'][1]['type_id'] === 3, 'Location sollte als zweites folgen');
    },
    'entity_type_observation_filter' => function () {
        $service = new EntityCandidateService();
        $visual = [
            'subject_overview' => 'environment',
            'living_kind' => 'none',
            'gender_hint' => 'unknown',
            'age_hint' => '',
            'age_bucket' => 'unknown',
            'subject_keywords' => [],
            'setting' => ['forest background'],
            'objects' => [],
            'style_tags' => [],
        ];

        $result = $service->rankEntityTypesFromObservation(
            [
                ['id' => 1, 'name' => 'Character'],
                ['id' => 2, 'name' => 'Location'],
            ],
            $visual,
            AiPrepassService::emptyPrepassResult(),
            ['character' => 0.3, 'location' => 0.2]
        );

        ensure(count($result['excluded']) === 1 && (int)$result['excluded'][0]['type_id'] === 1, 'Character wird bei reinem Environment ausgeschlossen');
        ensure(!empty($result['candidates']) && (int)$result['candidates'][0]['type_id'] === 2, 'Location bleibt als Top-Kandidat erhalten');
    },
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[OK] {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
    }
}

if ($failures > 0) {
    exit(1);
}

echo "Alle Tests erfolgreich.\n";
