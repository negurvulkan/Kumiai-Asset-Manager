<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../naming.php';
require_once __DIR__ . '/ai_client.php';

class AiClassificationService
{
    private PDO $pdo;
    private AiOpenAiClient $client;
    private array $config;
    private array $thresholds;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->thresholds = $config['openai']['classification'] ?? [];
        $this->client = new AiOpenAiClient($config['openai'] ?? []);
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

            $vision = $this->client->analyzeImageWithSchema(
                $absolutePath,
                $this->visionSchema(),
                $this->visionInstruction(),
                (int)($this->thresholds['max_retries'] ?? 2)
            );

            $keywords = $this->normalizeKeywords($vision);
            $candidates = $this->deriveCandidates($vision, $keywords);
            $queryVector = $this->client->embedText($this->buildEmbeddingPrompt($vision, $keywords));

            $scored = [];
            foreach ($candidates as $candidate) {
                $vector = $this->client->embedText($candidate['embedding_text']);
                $score = $this->cosineSimilarity($queryVector, $vector);
                $scored[] = $candidate + ['score' => $score];
            }
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $topK = max(1, (int)($this->thresholds['top_k'] ?? 3));
            $scored = array_slice($scored, 0, $topK);

            $decision = $this->applyAutoAssign($scored, (float)($vision['analysis_confidence'] ?? 0.0));
            $this->queueReviewDecision($inventory, $decision, (int)($user['id'] ?? 0));

            $this->logAudit(
                (int)$inventory['project_id'],
                (int)$inventory['id'],
                'ai_classification',
                $auditInput + ['absolute_path' => $absolutePath],
                [
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
        $stmt = $this->pdo->prepare('SELECT fi.*, p.root_path, p.name AS project_name FROM file_inventory fi JOIN projects p ON p.id = fi.project_id WHERE fi.id = :id');
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

    private function visionInstruction(): string
    {
        return <<<TXT
Analysiere das Bild und liefere ein prägnantes JSON, das exakt dem Schema entspricht.
Felder:
- asset_type.coarse: Grobkategorie wie character/background/location/scene/object/animal.
- asset_type.fine: Feinkategorie oder Motiv-Beschreibung.
- subjects: Liste zentraler Objekte/Personen/Tiere (Strings).
- scene_hints: Umgebung/Ort/Setting (Strings).
- attributes: visuelle Attribute (Stil, Kleidung, Stimmung).
- free_caption: maximal 2 Sätze Klartext.
- analysis_confidence: Zahl 0.0–1.0 für deine Sicherheit.
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

    private function deriveCandidates(array $vision, array $keywords): array
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
            ];
        }

        if ($hasTerm(['location', 'background', 'scene']) || !empty($vision['scene_hints'])) {
            $candidates[] = [
                'key' => 'location',
                'label' => 'Ort / Hintergrund',
                'embedding_text' => 'Location or backdrop focus, scenery and environmental description',
                'reason' => 'Setting/Hintergrund wird beschrieben.',
            ];
        }

        if ($hasTerm(['stable', 'barn', 'hay', 'stall']) || ($this->containsKeyword($keywords, 'horse') && $hasTerm(['barn', 'stable']))) {
            $candidates[] = [
                'key' => 'stable',
                'label' => 'Stall / Scheune',
                'embedding_text' => 'Horse stable, barn or stall interior with hay and wood textures',
                'reason' => 'Hinweise auf Stall/Scheune im Motiv.',
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
            ];
        }

        return $candidates;
    }

    private function containsKeyword(array $keywords, string $needle): bool
    {
        return in_array(kumiai_slug($needle), $keywords, true);
    }

    private function buildEmbeddingPrompt(array $vision, array $keywords): string
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

    private function applyAutoAssign(array $scored, float $visionConfidence): array
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
        $overall = min(max($visionConfidence, 0.0), 1.0);
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
