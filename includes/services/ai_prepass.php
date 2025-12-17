<?php
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/prepass_scoring.php';

class AiPrepassService
{
    private const STAGE = 'SUBJECT_FIRST';

    private PDO $pdo;
    private AiOpenAiClient $client;
    private array $config;

    public function __construct(PDO $pdo, array $config, ?AiOpenAiClient $client = null)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->client = $client ?: new AiOpenAiClient($config['openai'] ?? []);
    }

    public function ensureSubjectFirst(int $assetId, ?int $revisionId = null, ?array $user = null): array
    {
        $existing = $this->fetchExisting($assetId);
        if ($existing) {
            $existingRevision = (int)($existing['revision_id'] ?? 0);
            if (!$revisionId || $revisionId === $existingRevision) {
                return ['success' => true] + $existing + ['source' => 'cached'];
            }
        }

        return $this->runSubjectFirst($assetId, $revisionId, $user);
    }

    public function runSubjectFirst(int $assetId, ?int $revisionId = null, ?array $user = null): array
    {
        $auditInput = ['asset_id' => $assetId, 'revision_id' => $revisionId];

        try {
            $asset = $this->loadAsset($assetId);
            $auditInput['project_id'] = (int)$asset['project_id'];
            $this->assertRoleForProject((int)$asset['project_id'], $user);

            $revision = $this->loadRevision($assetId, $revisionId);
            $auditInput['revision_id'] = (int)$revision['id'];
            $inventoryId = $this->lookupInventoryId((int)$revision['id']);

            $absolutePath = rtrim($asset['root_path'], '/') . $revision['file_path'];
            if (!file_exists($absolutePath)) {
                throw new RuntimeException('Datei für den Prepass wurde nicht gefunden: ' . $absolutePath);
            }

            $maxRetries = (int)($this->config['openai']['prepass']['max_retries'] ?? 2);
            $detail = $this->config['openai']['prepass']['detail'] ?? 'low';
            $features = $this->client->analyzeImageWithSchema(
                $absolutePath,
                $this->prepassSchema(),
                $this->prepassInstruction(),
                $maxRetries,
                $detail,
                'Du gibst NUR JSON aus, das dem Schema entspricht. Wenn unsicher: unknown nutzen, aber Felder immer füllen.'
            );

            $normalized = self::normalizePrepassResult($features);
            $priors = (new PrepassScoringService())->derivePriors($normalized);
            $resultPayload = [
                'asset_id' => $assetId,
                'revision_id' => (int)$revision['id'],
                'stage' => self::STAGE,
                'features' => $normalized,
                'priors' => $priors,
            ];

            $persisted = $this->persistPrepass($assetId, $resultPayload, (float)($normalized['confidence']['overall'] ?? 0.0));
            $this->logAudit(
                (int)$asset['project_id'],
                $inventoryId,
                (int)$revision['id'],
                $auditInput + ['absolute_path' => $absolutePath],
                $resultPayload,
                (float)($normalized['confidence']['overall'] ?? 0.0),
                'ok',
                null,
                $persisted['diff'] ?? null,
                (int)($user['id'] ?? 0)
            );

            return [
                'success' => true,
                'stage' => self::STAGE,
                'asset_id' => $assetId,
                'revision_id' => (int)$revision['id'],
                'inventory_id' => $inventoryId,
                'model' => $this->client->getVisionModel(),
                'features' => $normalized,
                'priors' => $priors,
                'confidence_overall' => (float)($normalized['confidence']['overall'] ?? 0.0),
                'source' => $persisted['source'] ?? 'fresh',
            ];
        } catch (Throwable $e) {
            $fallback = self::emptyPrepassResult();
            $fallbackPriors = (new PrepassScoringService())->derivePriors($fallback, false);
            $this->logAudit(
                (int)($auditInput['project_id'] ?? 0),
                null,
                (int)($auditInput['revision_id'] ?? 0),
                $auditInput,
                ['error' => $e->getMessage()],
                null,
                'error',
                $e->getMessage(),
                null,
                (int)($user['id'] ?? 0)
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stage' => self::STAGE,
                'asset_id' => $assetId,
                'revision_id' => $revisionId,
                'features' => $fallback,
                'priors' => $fallbackPriors,
                'needs_review' => true,
            ];
        }
    }

    public static function normalizePrepassResult(array $raw): array
    {
        $normalized = self::emptyPrepassResult();

        $normalized['primary_subject'] = self::enumOrUnknown(
            $raw['primary_subject'] ?? null,
            ['human', 'animal', 'object', 'environment', 'text', 'logo', 'mixed', 'unknown']
        );

        $subjects = array_values(array_unique(array_filter(
            $raw['subjects_present'] ?? [],
            fn($item) => in_array($item, ['human', 'animal', 'object', 'environment', 'text', 'logo'], true)
        )));
        $normalized['subjects_present'] = $subjects;

        $normalized['counts'] = [
            'humans' => max(0, (int)($raw['counts']['humans'] ?? 0)),
            'animals' => max(0, (int)($raw['counts']['animals'] ?? 0)),
            'objects' => max(0, (int)($raw['counts']['objects'] ?? 0)),
        ];

        $normalized['human_attributes'] = [
            'present' => (bool)($raw['human_attributes']['present'] ?? false),
            'apparent_age' => self::enumOrUnknown(
                $raw['human_attributes']['apparent_age'] ?? null,
                ['child', 'teen', 'adult', 'elder', 'unknown']
            ),
            'gender_presentation' => self::enumOrUnknown(
                $raw['human_attributes']['gender_presentation'] ?? null,
                ['female', 'male', 'androgynous', 'unknown']
            ),
        ];

        $normalized['image_kind'] = self::enumOrUnknown(
            $raw['image_kind'] ?? null,
            ['photo', 'manga', 'anime', 'lineart', 'sketch', 'screenshot', 'panel', 'reference_sheet', 'other', 'unknown']
        );

        $normalized['background_type'] = self::enumOrUnknown(
            $raw['background_type'] ?? null,
            ['plain', 'transparent', 'environment', 'unknown']
        );

        $normalized['notes'] = [
            'is_single_character_fullbody' => (bool)($raw['notes']['is_single_character_fullbody'] ?? false),
            'is_scene_establishing_shot' => (bool)($raw['notes']['is_scene_establishing_shot'] ?? false),
            'contains_multiple_panels' => (bool)($raw['notes']['contains_multiple_panels'] ?? false),
        ];

        $normalized['free_caption'] = (string)($raw['free_caption'] ?? '');
        $normalized['confidence'] = [
            'overall' => self::clamp01((float)($raw['confidence']['overall'] ?? 0.0)),
            'primary_subject' => self::clamp01((float)($raw['confidence']['primary_subject'] ?? 0.0)),
        ];

        return $normalized;
    }

    public static function emptyPrepassResult(): array
    {
        return [
            'primary_subject' => 'unknown',
            'subjects_present' => [],
            'counts' => [
                'humans' => 0,
                'animals' => 0,
                'objects' => 0,
            ],
            'human_attributes' => [
                'present' => false,
                'apparent_age' => 'unknown',
                'gender_presentation' => 'unknown',
            ],
            'image_kind' => 'unknown',
            'background_type' => 'unknown',
            'notes' => [
                'is_single_character_fullbody' => false,
                'is_scene_establishing_shot' => false,
                'contains_multiple_panels' => false,
            ],
            'free_caption' => '',
            'confidence' => [
                'overall' => 0.0,
                'primary_subject' => 0.0,
            ],
        ];
    }

    private function fetchExisting(int $assetId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM asset_ai_prepass WHERE asset_id = :asset_id LIMIT 1');
        $stmt->execute(['asset_id' => $assetId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $decoded = json_decode($row['result_json'] ?? '', true);
        if (!is_array($decoded)) {
            return null;
        }

        $decoded['confidence_overall'] = (float)($row['confidence_overall'] ?? 0.0);
        $decoded['model'] = $row['model'] ?? '';

        return $decoded;
    }

    private function loadAsset(int $assetId): array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, p.root_path FROM assets a JOIN projects p ON p.id = a.project_id WHERE a.id = :id');
        $stmt->execute(['id' => $assetId]);
        $asset = $stmt->fetch();
        if (!$asset) {
            throw new RuntimeException('Asset wurde nicht gefunden.');
        }

        return $asset;
    }

    private function loadRevision(int $assetId, ?int $revisionId): array
    {
        if ($revisionId) {
            $stmt = $this->pdo->prepare('SELECT * FROM asset_revisions WHERE id = :id AND asset_id = :asset_id');
            $stmt->execute(['id' => $revisionId, 'asset_id' => $assetId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM asset_revisions WHERE asset_id = :asset_id ORDER BY version DESC LIMIT 1');
            $stmt->execute(['asset_id' => $assetId]);
        }

        $revision = $stmt->fetch();
        if (!$revision) {
            throw new RuntimeException('Keine Revision für den Prepass gefunden.');
        }

        return $revision;
    }

    private function lookupInventoryId(int $revisionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM file_inventory WHERE asset_revision_id = :rev LIMIT 1');
        $stmt->execute(['rev' => $revisionId]);
        $id = $stmt->fetchColumn();

        return $id ? (int)$id : null;
    }

    private function assertRoleForProject(int $projectId, ?array $user): void
    {
        if (php_sapi_name() === 'cli' && !$user) {
            return;
        }
        if (!$user || !isset($user['id'])) {
            throw new RuntimeException('Keine Berechtigung für den KI-Prepass (Login erforderlich).');
        }

        $stmt = $this->pdo->prepare('SELECT role FROM project_roles WHERE project_id = :project_id AND user_id = :user_id');
        $stmt->execute(['project_id' => $projectId, 'user_id' => $user['id']]);
        $role = $stmt->fetchColumn();

        if (!in_array($role, ['owner', 'admin', 'editor'], true)) {
            throw new RuntimeException('Keine Berechtigung für den KI-Prepass (nur Owner/Admin/Editor).');
        }
    }

    private function persistPrepass(int $assetId, array $payload, float $confidence): array
    {
        $existingStmt = $this->pdo->prepare('SELECT * FROM asset_ai_prepass WHERE asset_id = :asset_id LIMIT 1');
        $existingStmt->execute(['asset_id' => $assetId]);
        $existingRow = $existingStmt->fetch();

        $source = $existingRow ? 'updated' : 'inserted';
        $diff = null;

        if ($existingRow) {
            $old = json_decode($existingRow['result_json'] ?? '', true);
            if (is_array($old)) {
                $diff = $this->diffArrays($old, $payload);
                if (empty($diff['changed']) && empty($diff['added']) && empty($diff['removed'])) {
                    $source = 'unchanged';
                    $diff = null;
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO asset_ai_prepass (asset_id, stage, model, result_json, confidence_overall, created_at)
             VALUES (:asset_id, :stage, :model, :result_json, :confidence_overall, NOW())
             ON DUPLICATE KEY UPDATE stage = VALUES(stage), model = VALUES(model), result_json = VALUES(result_json), confidence_overall = VALUES(confidence_overall), updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            'asset_id' => $assetId,
            'stage' => self::STAGE,
            'model' => $this->client->getVisionModel(),
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'confidence_overall' => $confidence,
        ]);

        return ['source' => $source, 'diff' => $diff];
    }

    private function diffArrays(array $old, array $new, string $path = ''): array
    {
        $diff = ['changed' => [], 'added' => [], 'removed' => []];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $key) {
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;
            $currentPath = $path === '' ? $key : $path . '.' . $key;

            if (is_array($oldVal) && is_array($newVal)) {
                $child = $this->diffArrays($oldVal, $newVal, $currentPath);
                foreach (['changed', 'added', 'removed'] as $section) {
                    $diff[$section] = array_merge($diff[$section], $child[$section]);
                }
                continue;
            }

            if (!array_key_exists($key, $old)) {
                $diff['added'][$currentPath] = $newVal;
            } elseif (!array_key_exists($key, $new)) {
                $diff['removed'][$currentPath] = $oldVal;
            } elseif ($oldVal !== $newVal) {
                $diff['changed'][$currentPath] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        return $diff;
    }

    private function logAudit(
        int $projectId,
        ?int $inventoryId,
        int $revisionId,
        array $input,
        array $output,
        ?float $confidence,
        string $status,
        ?string $error,
        ?array $diff,
        int $userId
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_audit_logs (project_id, file_inventory_id, revision_id, action, input_payload, output_payload, confidence, status, error_message, diff_payload, created_by, created_at)
             VALUES (:project_id, :file_inventory_id, :revision_id, :action, :input_payload, :output_payload, :confidence, :status, :error_message, :diff_payload, :created_by, NOW())'
        );
        $stmt->execute([
            'project_id' => $projectId ?: null,
            'file_inventory_id' => $inventoryId ?: null,
            'revision_id' => $revisionId ?: null,
            'action' => 'ai_prepass_subject',
            'input_payload' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'output_payload' => json_encode($output, JSON_UNESCAPED_UNICODE),
            'confidence' => $confidence,
            'status' => $status,
            'error_message' => $error,
            'diff_payload' => $diff ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null,
            'created_by' => $userId ?: null,
        ]);
    }

    private function prepassSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'primary_subject' => [
                    'type' => 'string',
                    'enum' => ['human', 'animal', 'object', 'environment', 'text', 'logo', 'mixed', 'unknown'],
                ],
                'subjects_present' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['human', 'animal', 'object', 'environment', 'text', 'logo']],
                ],
                'counts' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'humans' => ['type' => 'integer', 'minimum' => 0],
                        'animals' => ['type' => 'integer', 'minimum' => 0],
                        'objects' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'required' => ['humans', 'animals', 'objects'],
                ],
                'human_attributes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'present' => ['type' => 'boolean'],
                        'apparent_age' => ['type' => 'string', 'enum' => ['child', 'teen', 'adult', 'elder', 'unknown']],
                        'gender_presentation' => ['type' => 'string', 'enum' => ['female', 'male', 'androgynous', 'unknown']],
                    ],
                    'required' => ['present', 'apparent_age', 'gender_presentation'],
                ],
                'image_kind' => [
                    'type' => 'string',
                    'enum' => ['photo', 'manga', 'anime', 'lineart', 'sketch', 'screenshot', 'panel', 'reference_sheet', 'other', 'unknown'],
                ],
                'background_type' => [
                    'type' => 'string',
                    'enum' => ['plain', 'transparent', 'environment', 'unknown'],
                ],
                'notes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'is_single_character_fullbody' => ['type' => 'boolean'],
                        'is_scene_establishing_shot' => ['type' => 'boolean'],
                        'contains_multiple_panels' => ['type' => 'boolean'],
                    ],
                    'required' => ['is_single_character_fullbody', 'is_scene_establishing_shot', 'contains_multiple_panels'],
                ],
                'free_caption' => [
                    'type' => 'string',
                ],
                'confidence' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'overall' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'primary_subject' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                    'required' => ['overall', 'primary_subject'],
                ],
            ],
            'required' => [
                'primary_subject',
                'subjects_present',
                'counts',
                'human_attributes',
                'image_kind',
                'background_type',
                'notes',
                'free_caption',
                'confidence',
            ],
        ];
    }

    private function prepassInstruction(): string
    {
        return <<<TXT
SUBJECT_FIRST: Erkenne nur die sichtbaren Subjekte (keine Klassifizierung des Assets). Pflichtfelder: primary_subject, subjects_present, counts (humans/animals/objects), human_attributes (present, apparent_age, gender_presentation), image_kind, background_type, notes (is_single_character_fullbody, is_scene_establishing_shot, contains_multiple_panels), free_caption, confidence (overall, primary_subject).
- Wähle unknown, wenn du unsicher bist.
- Nutze die enums exakt wie im Schema. Keine Zusatzfelder.
- Beschreibe das Bild sachlich: Manga/Lineart/Foto, freigestellt vs. Umgebung, Anzahl der Figuren.
- Hintergrund plain/transparent, wenn einfarbig/freigestellt; environment, wenn sichtbare Szene/Ort.
- Gib nur JSON aus, das dem Schema entspricht.
TXT;
    }

    private static function enumOrUnknown(?string $value, array $enum): string
    {
        return in_array($value, $enum, true) ? $value : 'unknown';
    }

    private static function clamp01(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
