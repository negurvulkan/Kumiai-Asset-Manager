<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../naming.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/ai_prepass.php';

class AiClassificationService
{
    private PDO $pdo;
    private AiOpenAiClient $client;
    private AiPrepassService $prepassService;
    private array $config;
    private array $thresholds;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->thresholds = $config['openai']['classification'] ?? [];
        $this->client = new AiOpenAiClient($config['openai'] ?? []);
        $this->prepassService = new AiPrepassService($pdo, $config, $this->client);
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
                        $priors = $prepass['priors'] ?? [];
                    }
                } catch (Throwable $e) {
                    $prepassError = $e->getMessage();
                }
            }

            $vision = $this->client->analyzeImageWithSchema(
                $absolutePath,
                $this->visionSchema(),
                $this->visionInstruction($prepass),
                (int)($this->thresholds['max_retries'] ?? 2)
            );

            $keywords = $this->normalizeKeywords($vision);
            $candidates = $this->deriveCandidates($vision, $keywords, $priors);
            $queryVector = $this->client->embedText($this->buildEmbeddingPrompt($vision, $keywords, $priors));

            $scored = [];
            foreach ($candidates as $candidate) {
                $vector = $this->client->embedText($candidate['embedding_text']);
                $score = $this->cosineSimilarity($queryVector, $vector);
                $score += $this->priorBonus($priors, $candidate['prior_key'] ?? null);
                $score = min(1.0, max(0.0, $score));
                $scored[] = $candidate + ['score' => $score];
            }
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $topK = max(1, (int)($this->thresholds['top_k'] ?? 3));
            $scored = array_slice($scored, 0, $topK);

            $decision = $this->applyAutoAssign(
                $scored,
                (float)($vision['analysis_confidence'] ?? 0.0),
                (float)($prepass['confidence_overall'] ?? ($prepass['features']['confidence']['overall'] ?? 0.0))
            );
            $this->queueReviewDecision($inventory, $decision, (int)($user['id'] ?? 0));

            $this->logAudit(
                (int)$inventory['project_id'],
                (int)$inventory['id'],
                'ai_classification',
                $auditInput + ['absolute_path' => $absolutePath],
                [
                    'prepass' => $prepass,
                    'prepass_error' => $prepassError,
                    'vision' => $vision,
                    'keywords' => $keywords,
                    'candidates' => $scored,
                    'decision' => $decision,
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
                'vision' => $vision,
                'keywords' => $keywords,
                'candidates' => $scored,
                'decision' => $decision,
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

    private function visionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['asset_type', 'subjects', 'scene_hints', 'attributes', 'free_caption', 'analysis_confidence'],
            'additionalProperties' => false,
            'properties' => [
                'asset_type' => [
                    'type' => 'object',
                    'required' => ['coarse', 'fine'],
                    'additionalProperties' => false,
                    'properties' => [
                        'coarse' => ['type' => 'string'],
                        'fine' => ['type' => 'string'],
                    ],
                ],
                'subjects' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'scene_hints' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'attributes' => [
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

    private function visionInstruction(?array $prepass): string
    {
        $priorText = '';
        if ($prepass && ($prepass['features'] ?? null)) {
            $features = $prepass['features'];
            $priors = $prepass['priors'] ?? [];
            $priorLines = [];
            $priorLines[] = 'Prepass: primary_subject=' . ($features['primary_subject'] ?? 'unknown') .
                ', background=' . ($features['background_type'] ?? 'unknown') .
                ', image_kind=' . ($features['image_kind'] ?? 'unknown');
            if (!empty($features['subjects_present'])) {
                $priorLines[] = 'Subjects present: ' . implode(',', $features['subjects_present']);
            }
            $priorParts = [];
            foreach (['character', 'location', 'scene', 'prop', 'effect'] as $key) {
                if (isset($priors[$key])) {
                    $priorParts[] = $key . '=' . number_format((float)$priors[$key], 2);
                }
            }
            if (!empty($priorParts)) {
                $priorLines[] = 'Soft priors: ' . implode(', ', $priorParts);
            }

            $priorText = "\nPriors (nur Hinweis, keine harte Entscheidung):\n- " . implode("\n- ", $priorLines);
        }

        return <<<TXT
Analysiere das Bild und liefere ein prägnantes JSON, das exakt dem Schema entspricht.
Felder:
- asset_type.coarse: Grobkategorie wie character/background/location/scene/object/animal.
- asset_type.fine: Feinkategorie oder Motiv-Beschreibung.
- subjects: Liste zentraler Objekte/Personen/Tiere (Strings).
- scene_hints: Umgebung/Ort/Setting (Strings).
- attributes: visuelle Attribute (Stil, Kleidung, Stimmung).
- free_caption: maximal 2 Sätze Klartext.
- analysis_confidence: Zahl 0.0–1.0 für deine Sicherheit.$priorText
TXT;
    }

    private function normalizeKeywords(array $vision): array
    {
        $keywords = [];
        $keywords[] = $vision['asset_type']['coarse'] ?? '';
        $keywords[] = $vision['asset_type']['fine'] ?? '';

        foreach (['subjects', 'scene_hints', 'attributes'] as $field) {
            foreach ($vision[$field] ?? [] as $value) {
                $keywords[] = $value;
            }
        }

        $caption = strtolower($vision['free_caption'] ?? '');
        foreach (preg_split('/[\s,.;:]+/', $caption) as $token) {
            if (strlen($token) >= 3) {
                $keywords[] = $token;
            }
        }

        $normalized = [];
        foreach ($keywords as $keyword) {
            $value = trim((string)$keyword);
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

    private function deriveCandidates(array $vision, array $keywords, array $priors = []): array
    {
        $text = strtolower(
            implode(
                ' ',
                [
                    $vision['free_caption'] ?? '',
                    implode(' ', $vision['subjects'] ?? []),
                    implode(' ', $vision['scene_hints'] ?? []),
                    implode(' ', $vision['attributes'] ?? []),
                    $vision['asset_type']['coarse'] ?? '',
                    $vision['asset_type']['fine'] ?? '',
                ]
            )
        );
        $hasTerm = function (array $needles) use ($keywords, $text): bool {
            foreach ($needles as $needle) {
                $slug = kumiai_slug($needle);
                if (in_array($slug, $keywords, true) || str_contains($text, strtolower($needle))) {
                    return true;
                }
            }
            return false;
        };

        $candidates = [];

        if ($hasTerm(['horse', 'mare', 'stallion', 'pony', 'equine'])) {
            $candidates[] = [
                'key' => 'horse',
                'label' => 'Pferd / Equine',
                'embedding_text' => 'Horse or equine subject, possibly with tack, rider or stable context',
                'reason' => 'Motiv enthält Pferd/Equine-Bezug.',
                'prior_key' => 'character',
            ];
        }

        if ($hasTerm(['location', 'background', 'scene']) || !empty($vision['scene_hints'])) {
            $candidates[] = [
                'key' => 'location',
                'label' => 'Ort / Hintergrund',
                'embedding_text' => 'Location or backdrop focus, scenery and environmental description',
                'reason' => 'Setting/Hintergrund wird beschrieben.',
                'prior_key' => 'location',
            ];
        }

        if ($hasTerm(['stable', 'barn', 'hay', 'stall']) || ($this->containsKeyword($keywords, 'horse') && $hasTerm(['barn', 'stable']))) {
            $candidates[] = [
                'key' => 'stable',
                'label' => 'Stall / Scheune',
                'embedding_text' => 'Horse stable, barn or stall interior with hay and wood textures',
                'reason' => 'Hinweise auf Stall/Scheune im Motiv.',
                'prior_key' => 'location',
            ];
        }

        $hasTeen = $hasTerm(['teen', 'teenager', 'youth']);
        $hasSchool = $hasTerm(['school', 'campus', 'classroom']);
        $hasUniform = $hasTerm(['uniform', 'school uniform', 'blazer', 'pleated skirt']);
        if ($hasTeen && $hasSchool && $hasUniform) {
            $candidates[] = [
                'key' => 'teen_school_uniform',
                'label' => 'Teenager in Schuluniform',
                'embedding_text' => 'Teen student wearing a school uniform outfit',
                'reason' => 'Kombination Teen + School + Uniform erkannt.',
                'prior_key' => 'character',
            ];
        }

        $candidates = $this->augmentWithPriors($candidates, $priors);

        return $candidates;
    }

    private function augmentWithPriors(array $candidates, array $priors): array
    {
        $characterPrior = (float)($priors['character'] ?? 0.0);
        if ($characterPrior >= 0.3 && !$this->hasCandidate($candidates, 'character_focus')) {
            $candidates[] = [
                'key' => 'character_focus',
                'label' => 'Charakter / Figur im Fokus',
                'embedding_text' => 'Isolated character or avatar focus, figure-centric framing, outfit reference',
                'reason' => 'Prepass prior deutet auf Hauptfigur.',
                'prior_key' => 'character',
            ];
        }

        $locationPrior = (float)($priors['location'] ?? 0.0);
        if ($locationPrior >= 0.3 && !$this->hasCandidate($candidates, 'location')) {
            $candidates[] = [
                'key' => 'location',
                'label' => 'Ort / Hintergrund',
                'embedding_text' => 'Location or backdrop focus, scenery and environmental description',
                'reason' => 'Soft Prior auf Location aus dem Prepass.',
                'prior_key' => 'location',
            ];
        }

        $scenePrior = (float)($priors['scene'] ?? 0.0);
        if ($scenePrior >= 0.25 && !$this->hasCandidate($candidates, 'scene_frame')) {
            $candidates[] = [
                'key' => 'scene_frame',
                'label' => 'Szene / Panel',
                'embedding_text' => 'Framed scene or panel with multiple elements and environment context',
                'reason' => 'Prepass prior auf Szene/Panel erkannt.',
                'prior_key' => 'scene',
            ];
        }

        return $candidates;
    }

    private function hasCandidate(array $candidates, string $key): bool
    {
        foreach ($candidates as $candidate) {
            if (($candidate['key'] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    private function priorBonus(array $priors, ?string $key): float
    {
        if (!$key || !isset($priors[$key])) {
            return 0.0;
        }

        $value = min(1.0, max(0.0, (float)$priors[$key]));
        return 0.15 * $value;
    }

    private function containsKeyword(array $keywords, string $needle): bool
    {
        return in_array(kumiai_slug($needle), $keywords, true);
    }

    private function buildEmbeddingPrompt(array $vision, array $keywords, array $priors = []): string
    {
        $parts = [
            'caption' => $vision['free_caption'] ?? '',
            'coarse' => $vision['asset_type']['coarse'] ?? '',
            'fine' => $vision['asset_type']['fine'] ?? '',
            'subjects' => implode(', ', $vision['subjects'] ?? []),
            'scene' => implode(', ', $vision['scene_hints'] ?? []),
            'attributes' => implode(', ', $vision['attributes'] ?? []),
            'keywords' => implode(', ', $keywords),
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

    private function applyAutoAssign(array $scored, float $visionConfidence, float $prepassConfidence = 0.0): array
    {
        if (empty($scored)) {
            return [
                'status' => 'needs_review',
                'reason' => 'Keine Kandidaten konnten abgeleitet werden.',
                'overall_confidence' => 0.0,
            ];
        }

        $threshold = (float)($this->thresholds['score_threshold'] ?? 0.42);
        $margin = (float)($this->thresholds['margin'] ?? 0.08);
        $top = $scored[0];
        $second = $scored[1]['score'] ?? 0.0;
        $overall = max(min(max($visionConfidence, 0.0), 1.0), min(max($prepassConfidence, 0.0), 1.0));
        $overall = min($overall, (float)$top['score']);

        $canAssign = $top['score'] >= $threshold && ($top['score'] - $second) >= $margin && $overall >= $threshold;

        return [
            'status' => $canAssign ? 'auto_assigned' : 'needs_review',
            'reason' => $canAssign ? 'Score über Schwellwert und ausreichende Margin.' : 'Score/Margin zu niedrig, Review notwendig.',
            'winner' => $top,
            'runner_up_score' => $second,
            'score_threshold' => $threshold,
            'score_margin' => $margin,
            'overall_confidence' => $overall,
        ];
    }

    private function queueReviewDecision(array $inventory, array $decision, int $userId): void
    {
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
            'suggested_assignment' => json_encode($decision['winner'] ?? new stdClass()),
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
}
