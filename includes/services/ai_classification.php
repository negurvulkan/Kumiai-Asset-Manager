<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../naming.php';
require_once __DIR__ . '/../classification.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/ai_prepass.php';
require_once __DIR__ . '/prepass_scoring.php';
require_once __DIR__ . '/entity_candidates.php';

class AiClassificationService
{
    private PDO $pdo;
    private AiOpenAiClient $client;
    private AiPrepassService $prepassService;
    private EntityCandidateService $entityCandidates;
    private array $config;
    private array $thresholds;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->thresholds = $config['openai']['classification'] ?? [];
        $this->client = new AiOpenAiClient($config['openai'] ?? []);
        $this->prepassService = new AiPrepassService($pdo, $config, $this->client);
        $this->entityCandidates = new EntityCandidateService();
    }

    public function classifyInventoryFile(int $inventoryId, array $user): array
    {
        $auditInput = ['inventory_id' => $inventoryId];
        try {
            $inventory = $this->loadInventory($inventoryId);
            $auditInput['project_id'] = $inventory['project_id'];

            $role = $this->assertRoleForProject((int)$inventory['project_id'], $user);
            $auditInput['role'] = $role;

            $absolutePath = rtrim($inventory['root_path'], '/') . $inventory['file_path'];
            if (!file_exists($absolutePath)) {
                throw new RuntimeException('Datei wurde nicht gefunden: ' . $absolutePath);
            }

            $prepass = null;
            $prepassError = null;
            $priors = [];
            $prepassFeatures = AiPrepassService::emptyPrepassResult();
            if (!empty($inventory['asset_id'])) {
                try {
                    $prepass = $this->prepassService->ensureSubjectFirst(
                        (int)$inventory['asset_id'],
                        (int)($inventory['asset_revision_id'] ?? 0),
                        $user
                    );
                    if (!($prepass['success'] ?? true)) {
                        $prepassError = $prepass['error'] ?? 'Prepass fehlgeschlagen.';
                    } else {
                        $prepassFeatures = $prepass['features'] ?? $prepassFeatures;
                        $priors = $prepass['priors'] ?? [];
                    }
                } catch (Throwable $e) {
                    $prepassError = $e->getMessage();
                }
            }

            if (empty($priors)) {
                $priors = (new PrepassScoringService())->derivePriors($prepassFeatures, true);
            }

            $visual = $this->runVisualExtraction($absolutePath, $prepassFeatures);
            $observationTokens = $this->collectObservationTokens($visual, $prepassFeatures);
            $queryVector = $this->client->embedText(
                $this->buildVisualEmbeddingPrompt($visual, $observationTokens, $priors)
            );

            $entityTypes = $this->loadEntityTypesWithFields((int)$inventory['project_id']);
            $entityTypeCandidates = $this->entityCandidates->rankEntityTypesFromObservation(
                array_values($entityTypes),
                $visual,
                $prepassFeatures,
                $priors
            );

            $topTypeIds = array_map(
                fn($row) => (int)$row['type_id'],
                array_slice($entityTypeCandidates['candidates'], 0, 2)
            );

            $entityCandidates = $this->rankEntitiesForTypes(
                (int)$inventory['project_id'],
                $entityTypes,
                $topTypeIds,
                $visual,
                $observationTokens,
                $priors,
                $queryVector
            );

            $verification = $this->verifyWithReferences($entityCandidates, $queryVector, $visual);
            $decision = $this->decideFinalAssignment(
                $verification['ranked'],
                (float)($visual['analysis_confidence'] ?? 0.0)
            );

            $assignment = null;
            if (($decision['status'] ?? '') === 'auto_assigned' && !empty($decision['entity'])) {
                $assignment = $this->applyAutoAssignment(
                    $inventory,
                    $decision['entity'],
                    $entityTypes,
                    $visual,
                    $observationTokens,
                    $queryVector
                );
            }

            $this->queueReviewDecision($inventory, $decision, (int)($user['id'] ?? 0));

            $this->logAudit(
                (int)$inventory['project_id'],
                (int)$inventory['id'],
                'ai_classification',
                $auditInput + ['absolute_path' => $absolutePath],
                [
                    'prepass' => $prepass,
                    'prepass_error' => $prepassError,
                    'visual' => $visual,
                    'tokens' => $observationTokens,
                    'priors' => $priors,
                    'entity_type_candidates' => $entityTypeCandidates,
                    'entity_candidates' => $entityCandidates,
                    'verification' => $verification,
                    'decision' => $decision,
                    'assignment' => $assignment,
                ],
                $decision['overall_confidence'] ?? null,
                'ok',
                null,
                (int)($user['id'] ?? 0)
            );

            return [
                'success' => true,
                'prepass' => $prepass,
                'prepass_error' => $prepassError,
                'visual' => $visual,
                'tokens' => $observationTokens,
                'priors' => $priors,
                'entity_type_candidates' => $entityTypeCandidates,
                'entity_candidates' => $entityCandidates,
                'verification' => $verification,
                'decision' => $decision,
                'assignment' => $assignment,
            ];
        } catch (Throwable $e) {
            $this->logAudit(
                (int)($auditInput['project_id'] ?? 0),
                $inventoryId,
                'ai_classification',
                $auditInput,
                ['error' => $e->getMessage()],
                null,
                'error',
                $e->getMessage(),
                (int)($user['id'] ?? 0)
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function loadInventory(int $inventoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT fi.*, p.root_path, p.name AS project_name, ar.asset_id
                                     FROM file_inventory fi
                                     JOIN projects p ON p.id = fi.project_id
                                     LEFT JOIN asset_revisions ar ON ar.id = fi.asset_revision_id
                                     WHERE fi.id = :id');
        $stmt->execute(['id' => $inventoryId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('File-Inventory-Eintrag nicht gefunden.');
        }

        return $row;
    }

    private function assertRoleForProject(int $projectId, array $user): string
    {
        $projects = user_projects($this->pdo);
        foreach ($projects as $project) {
            if ((int)$project['id'] !== $projectId) {
                continue;
            }
            $role = $project['role'] ?? '';
            if (in_array($role, ['owner', 'admin', 'editor'], true)) {
                return $role;
            }
            break;
        }

        throw new RuntimeException('Keine Berechtigung für den KI-Service (nur Admin/Editor/Owner).');
    }

    private function runVisualExtraction(string $absolutePath, array $prepass): array
    {
        return $this->client->analyzeImageWithSchema(
            $absolutePath,
            $this->visualSchema(),
            $this->visualInstruction($prepass),
            (int)($this->thresholds['max_retries'] ?? 2)
        );
    }

    private function visualSchema(): array
    {
        return [
            'type' => 'object',
            'required' => [
                'subject_overview',
                'living_kind',
                'gender_hint',
                'age_hint',
                'age_bucket',
                'subject_keywords',
                'setting',
                'objects',
                'style_tags',
                'free_caption',
                'analysis_confidence',
            ],
            'additionalProperties' => false,
            'properties' => [
                'subject_overview' => [
                    'type' => 'string',
                    'enum' => ['person', 'animal', 'object', 'environment', 'mixed', 'unknown'],
                ],
                'living_kind' => [
                    'type' => 'string',
                    'enum' => ['human', 'animal', 'fantasy', 'none', 'unknown'],
                ],
                'gender_hint' => [
                    'type' => 'string',
                    'enum' => ['female', 'male', 'androgynous', 'mixed', 'unknown'],
                ],
                'age_hint' => [
                    'type' => 'string',
                ],
                'age_bucket' => [
                    'type' => 'string',
                    'enum' => ['child', 'teen', 'adult', 'elder', 'mixed', 'unknown'],
                ],
                'subject_keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'setting' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'objects' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'style_tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'free_caption' => [
                    'type' => 'string',
                ],
                'analysis_confidence' => [
                    'type' => 'number',
                ],
            ],
        ];
    }

    private function visualInstruction(array $prepass): string
    {
        $context = [];
        if (!empty($prepass)) {
            $context[] = 'Prepass primary_subject=' . ($prepass['primary_subject'] ?? 'unknown');
            $context[] = 'subjects_present=' . implode(',', $prepass['subjects_present'] ?? []);
            $context[] = 'image_kind=' . ($prepass['image_kind'] ?? 'unknown');
        }

        $contextText = empty($context) ? '' : '\nKontext: ' . implode(' | ', $context);

        return <<<TXT
Du beobachtest das Bild und beschreibst nur sichtbare Merkmale. Keine Vermutungen zu konkreten Entities/Namen.
- subject_overview: person/animal/object/environment/mixed/unknown.
- living_kind (falls Lebewesen): human/animal/fantasy, sonst none/unknown.
- gender_hint, age_hint (frei) und age_bucket (child/teen/adult/elder/mixed/unknown) nur falls erkennbar.
- subject_keywords: kurze Stichworte zu Körperbau, Rasse/Art, Kleidung.
- setting: Umgebung/Ort/Lighting.
- objects: wichtige Gegenstände/Requisiten.
- style_tags: Stilhinweise (photo/anime/sketch, Farbstimmung etc.).
- free_caption: 1–2 Sätze neutrale Beschreibung.
- analysis_confidence: 0–1 für deine Gesamtsicherheit.
Halte dich streng an das JSON-Schema.$contextText
TXT;
    }

    private function collectObservationTokens(array $visual, array $prepass): array
    {
        $tokens = [];
        $candidates = array_merge(
            [$visual['subject_overview'] ?? '', $visual['living_kind'] ?? '', $visual['gender_hint'] ?? '', $visual['age_bucket'] ?? ''],
            $visual['subject_keywords'] ?? [],
            $visual['setting'] ?? [],
            $visual['objects'] ?? [],
            $visual['style_tags'] ?? []
        );

        $caption = strtolower($visual['free_caption'] ?? '');
        $candidates = array_merge($candidates, preg_split('/[\s,.;:]+/', $caption));
        $prepassCaption = strtolower($prepass['free_caption'] ?? '');
        if ($prepassCaption !== '') {
            $candidates = array_merge($candidates, preg_split('/[\s,.;:]+/', $prepassCaption));
        }

        foreach ($prepass['subjects_present'] ?? [] as $subject) {
            $candidates[] = $subject;
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value === '') {
                continue;
            }
            $slug = kumiai_slug($value);
            if ($slug !== '' && !in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }

        return $normalized;
    }

    private function loadEntityTypesWithFields(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entity_types WHERE project_id = :project_id ORDER BY name');
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $row['field_definitions'] = $this->decodeFieldDefinitions($row['field_definitions'] ?? null);
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    private function decodeFieldDefinitions(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildVisualEmbeddingPrompt(array $visual, array $tokens, array $priors): string
    {
        $parts = [
            'subject' => $visual['subject_overview'] ?? '',
            'living' => $visual['living_kind'] ?? '',
            'gender' => $visual['gender_hint'] ?? '',
            'age_bucket' => $visual['age_bucket'] ?? '',
            'keywords' => implode(', ', $visual['subject_keywords'] ?? []),
            'setting' => implode(', ', $visual['setting'] ?? []),
            'objects' => implode(', ', $visual['objects'] ?? []),
            'style' => implode(', ', $visual['style_tags'] ?? []),
            'free_caption' => $visual['free_caption'] ?? '',
            'tokens' => implode(', ', $tokens),
        ];

        if (!empty($priors)) {
            $parts['priors'] = implode(
                ', ',
                array_map(
                    fn($key, $value) => $key . ':' . number_format((float)$value, 2),
                    array_keys($priors),
                    $priors
                )
            );
        }

        return implode(' | ', array_filter($parts, fn($value) => $value !== ''));
    }

    private function rankEntitiesForTypes(
        int $projectId,
        array $entityTypes,
        array $typeIds,
        array $visual,
        array $tokens,
        array $priors,
        array $queryVector
    ): array {
        if (empty($typeIds)) {
            return ['ranked' => [], 'excluded' => []];
        }

        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT e.*, t.name AS type_name, t.field_definitions AS type_field_definitions
             FROM entities e
             JOIN entity_types t ON t.id = e.type_id
             WHERE e.project_id = ? AND e.type_id IN ($placeholders)"
        );
        $params = array_merge([$projectId], $typeIds);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $ranked = [];
        $excluded = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode($row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $fieldDefs = $this->decodeFieldDefinitions($row['type_field_definitions'] ?? null);
            $typeName = $row['type_name'] ?? '';
            $typeKey = entity_type_key($typeName);

            $entityTokens = $this->metadataTokens($row, $metadata, $fieldDefs, $typeName);
            $profile = $this->deriveProfileFromTokens($entityTokens);

            $fieldAlignment = $this->alignDynamicFields($visual, $metadata, $fieldDefs);
            if ($fieldAlignment['hard_mismatch']) {
                $excluded[] = [
                    'entity_id' => (int)$row['id'],
                    'name' => $row['name'],
                    'type_id' => (int)$row['type_id'],
                    'reason' => 'Harter Ausschluss aus Feldabgleich.',
                ];
                continue;
            }

            if ($visual['subject_overview'] === 'person' && $profile === 'animal') {
                $excluded[] = [
                    'entity_id' => (int)$row['id'],
                    'name' => $row['name'],
                    'type_id' => (int)$row['type_id'],
                    'reason' => 'Bild zeigt Person, Entity wirkt tierisch.',
                ];
                continue;
            }
            if ($visual['subject_overview'] === 'animal' && $profile === 'human') {
                $excluded[] = [
                    'entity_id' => (int)$row['id'],
                    'name' => $row['name'],
                    'type_id' => (int)$row['type_id'],
                    'reason' => 'Bild zeigt Tier, Entity wirkt menschlich.',
                ];
                continue;
            }

            $overlap = count(array_intersect($entityTokens, $tokens));
            $overlapScore = min(1.0, $overlap / max(3, count($tokens)) * 3);

            $embedding = $this->fetchEntityEmbedding($row, $metadata, $fieldDefs, $typeName);
            $similarity = $this->cosineSimilarity($queryVector, $embedding);

            $prior = (float)($priors[$typeKey] ?? 0.1);
            $score = $similarity * 0.55 + $overlapScore * 0.25 + $fieldAlignment['score'] * 0.2 + $prior * 0.1;
            $score = min(1.0, max(0.0, $score));

            $ranked[] = [
                'entity_id' => (int)$row['id'],
                'name' => $row['name'],
                'type_id' => (int)$row['type_id'],
                'type_name' => $typeName,
                'score' => $score,
                'similarity' => $similarity,
                'overlap_score' => $overlapScore,
                'field_alignment' => $fieldAlignment,
                'tokens' => $entityTokens,
                'profile' => $profile,
                'prior' => $prior,
            ];
        }

        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

        return ['ranked' => $ranked, 'excluded' => $excluded];
    }

    private function metadataTokens(array $entityRow, array $metadata, array $fieldDefs, string $typeName): array
    {
        $parts = [$entityRow['name'] ?? '', $entityRow['slug'] ?? '', $entityRow['description'] ?? '', $typeName];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key;
                $parts[] = (string)$value;
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if (is_scalar($child)) {
                        $parts[] = (string)$child;
                    }
                }
            }
        }

        foreach ($fieldDefs as $def) {
            $parts[] = $def['key'] ?? '';
            $parts[] = $def['label'] ?? '';
        }

        $tokens = [];
        foreach ($parts as $part) {
            $slug = kumiai_slug((string)$part);
            if ($slug !== '' && !in_array($slug, $tokens, true)) {
                $tokens[] = $slug;
            }
        }

        return $tokens;
    }

    private function deriveProfileFromTokens(array $tokens): string
    {
        $hasHuman = array_intersect($tokens, ['human', 'person', 'frau', 'mann', 'maedchen', 'junge', 'charakter', 'character']);
        $hasAnimal = array_intersect($tokens, ['animal', 'tier', 'pferd', 'katze', 'hund', 'drache', 'dragon']);
        $hasObject = array_intersect($tokens, ['objekt', 'object', 'waffe', 'schwert', 'auto']);

        if (!empty($hasHuman) && empty($hasAnimal)) {
            return 'human';
        }
        if (!empty($hasAnimal) && empty($hasHuman)) {
            return 'animal';
        }
        if (!empty($hasObject) && empty($hasHuman) && empty($hasAnimal)) {
            return 'object';
        }

        return 'unknown';
    }

    private function alignDynamicFields(array $visual, array $metadata, array $fieldDefs): array
    {
        $score = 0.0;
        $checks = 0;
        $hardMismatch = false;
        $notes = [];

        $gender = $visual['gender_hint'] ?? 'unknown';
        $ageBucket = $visual['age_bucket'] ?? 'unknown';
        $living = $visual['living_kind'] ?? 'unknown';

        foreach ($fieldDefs as $def) {
            $key = $def['key'] ?? '';
            $label = strtolower((string)($def['label'] ?? $key));
            $value = $metadata[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $checks++;
            $valueSlug = kumiai_slug((string)$value);
            $labelSlug = kumiai_slug($label);

            if ($this->matchesAny($labelSlug, ['gender', 'geschlecht'])) {
                if ($gender !== 'unknown') {
                    if ($this->matchesAny($valueSlug, [$gender])) {
                        $score += 0.5;
                        $notes[] = 'Geschlecht passt (' . $value . ')';
                    } elseif ($gender !== 'mixed') {
                        $hardMismatch = true;
                        $notes[] = 'Geschlecht widerspricht (' . $value . ' vs ' . $gender . ')';
                    }
                }
            } elseif ($this->matchesAny($labelSlug, ['age', 'alter'])) {
                if ($ageBucket !== 'unknown') {
                    if ($this->matchesAny($valueSlug, [$ageBucket, $visual['age_hint'] ?? ''])) {
                        $score += 0.4;
                        $notes[] = 'Alter/Age Bucket matcht (' . $value . ')';
                    } else {
                        $score -= 0.1;
                        $notes[] = 'Alter passt weniger gut';
                    }
                }
            } elseif ($this->matchesAny($labelSlug, ['art', 'species', 'rasse'])) {
                if ($living === 'human' && $this->matchesAny($valueSlug, ['animal', 'tier'])) {
                    $hardMismatch = true;
                    $notes[] = 'Art Tier vs. menschliches Motiv';
                } elseif ($living === 'animal' && $this->matchesAny($valueSlug, ['human', 'mensch', 'person'])) {
                    $hardMismatch = true;
                    $notes[] = 'Art Mensch vs. tierisches Motiv';
                } else {
                    $score += 0.25;
                    $notes[] = 'Art/Spezies unterstützt Zuordnung';
                }
            } else {
                if ($this->matchesAny($valueSlug, $this->visualSlugs($visual))) {
                    $score += 0.15;
                    $notes[] = 'Feld ' . $label . ' trifft visuelle Tokens.';
                }
            }
        }

        $score = $checks > 0 ? min(1.0, max(0.0, $score / max(1, $checks))) : 0.0;

        return [
            'score' => $score,
            'hard_mismatch' => $hardMismatch,
            'notes' => $notes,
        ];
    }

    private function visualSlugs(array $visual): array
    {
        return $this->collectObservationTokens($visual, []);
    }

    private function matchesAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needleSlug = kumiai_slug((string)$needle);
            if ($needleSlug !== '' && str_contains($value, $needleSlug)) {
                return true;
            }
        }

        return false;
    }

    private function fetchEntityEmbedding(array $entityRow, array $metadata, array $fieldDefs, string $typeName): array
    {
        $stmt = $this->pdo->prepare('SELECT embedding_json FROM entity_embeddings WHERE entity_id = :entity_id LIMIT 1');
        $stmt->execute(['entity_id' => $entityRow['id']]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_map('floatval', $decoded);
            }
        }

        $text = $this->buildEntityEmbeddingText($entityRow, $metadata, $fieldDefs, $typeName);
        $embedding = $this->client->embedText($text);
        $this->persistEntityEmbedding((int)$entityRow['id'], $embedding);

        return $embedding;
    }

    private function buildEntityEmbeddingText(array $entityRow, array $metadata, array $fieldDefs, string $typeName): string
    {
        $parts = [];
        $parts[] = 'Name: ' . ($entityRow['name'] ?? '');
        $parts[] = 'Typ: ' . $typeName;
        if (!empty($entityRow['description'])) {
            $parts[] = 'Beschreibung: ' . $entityRow['description'];
        }

        foreach ($fieldDefs as $def) {
            $key = $def['key'] ?? '';
            if ($key === '' || !isset($metadata[$key])) {
                continue;
            }
            $label = $def['label'] ?? $key;
            $parts[] = $label . ': ' . (is_scalar($metadata[$key]) ? $metadata[$key] : json_encode($metadata[$key]));
        }

        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . ': ' . $value;
            }
        }

        return implode(' | ', array_filter($parts, fn($p) => trim((string)$p) !== ''));
    }

    private function persistEntityEmbedding(int $entityId, array $vector): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO entity_embeddings (entity_id, embedding_json, created_at, updated_at) VALUES (:entity_id, :embedding_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE embedding_json = VALUES(embedding_json), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'entity_id' => $entityId,
            'embedding_json' => json_encode($vector),
        ]);
    }

    private function verifyWithReferences(array $entityCandidates, array $queryVector, array $visual): array
    {
        $ranked = $entityCandidates['ranked'] ?? [];
        $evidence = [];
        $topK = array_slice($ranked, 0, max(1, (int)($this->thresholds['top_k'] ?? 3)));

        foreach ($topK as $idx => $candidate) {
            $references = $this->collectReferenceCaptions((int)$candidate['entity_id']);
            $best = 0.0;
            $refUsed = null;
            foreach ($references as $ref) {
                $vector = $this->client->embedText($ref);
                $sim = $this->cosineSimilarity($queryVector, $vector);
                if ($sim > $best) {
                    $best = $sim;
                    $refUsed = $ref;
                }
            }
            $ranked[$idx]['reference_score'] = $best;
            $ranked[$idx]['final_score'] = min(1.0, max(0.0, $candidate['score'] * 0.6 + $best * 0.4));
            if ($refUsed) {
                $evidence[(int)$candidate['entity_id']] = ['caption' => $refUsed, 'score' => $best];
            }
        }

        usort($ranked, fn($a, $b) => ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0));

        return [
            'ranked' => $ranked,
            'reference_evidence' => $evidence,
        ];
    }

    private function collectReferenceCaptions(int $entityId, int $limit = 3): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fi.file_path, fi.asset_revision_id, ar.asset_id
             FROM entity_file_links efl
             JOIN file_inventory fi ON fi.id = efl.file_inventory_id
             LEFT JOIN asset_revisions ar ON ar.id = fi.asset_revision_id
             WHERE efl.entity_id = :entity_id
             ORDER BY fi.last_seen_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $captions = [];
        foreach ($rows as $row) {
            $caption = '';
            if (!empty($row['asset_id'])) {
                $prepassStmt = $this->pdo->prepare('SELECT result_json FROM asset_ai_prepass WHERE asset_id = :asset_id LIMIT 1');
                $prepassStmt->execute(['asset_id' => $row['asset_id']]);
                $pp = $prepassStmt->fetchColumn();
                if ($pp) {
                    $decoded = json_decode($pp, true);
                    $caption = $decoded['features']['free_caption'] ?? ($decoded['features']['primary_subject'] ?? '');
                }
            }

            if ($caption === '') {
                $caption = 'Referenzbild ' . basename((string)$row['file_path']);
            }
            $captions[] = $caption;
        }

        if (empty($captions)) {
            $captions[] = 'Keine Referenzen gefunden';
        }

        return $captions;
    }

    private function decideFinalAssignment(array $ranked, float $visionConfidence): array
    {
        if (empty($ranked)) {
            return [
                'status' => 'needs_review',
                'reason' => 'Keine Entity-Kandidaten gefunden.',
                'overall_confidence' => 0.0,
                'candidates' => [],
            ];
        }

        $threshold = (float)($this->thresholds['score_threshold'] ?? 0.55);
        $margin = (float)($this->thresholds['margin'] ?? 0.12);

        $winner = $ranked[0];
        $runner = $ranked[1] ?? ['final_score' => 0.0];
        $winnerScore = (float)($winner['final_score'] ?? $winner['score'] ?? 0.0);
        $runnerScore = (float)($runner['final_score'] ?? $runner['score'] ?? 0.0);
        $overall = min(1.0, max($visionConfidence, $winnerScore));

        $canAssign = $winnerScore >= $threshold && ($winnerScore - $runnerScore) >= $margin;

        return [
            'status' => $canAssign ? 'auto_assigned' : 'needs_review',
            'reason' => $canAssign ? 'Finaler Score über Schwellwert mit ausreichender Margin.' : 'Zu unsicher, Review nötig.',
            'entity' => $winner,
            'runner_up' => $runner,
            'overall_confidence' => $canAssign ? $overall : max($overall, $runnerScore),
            'candidates' => $ranked,
            'threshold' => $threshold,
            'margin' => $margin,
        ];
    }

    private function classifyAxesForEntity(
        array $entity,
        array $entityTypes,
        array $visual,
        array $tokens,
        array $queryVector
    ): array {
        $typeId = (int)($entity['type_id'] ?? 0);
        $typeRow = $entityTypes[$typeId] ?? null;
        $typeName = $typeRow['name'] ?? '';

        $axes = $typeName !== '' ? load_axes_for_entity($this->pdo, $typeName) : [];
        if (empty($axes)) {
            return [
                'axes' => [],
                'values' => [],
                'confidences' => [],
                'state' => 'fully_classified',
            ];
        }

        [$values, $conf] = $this->deriveAxisValues($axes, $tokens, $visual, $queryVector);
        $state = derive_classification_state($axes, $values);

        return [
            'axes' => $axes,
            'values' => $values,
            'confidences' => $conf,
            'state' => $state,
        ];
    }

    private function deriveAxisValues(array $axes, array $tokens, array $visual, array $queryVector): array
    {
        $values = [];
        $confidences = [];
        $tokenSet = array_unique($tokens);
        $text = strtolower(trim(($visual['free_caption'] ?? '') . ' ' . implode(' ', $visual['subject_keywords'] ?? [])));

        foreach ($axes as $axis) {
            $axisKey = $axis['axis_key'];
            $axisLabel = strtolower((string)$axis['label']);
            $bestValue = '';
            $bestScore = 0.0;

            if (!empty($axis['values'])) {
                foreach ($axis['values'] as $val) {
                    $slug = kumiai_slug($val['value_key'] ?? '');
                    if ($slug === '') {
                        continue;
                    }

                    $score = 0.0;
                    if (in_array($slug, $tokenSet, true) || str_contains($text, $slug)) {
                        $score += 0.7;
                    }

                    $valueText = $axisLabel . ' ' . ($val['label'] ?? $val['value_key']);
                    $vector = $this->client->embedText($valueText);
                    $score += $this->cosineSimilarity($queryVector, $vector) * 0.5;
                    $score = min(1.0, $score);

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestValue = $val['value_key'];
                    }
                }
            } else {
                foreach ($tokenSet as $token) {
                    $score = 0.0;
                    if (str_contains($token, $axisKey) || str_contains($axisLabel, $token)) {
                        $score += 0.4;
                    }
                    if (str_contains($text, $token)) {
                        $score += 0.2;
                    }
                    if ($score > $bestScore && $score >= 0.4) {
                        $bestScore = $score;
                        $bestValue = $token;
                    }
                }
            }

            if ($bestValue !== '' && $bestScore >= 0.45) {
                $values[$axisKey] = $bestValue;
                $confidences[$axisKey] = min(1.0, $bestScore);
            }
        }

        return [$values, $confidences];
    }

    private function applyAutoAssignment(
        array $inventory,
        array $entity,
        array $entityTypes,
        array $visual,
        array $tokens,
        array $queryVector
    ): array {
        $axesResult = $this->classifyAxesForEntity($entity, $entityTypes, $visual, $tokens, $queryVector);
        $state = $axesResult['state'];

        $this->persistClassification($inventory, $axesResult['axes'], $axesResult['values']);
        $this->ensureAssetEntityLink((int)($inventory['asset_id'] ?? 0), (int)$entity['entity_id']);

        $linkStmt = $this->pdo->prepare(
            'INSERT INTO entity_file_links (entity_id, file_inventory_id, created_at) VALUES (:entity_id, :file_inventory_id, NOW())
             ON DUPLICATE KEY UPDATE entity_id = VALUES(entity_id)'
        );
        $linkStmt->execute([
            'entity_id' => $entity['entity_id'],
            'file_inventory_id' => $inventory['id'],
        ]);

        $updateInventory = $this->pdo->prepare('UPDATE file_inventory SET classification_state = :state, status = "linked", last_seen_at = NOW() WHERE id = :id');
        $updateInventory->execute([
            'state' => $state,
            'id' => $inventory['id'],
        ]);

        return [
            'axes' => $axesResult,
            'classification_state' => $state,
        ];
    }

    private function persistClassification(array $inventory, array $axes, array $values): void
    {
        if (!empty($axes)) {
            replace_inventory_classifications($this->pdo, (int)$inventory['id'], $axes, $values);
        }

        $revisionId = (int)($inventory['asset_revision_id'] ?? 0);
        if ($revisionId > 0 && !empty($axes)) {
            replace_revision_classifications($this->pdo, $revisionId, $axes, $values);
        }
    }

    private function ensureAssetEntityLink(int $assetId, int $entityId): void
    {
        if ($assetId <= 0 || $entityId <= 0) {
            return;
        }

        $assetStmt = $this->pdo->prepare('UPDATE assets SET primary_entity_id = :entity_id WHERE id = :id');
        $assetStmt->execute(['entity_id' => $entityId, 'id' => $assetId]);

        $linkStmt = $this->pdo->prepare('INSERT IGNORE INTO asset_entities (asset_id, entity_id) VALUES (:asset_id, :entity_id)');
        $linkStmt->execute(['asset_id' => $assetId, 'entity_id' => $entityId]);
    }

    private function queueReviewDecision(array $inventory, array $decision, int $userId): void
    {
        $payload = [
            'status' => $decision['status'] ?? 'needs_review',
            'reason' => $decision['reason'] ?? '',
            'winner' => $decision['entity'] ?? null,
            'candidates' => $decision['candidates'] ?? [],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_review_queue (project_id, file_inventory_id, status, reason, suggested_assignment, confidence, created_by, created_at)
             VALUES (:project_id, :file_inventory_id, :status, :reason, :suggested_assignment, :confidence, :created_by, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason), suggested_assignment = VALUES(suggested_assignment), confidence = VALUES(confidence)'
        );
        $stmt->execute([
            'project_id' => $inventory['project_id'],
            'file_inventory_id' => $inventory['id'],
            'status' => $decision['status'] ?? 'needs_review',
            'reason' => $decision['reason'] ?? '',
            'suggested_assignment' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'confidence' => $decision['overall_confidence'] ?? null,
            'created_by' => $userId ?: null,
        ]);
    }

    private function logAudit(
        int $projectId,
        int $inventoryId,
        string $action,
        array $input,
        array $output,
        ?float $confidence,
        string $status,
        ?string $error,
        int $userId
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_audit_logs (project_id, file_inventory_id, action, input_payload, output_payload, confidence, status, error_message, created_by, created_at)
             VALUES (:project_id, :file_inventory_id, :action, :input_payload, :output_payload, :confidence, :status, :error_message, :created_by, NOW())'
        );
        $stmt->execute([
            'project_id' => $projectId ?: null,
            'file_inventory_id' => $inventoryId ?: null,
            'action' => $action,
            'input_payload' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'output_payload' => json_encode($output, JSON_UNESCAPED_UNICODE),
            'confidence' => $confidence,
            'status' => $status,
            'error_message' => $error,
            'created_by' => $userId ?: null,
        ]);
    }

    private function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        $length = min(count($vectorA), count($vectorB));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $a = (float)$vectorA[$i];
            $b = (float)$vectorB[$i];
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
