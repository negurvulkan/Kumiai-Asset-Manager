<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/classification.php';
require_once __DIR__ . '/../includes/services/ai_classification.php';
require_login();

$message = null;
$error = null;
$aiResults = [];

$assetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assetId <= 0) {
    header('Location: assets.php');
    exit;
}

// Asset laden
$stmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, t.name AS primary_entity_type
                       FROM assets a
                       LEFT JOIN entities e ON a.primary_entity_id = e.id
                       LEFT JOIN entity_types t ON e.type_id = t.id
                       WHERE a.id = :id');
$stmt->execute(['id' => $assetId]);
$asset = $stmt->fetch();

if (!$asset) {
    render_header('Asset nicht gefunden');
    echo '<div class="alert alert-danger">Asset nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectId = (int)$asset['project_id'];
$projects = user_projects($pdo);
$projectContext = null;
foreach ($projects as $p) {
    if ((int)$p['id'] === $projectId) {
        $projectContext = $p;
        break;
    }
}

if (!$projectContext) {
    render_header('Zugriff verweigert');
    echo '<div class="alert alert-danger">Kein Zugriff auf dieses Projekt.</div>';
    render_footer();
    exit;
}

$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectRow = $projectStmt->fetch();
$projectRoot = rtrim($projectRow['root_path'], '/');

$canReview = in_array($projectContext['role'], ['owner', 'admin', 'editor'], true);
$prepassData = null;
$prepassStmt = $pdo->prepare('SELECT * FROM asset_ai_prepass WHERE asset_id = :asset_id LIMIT 1');
$prepassStmt->execute(['asset_id' => $assetId]);
$prepassRow = $prepassStmt->fetch();
if ($prepassRow) {
    $decoded = json_decode($prepassRow['result_json'] ?? '', true);
    if (is_array($decoded)) {
        $prepassData = $decoded + [
            'model' => $prepassRow['model'] ?? '',
            'confidence_overall' => (float)($prepassRow['confidence_overall'] ?? 0.0),
            'created_at' => $prepassRow['created_at'] ?? null,
            'updated_at' => $prepassRow['updated_at'] ?? null,
        ];
    }
}

// Load Axes for Entity (needed for display and logic)
$axesForAsset = [];
if ($asset['primary_entity_id']) {
    $axesForAsset = load_axes_for_entity($pdo, $asset['primary_entity_type'] ?? '');
}
$classificationMap = fetch_asset_classifications($pdo, $assetId);

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'ai_classify_revision') {
        if (!$canReview) {
            $error = 'Keine Berechtigung für den KI-Run.';
        } else {
            $revId = (int)($_POST['revision_id'] ?? 0);
            if ($revId <= 0) {
                $error = 'Ungültige Revision für den KI-Run.';
            } else {
                $revStmt = $pdo->prepare('SELECT id, file_path FROM asset_revisions WHERE id = :id AND asset_id = :asset_id');
                $revStmt->execute(['id' => $revId, 'asset_id' => $assetId]);
                $revRow = $revStmt->fetch();
                if (!$revRow) {
                    $error = 'Revision gehört nicht zu diesem Asset.';
                } else {
                    $inventoryRow = null;
                    $inventoryStmt = $pdo->prepare('SELECT * FROM file_inventory WHERE asset_revision_id = :revision_id LIMIT 1');
                    $inventoryStmt->execute(['revision_id' => $revId]);
                    $inventoryRow = $inventoryStmt->fetch();

                    if (!$inventoryRow && $projectRoot !== '' && ($revRow['file_path'] ?? '') !== '') {
                        $byPath = $pdo->prepare('SELECT * FROM file_inventory WHERE project_id = :project_id AND file_path = :file_path LIMIT 1');
                        $byPath->execute(['project_id' => $projectId, 'file_path' => $revRow['file_path']]);
                        $inventoryRow = $byPath->fetch();
                    }

                    if (!$inventoryRow) {
                        $error = 'Kein Inventory-Eintrag für diese Revision gefunden.';
                    } else {
                        $service = new AiClassificationService($pdo, $config);
                        $result = $service->classifyInventoryFile((int)$inventoryRow['id'], current_user());
                        if ($result['success'] ?? false) {
                            $aiResults[$revId] = $result;
                            $message = 'KI-Lauf abgeschlossen.';
                            if (!empty($result['prepass'])) {
                                $prepassData = $result['prepass'];
                            }
                        } else {
                            $error = 'KI-Lauf fehlgeschlagen: ' . ($result['error'] ?? 'Unbekannter Fehler');
                        }
                    }
                }
            }
        }
    }

    if ($action === 'update_asset') {
        $displayName = trim($_POST['asset_display_name'] ?? '');
        $type = trim($_POST['asset_type'] ?? 'other');
        $description = trim($_POST['asset_description'] ?? '');
        $status = trim($_POST['asset_status'] ?? 'active');
        $validStatus = ['active', 'deprecated', 'archived'];

        // Collect Axis Values
        $inputValues = [];
        foreach ($axesForAsset as $axis) {
            $inputValues[$axis['axis_key']] = trim($_POST['axis_' . $axis['id']] ?? '');
        }
        $classValues = normalize_axis_values($axesForAsset, $inputValues);

        // Build new Asset Key
        $entityInfo = [
            'slug' => $asset['primary_entity_slug'],
            'name' => $asset['primary_entity_name']
        ];
        // Use ID for misc/unassigned if needed
        $newAssetKey = build_asset_key($entityInfo, $axesForAsset, $classValues, $assetId);

        if (in_array($status, $validStatus, true)) {
            // Check Collision if key changed
            if ($newAssetKey !== $asset['asset_key']) {
                $existingStmt = $pdo->prepare('SELECT id FROM assets WHERE project_id = :project_id AND asset_key = :asset_key AND id != :id LIMIT 1');
                $existingStmt->execute(['project_id' => $projectId, 'asset_key' => $newAssetKey, 'id' => $assetId]);
                if ($existingStmt->fetch()) {
                    $error = 'Diese Klassifizierung führt zu einem Asset-Key, der bereits existiert.';
                }
            }

            if (!$error) {
                $updateStmt = $pdo->prepare('UPDATE assets SET display_name = :display_name, asset_type = :asset_type, description = :description, status = :status, asset_key = :asset_key, name = :name WHERE id = :id');
                $updateStmt->execute([
                    'display_name' => $displayName !== '' ? $displayName : null,
                    'asset_type' => $type,
                    'description' => $description,
                    'status' => $status,
                    'asset_key' => $newAssetKey,
                    'name' => $newAssetKey,
                    'id' => $assetId,
                ]);

                // Update Classifications
                replace_asset_classifications($pdo, $assetId, $axesForAsset, $classValues);

                $message = 'Asset aktualisiert.';
                // Refresh Asset Data
                $stmt->execute(['id' => $assetId]);
                $asset = $stmt->fetch();
                $classificationMap = fetch_asset_classifications($pdo, $assetId);
            }
        }
    }

    if ($action === 'add_revision') {
        $filePath = trim($_POST['file_path'] ?? '');
        $fileHash = trim($_POST['file_hash'] ?? '');
        $mime = trim($_POST['mime_type'] ?? 'application/octet-stream');
        $status = trim($_POST['review_status'] ?? 'pending');
        $useTemplate = isset($_POST['enforce_template']);
        $viewLabel = trim($_POST['view_label'] ?? 'main');
        $manualExtension = trim($_POST['file_extension'] ?? '');
        $uploadedFile = $_FILES['revision_file'] ?? null;

        $fileSize = (int)($_POST['file_size_bytes'] ?? 0);
        $width = null;
        $height = null;

        $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id = :asset_id');
        $versionStmt->execute(['asset_id' => $assetId]);
        $nextVersion = (int)$versionStmt->fetchColumn();

        $entityContext = null;
        if ($asset['primary_entity_id']) {
            $entityContext = [
                'id' => $asset['primary_entity_id'],
                'name' => $asset['primary_entity_name'],
                'slug' => $asset['primary_entity_slug'],
                'type' => $asset['primary_entity_type'],
            ];
        }

        if (!empty($classificationMap['view'])) {
            $viewLabel = $classificationMap['view'];
        }

        $filePath = sanitize_relative_path($filePath);
        $extension = $manualExtension !== '' ? ltrim($manualExtension, '.') : '';
        if ($extension === '') {
            $extensionSource = $filePath !== '' ? $filePath : ($uploadedFile['name'] ?? 'png');
            $extension = ltrim(extension_from_path((string)$extensionSource), '.');
        }

        if ($useTemplate || $filePath === '') {
            $generated = generate_revision_path($projectRow, $asset, $entityContext, $nextVersion, $extension, $viewLabel, [], $classificationMap ?: ['view' => $viewLabel]);
            $filePath = $generated['relative_path'];
        }

        if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
            if ($projectRoot === '') {
                $error = 'Projekt-Root ist nicht gesetzt, Upload nicht möglich.';
            } else {
                $targetPath = $filePath !== '' ? $filePath : '/99_TEMP/' . kumiai_slug(pathinfo($uploadedFile['name'], PATHINFO_FILENAME)) . '.' . extension_from_path($uploadedFile['name']);
                $targetPath = sanitize_relative_path($targetPath);
                $targetPath = ensure_unique_path($projectRoot, $targetPath);
                $destination = $projectRoot . $targetPath;
                ensure_directory(dirname($destination));

                if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
                    $error = 'Upload konnte nicht gespeichert werden.';
                } else {
                    $filePath = $targetPath;
                    $meta = collect_file_metadata($destination);
                    $fileHash = $meta['file_hash'] ?? $fileHash;
                    $mime = $meta['mime_type'] ?? $mime;
                    $fileSize = $meta['file_size_bytes'] ?? $fileSize;
                    $width = $meta['width'];
                    $height = $meta['height'];
                    generate_thumbnail($projectId, $filePath, $destination);
                }
            }
        }

        if ($filePath !== '' && !$error) {
            $ensureUnique = !$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK;
            if ($ensureUnique && $projectRoot !== '') {
                $absoluteCandidate = $projectRoot . $filePath;
                if (!file_exists($absoluteCandidate)) {
                    $filePath = ensure_unique_path($projectRoot, $filePath);
                }
            }

            $absoluteExisting = $projectRoot . $filePath;
            if ($projectRoot !== '' && file_exists($absoluteExisting)) {
                $meta = collect_file_metadata($absoluteExisting);
                $fileHash = $meta['file_hash'] ?? $fileHash;
                $mime = $meta['mime_type'] ?? $mime;
                $fileSize = $meta['file_size_bytes'] ?? $fileSize;
                $width = $meta['width'];
                $height = $meta['height'];
                generate_thumbnail($projectId, $filePath, $absoluteExisting);
            }

            $stmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, width, height, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :width, :height, :created_by, NOW(), :review_status)');
            $stmt->execute([
                'asset_id' => $assetId,
                'version' => $nextVersion,
                'file_path' => $filePath,
                'file_hash' => $fileHash,
                'mime_type' => $mime,
                'file_size_bytes' => $fileSize,
                'width' => $width,
                'height' => $height,
                'created_by' => current_user()['id'],
                'review_status' => $status,
            ]);
            $revisionId = (int)$pdo->lastInsertId();

            // Revision classifications
            $revisionClassStmt = $pdo->prepare('INSERT INTO revision_classifications (revision_id, axis_id, value_key) VALUES (:revision_id, :axis_id, :value_key)');
            foreach ($axesForAsset as $axis) {
                $key = $axis['axis_key'];
                if (!isset($classificationMap[$key]) || $classificationMap[$key] === '') {
                    continue;
                }
                $revisionClassStmt->execute([
                    'revision_id' => $revisionId,
                    'axis_id' => $axis['id'],
                    'value_key' => $classificationMap[$key],
                ]);
            }

            $state = derive_classification_state($axesForAsset, $classificationMap);
            $inventoryStmt = $pdo->prepare('INSERT INTO file_inventory (project_id, file_path, file_hash, status, classification_state, asset_revision_id, last_seen_at, file_size_bytes, mime_type) VALUES (:project_id, :file_path, :file_hash, :status, :classification_state, :asset_revision_id, NOW(), :file_size_bytes, :mime_type) ON DUPLICATE KEY UPDATE file_hash = VALUES(file_hash), status = VALUES(status), classification_state = :classification_state_update, asset_revision_id = VALUES(asset_revision_id), last_seen_at = NOW(), file_size_bytes = VALUES(file_size_bytes), mime_type = VALUES(mime_type)');
            $inventoryStmt->execute([
                'project_id' => $projectId,
                'file_path' => $filePath,
                'file_hash' => $fileHash,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mime,
                'status' => 'linked',
                'classification_state' => $state,
                'classification_state_update' => $state,
                'asset_revision_id' => $revisionId,
            ]);
            $message = 'Revision gespeichert.';
        } elseif (!$error) {
            $error = 'Dateipfad erforderlich.';
        }
    }

    if ($action === 'review_revision') {
        if (!$canReview) {
            $error = 'Keine Berechtigung.';
        } else {
            $revId = (int)($_POST['revision_id'] ?? 0);
            $revStatus = $_POST['review_status'] ?? 'pending';
            $revNotes = trim($_POST['notes'] ?? '');

            if (in_array($revStatus, ['pending', 'approved', 'rejected'], true)) {
                $updateStmt = $pdo->prepare('UPDATE asset_revisions SET review_status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(), notes = :notes WHERE id = :id AND asset_id = :asset_id');
                $updateStmt->execute([
                    'status' => $revStatus,
                    'reviewed_by' => current_user()['id'],
                    'notes' => $revNotes,
                    'id' => $revId,
                    'asset_id' => $assetId,
                ]);
                $message = 'Review gespeichert.';
            }
        }
    }
}

// Revisions laden
$revisionsStmt = $pdo->prepare('SELECT r.*, u.display_name AS reviewed_by_name
                                FROM asset_revisions r
                                LEFT JOIN users u ON u.id = r.reviewed_by
                                WHERE r.asset_id = :asset_id
                                ORDER BY r.version DESC');
$revisionsStmt->execute(['asset_id' => $assetId]);
$revisions = $revisionsStmt->fetchAll();

$revisionIds = [];
$inventoryByRevision = [];
$inventoryIds = [];
if (!empty($revisions)) {
    $revisionIds = array_map(fn($rev) => (int)$rev['id'], $revisions);
    $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
    $invStmt = $pdo->prepare("SELECT * FROM file_inventory WHERE asset_revision_id IN ($placeholders)");
    $invStmt->execute($revisionIds);
    foreach ($invStmt->fetchAll() as $invRow) {
        $revId = (int)$invRow['asset_revision_id'];
        $inventoryByRevision[$revId] = $invRow;
        $inventoryIds[] = (int)$invRow['id'];
    }
}

$inventoryToRevision = [];
foreach ($inventoryByRevision as $revId => $invRow) {
    $inventoryToRevision[(int)$invRow['id']] = $revId;
}

$revisionThumbs = [];
foreach ($revisions as $revision) {
    $thumb = thumbnail_public_if_exists($projectId, $revision['file_path']);
    $absolute = $projectRoot . $revision['file_path'];
    if (!$thumb && $projectRoot !== '' && file_exists($absolute)) {
        $thumb = generate_thumbnail($projectId, $revision['file_path'], $absolute);
    }
    $revisionThumbs[(int)$revision['id']] = $thumb;
}

$auditsByRevision = [];
if (!empty($revisionIds) || !empty($inventoryIds)) {
    $clauses = [];
    $params = [];
    if (!empty($revisionIds)) {
        $clauses[] = 'revision_id IN (' . implode(',', array_fill(0, count($revisionIds), '?')) . ')';
        $params = array_merge($params, $revisionIds);
    }
    if (!empty($inventoryIds)) {
        $clauses[] = 'file_inventory_id IN (' . implode(',', array_fill(0, count($inventoryIds), '?')) . ')';
        $params = array_merge($params, $inventoryIds);
    }
    $auditSql = 'SELECT * FROM ai_audit_logs WHERE ' . implode(' OR ', $clauses) . ' ORDER BY created_at DESC LIMIT 200';
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute($params);
    foreach ($auditStmt->fetchAll() as $auditRow) {
        $revId = (int)($auditRow['revision_id'] ?? 0);
        if (!$revId && ($auditRow['file_inventory_id'] ?? null)) {
            $revId = $inventoryToRevision[(int)$auditRow['file_inventory_id']] ?? 0;
        }
        if ($revId) {
            if (!isset($auditsByRevision[$revId])) {
                $auditsByRevision[$revId] = [];
            }
            $auditsByRevision[$revId][] = $auditRow;
        }
    }
}

// Naming Hint Setup
$entityContext = null;
if ($asset['primary_entity_id']) {
    $entityContext = [
        'id' => $asset['primary_entity_id'],
        'name' => $asset['primary_entity_name'],
        'slug' => $asset['primary_entity_slug'],
        'type' => $asset['primary_entity_type'],
    ];
}
$nextVersion = 1;
if (!empty($revisions)) {
    $nextVersion = $revisions[0]['version'] + 1;
}
$assetForNaming = $asset;
$assetForNaming['asset_key'] = $asset['asset_key'] ?: $asset['name'];
$generated = generate_revision_path($projectRow, $assetForNaming, $entityContext, $nextVersion, 'png', $classificationMap['view'] ?? 'main', [], $classificationMap);
$namingContext = [
    'version' => $nextVersion,
    'suggested' => $generated['relative_path'],
    'template' => $generated['rule'],
    'context' => $generated['context'],
    'asset_type' => $asset['asset_type'],
    'classification' => $classificationMap,
];


render_header('Asset: ' . htmlspecialchars($asset['display_name'] ?? $asset['asset_key']));
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0"><?= htmlspecialchars($asset['display_name'] ?? $asset['asset_key']) ?></h1>
        <div class="small text-muted">Asset-Key: <code><?= htmlspecialchars($asset['asset_key']) ?></code></div>
    </div>
    <div class="d-flex gap-2">
        <a href="assets.php?project_id=<?= $projectId ?>" class="btn btn-outline-secondary btn-sm">Zurück zur Liste</a>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-light">Metadaten</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="update_asset">

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Status</label>
                        <select class="form-select form-select-sm" name="asset_status">
                            <option value="active" <?= $asset['status'] === 'active' ? 'selected' : '' ?>>active</option>
                            <option value="deprecated" <?= $asset['status'] === 'deprecated' ? 'selected' : '' ?>>deprecated</option>
                            <option value="archived" <?= $asset['status'] === 'archived' ? 'selected' : '' ?>>archived</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Display Name</label>
                        <input class="form-control form-control-sm" name="asset_display_name" value="<?= htmlspecialchars($asset['display_name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Type</label>
                        <select class="form-select form-select-sm" name="asset_type">
                            <?php foreach(['character_ref', 'background', 'scene_frame', 'concept', 'other'] as $t): ?>
                                <option value="<?= $t ?>" <?= $asset['asset_type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Entity</label>
                        <div>
                            <?php if ($asset['primary_entity_id']): ?>
                                <a href="entity_details.php?entity_id=<?= (int)$asset['primary_entity_id'] ?>">
                                    <?= htmlspecialchars($asset['primary_entity_name']) ?>
                                </a>
                                <span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($asset['primary_entity_type']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($axesForAsset)): ?>
                        <div class="mb-3 border-top pt-2">
                            <div class="small text-muted mb-2">Klassifizierung (definiert Asset-Key)</div>
                            <?php foreach ($axesForAsset as $axis): ?>
                                <div class="mb-2">
                                    <label class="form-label small mb-0" for="axis_<?= $axis['id'] ?>"><?= htmlspecialchars($axis['label']) ?></label>
                                    <?php
                                    $currentVal = $classificationMap[$axis['axis_key']] ?? '';
                                    if (!empty($axis['values'])):
                                    ?>
                                        <select class="form-select form-select-sm" name="axis_<?= $axis['id'] ?>" id="axis_<?= $axis['id'] ?>">
                                            <option value="">–</option>
                                            <?php foreach ($axis['values'] as $v): ?>
                                                <option value="<?= htmlspecialchars($v['value_key']) ?>" <?= $currentVal === $v['value_key'] ? 'selected' : '' ?>><?= htmlspecialchars($v['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input class="form-control form-control-sm" name="axis_<?= $axis['id'] ?>" id="axis_<?= $axis['id'] ?>" value="<?= htmlspecialchars($currentVal) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Beschreibung</label>
                        <textarea class="form-control form-control-sm" name="asset_description" rows="3"><?= htmlspecialchars($asset['description'] ?? '') ?></textarea>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-3" id="prepass-card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">AI Prepass (Subject)</div>
                    <div class="fw-semibold mb-0"><?= htmlspecialchars($prepassData['stage'] ?? 'SUBJECT_FIRST') ?></div>
                </div>
                <?php if ($canReview): ?>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="run-prepass-btn" data-asset="<?= $assetId ?>" data-revision="<?= !empty($revisions) ? (int)$revisions[0]['id'] : '' ?>">AI Prepass (Subject)</button>
                <?php endif; ?>
            </div>
            <div class="card-body" id="prepass-body">
                <?php if ($prepassData && ($prepassData['features'] ?? null)): ?>
                    <?php
                        $features = $prepassData['features'];
                        $priors = $prepassData['priors'] ?? [];
                        $confidencePrepass = $prepassData['confidence_overall'] ?? ($features['confidence']['overall'] ?? 0.0);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <div class="small text-muted">Primary Subject</div>
                            <div class="fw-semibold"><?= htmlspecialchars($features['primary_subject'] ?? 'unknown') ?></div>
                        </div>
                        <span class="badge bg-light text-dark border">Confidence <?= number_format((float)$confidencePrepass, 2) ?></span>
                    </div>
                    <div class="small mb-2 text-muted">Image-Kind: <strong><?= htmlspecialchars($features['image_kind'] ?? 'unknown') ?></strong> · Hintergrund: <strong><?= htmlspecialchars($features['background_type'] ?? 'unknown') ?></strong></div>
                    <div class="small mb-2">Subjects:
                        <?php if (!empty($features['subjects_present'])): ?>
                            <?= htmlspecialchars(implode(', ', $features['subjects_present'])) ?>
                        <?php else: ?>
                            <span class="text-muted">–</span>
                        <?php endif; ?>
                    </div>
                    <div class="small mb-2">Counts: H <?= (int)($features['counts']['humans'] ?? 0) ?> · A <?= (int)($features['counts']['animals'] ?? 0) ?> · O <?= (int)($features['counts']['objects'] ?? 0) ?></div>
                    <div class="small mb-2">Human Attributes:
                        <?php if (!empty($features['human_attributes']['present'])): ?>
                            <?= htmlspecialchars($features['human_attributes']['apparent_age'] ?? 'unknown') ?>,
                            <?= htmlspecialchars($features['human_attributes']['gender_presentation'] ?? 'unknown') ?>
                        <?php else: ?>
                            <span class="text-muted">none/unknown</span>
                        <?php endif; ?>
                    </div>
                    <div class="small mb-2">Notes:
                        <?php
                            $noteBadges = [];
                            if (!empty($features['notes']['is_single_character_fullbody'])) $noteBadges[] = 'Fullbody';
                            if (!empty($features['notes']['contains_multiple_panels'])) $noteBadges[] = 'Panels';
                            if (!empty($features['notes']['is_scene_establishing_shot'])) $noteBadges[] = 'Establishing Shot';
                        ?>
                        <?php if (!empty($noteBadges)): ?>
                            <?php foreach ($noteBadges as $label): ?>
                                <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($label) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">–</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($features['free_caption'])): ?>
                        <div class="small fst-italic text-muted mb-2">“<?= htmlspecialchars($features['free_caption']) ?>”</div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <div class="small text-muted mb-1">Soft Priors</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (['character','location','scene','prop','effect'] as $pKey): ?>
                                <span class="badge bg-light text-dark border">
                                    <?= $pKey ?>: <?= number_format((float)($priors[$pKey] ?? 0.0), 2) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="small text-muted">Stand: <?= htmlspecialchars($prepassData['updated_at'] ?? $prepassData['created_at'] ?? '') ?> · Modell: <?= htmlspecialchars($prepassData['model'] ?? '') ?></div>
                <?php else: ?>
                    <p class="text-muted small mb-2">Noch kein Prepass vorhanden. Der Lauf speichert Features und Soft-Priors für spätere Klassifizierung.</p>
                <?php endif; ?>
                <div class="small text-muted mt-2" id="prepass-status"></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-light">Neue Revision</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_revision">

                    <div class="mb-2">
                        <label class="form-label small mb-1">Datei hochladen</label>
                        <input class="form-control form-control-sm" type="file" name="revision_file" id="revision_file" accept="image/*">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small mb-1">oder Pfad (relativ)</label>
                        <input class="form-control form-control-sm" name="file_path" id="file_path" placeholder="<?= htmlspecialchars($namingContext['suggested']) ?>">
                    </div>

                    <div class="row g-2 mb-2">
                         <div class="col-6">
                            <label class="form-label small mb-1">View/Suffix</label>
                            <input class="form-control form-control-sm" name="view_label" id="view_label" value="<?= htmlspecialchars($classificationMap['view'] ?? 'main') ?>">
                         </div>
                         <div class="col-6">
                            <label class="form-label small mb-1">Ext</label>
                            <input class="form-control form-control-sm" name="file_extension" id="file_extension" value="png">
                         </div>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="enforce_template" id="enforce_template" checked>
                        <label class="form-check-label small" for="enforce_template">Template erzwingen</label>
                    </div>
                    <div class="small text-muted mb-3 fst-italic" id="naming_hint_text">
                        Vorschlag: <?= htmlspecialchars($namingContext['suggested']) ?>
                    </div>

                    <button type="submit" class="btn btn-sm btn-success w-100">Revision hinzufügen</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <h2 class="h5 mb-3">Revisions-Historie (<?= count($revisions) ?>)</h2>
        <?php if (empty($revisions)): ?>
            <div class="alert alert-light border">Keine Revisionen vorhanden.</div>
        <?php else: ?>
            <div class="list-group shadow-sm">
                <?php foreach ($revisions as $rev): ?>
                    <div class="list-group-item p-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-shrink-0" style="width: 100px; height: 100px; background-color: #f8f9fa;">
                                <?php $thumb = $revisionThumbs[(int)$rev['id']] ?? null; ?>
                                <?php if ($thumb): ?>
                                    <a href="<?= htmlspecialchars($thumb) ?>" target="_blank">
                                        <img src="<?= htmlspecialchars($thumb) ?>" class="w-100 h-100 object-fit-contain border rounded" alt="v<?= $rev['version'] ?>">
                                    </a>
                                <?php else: ?>
                                    <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted small">No Image</div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <h5 class="mb-1">Version <?= (int)$rev['version'] ?></h5>
                                    <span class="badge bg-<?= $rev['review_status'] === 'approved' ? 'success' : ($rev['review_status'] === 'rejected' ? 'danger' : 'warning text-dark') ?>">
                                        <?= htmlspecialchars($rev['review_status']) ?>
                                    </span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <code><?= htmlspecialchars($rev['file_path']) ?></code>
                                </div>
                                <div class="small mb-2">
                                    <span class="me-3"><i class="bi bi-hdd"></i> <?= format_file_size($rev['file_size_bytes']) ?></span>
                                    <span class="me-3"><i class="bi bi-aspect-ratio"></i> <?= $rev['width'] ? $rev['width'].'x'.$rev['height'] : '?' ?></span>
                                    <span><i class="bi bi-calendar"></i> <?= $rev['created_at'] ?></span>
                                </div>
                                <?php if ($rev['notes']): ?>
                                    <div class="alert alert-light border p-2 small mb-2">
                                        <strong>Notiz:</strong> <?= nl2br(htmlspecialchars($rev['notes'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canReview): ?>
                                    <form method="post" class="mt-2 row g-2 align-items-center">
                                        <input type="hidden" name="action" value="review_revision">
                                        <input type="hidden" name="revision_id" value="<?= (int)$rev['id'] ?>">
                                        <div class="col-auto">
                                            <select class="form-select form-select-sm" name="review_status">
                                                <option value="pending" <?= $rev['review_status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                                <option value="approved" <?= $rev['review_status'] === 'approved' ? 'selected' : '' ?>>approved</option>
                                                <option value="rejected" <?= $rev['review_status'] === 'rejected' ? 'selected' : '' ?>>rejected</option>
                                            </select>
                                        </div>
                                        <div class="col">
                                            <input type="text" class="form-control form-control-sm" name="notes" placeholder="Review Notiz" value="<?= htmlspecialchars($rev['notes'] ?? '') ?>">
                                        </div>
                                        <div class="col-auto">
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                                <?php
                                    $revId = (int)$rev['id'];
                                    $invRow = $inventoryByRevision[$revId] ?? null;
                                    $auditList = $auditsByRevision[$revId] ?? [];
                                    $latestAudit = $auditList[0] ?? null;
                                    $aiDisplay = $aiResults[$revId] ?? null;
                                    if (!$aiDisplay && $latestAudit) {
                                        $decoded = json_decode($latestAudit['output_payload'] ?? '', true);
                                        if (is_array($decoded)) {
                                            $aiDisplay = $decoded;
                                        }
                                    }
                                ?>
                                <div class="border-top pt-2 mt-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-semibold small mb-0">KI-Vorschläge &amp; Audit</div>
                                        <?php if ($canReview && $invRow): ?>
                                            <form method="post" class="d-flex align-items-center gap-2 mb-0">
                                                <input type="hidden" name="action" value="ai_classify_revision">
                                                <input type="hidden" name="revision_id" value="<?= $revId ?>">
                                                <button class="btn btn-sm btn-outline-primary" type="submit">KI-Lauf starten</button>
                                            </form>
                                        <?php elseif ($canReview): ?>
                                            <span class="badge bg-light text-dark border small">Kein Inventory-Mapping</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($aiDisplay && ($aiDisplay['success'] ?? true)): ?>
                                        <?php $decision = $aiDisplay['decision'] ?? []; ?>
                                        <div class="mb-2">
                                            <span class="badge bg-<?= ($decision['status'] ?? '') === 'auto_assigned' ? 'success' : 'warning text-dark' ?>">
                                                <?= htmlspecialchars($decision['status'] ?? 'needs_review') ?>
                                            </span>
                                            <span class="small text-muted ms-2"><?= htmlspecialchars($decision['reason'] ?? ($latestAudit['reason'] ?? '')) ?></span>
                                        </div>
                                        <dl class="row small mb-2">
                                            <dt class="col-sm-4">Confidence</dt>
                                            <dd class="col-sm-8"><?= number_format((float)($decision['overall_confidence'] ?? ($latestAudit['confidence'] ?? 0.0)), 3) ?></dd>
                                            <dt class="col-sm-4">Margin</dt>
                                            <dd class="col-sm-8"><?= number_format((float)($decision['score_margin'] ?? 0.0), 3) ?> (Runner-up <?= number_format((float)($decision['runner_up_score'] ?? 0.0), 3) ?>)</dd>
                                            <dt class="col-sm-4">Threshold</dt>
                                            <dd class="col-sm-8"><?= number_format((float)($decision['score_threshold'] ?? 0.0), 3) ?></dd>
                                        </dl>
                                        <?php if (!empty($aiDisplay['candidates'])): ?>
                                            <div class="list-group list-group-flush small mb-2">
                                                <?php foreach ($aiDisplay['candidates'] as $candidate): ?>
                                                    <div class="list-group-item px-2 py-2">
                                                        <div class="d-flex justify-content-between">
                                                            <strong><?= htmlspecialchars($candidate['label'] ?? $candidate['key'] ?? 'n/a') ?></strong>
                                                            <span class="badge bg-light text-dark border"><?= number_format((float)($candidate['score'] ?? 0.0), 3) ?></span>
                                                        </div>
                                                        <?php if (!empty($candidate['reason'])): ?>
                                                            <div class="text-muted"><?= htmlspecialchars($candidate['reason']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="small text-muted mb-1">Keine Kandidaten vorhanden.</p>
                                        <?php endif; ?>
                                    <?php elseif ($aiDisplay && !($aiDisplay['success'] ?? true)): ?>
                                        <div class="alert alert-warning small mb-2"><?= htmlspecialchars($aiDisplay['error'] ?? 'Unbekannter Fehler') ?></div>
                                    <?php else: ?>
                                        <p class="small text-muted mb-2">Noch kein KI-Lauf für diese Revision gespeichert.</p>
                                    <?php endif; ?>

                                    <?php if (!empty($auditList)): ?>
                                        <div class="small text-muted mb-1">Audit-Events</div>
                                        <div class="list-group list-group-flush small">
                                            <?php foreach (array_slice($auditList, 0, 3) as $audit): ?>
                                                <div class="list-group-item px-2 py-2">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="badge bg-<?= ($audit['status'] ?? '') === 'ok' ? 'success' : (($audit['status'] ?? '') === 'error' ? 'danger' : 'secondary') ?>">
                                                            <?= htmlspecialchars($audit['status'] ?? 'ok') ?>
                                                        </span>
                                                        <span class="text-muted"><?= htmlspecialchars($audit['created_at'] ?? '') ?></span>
                                                    </div>
                                                    <div class="mt-1">Aktion: <strong><?= htmlspecialchars($audit['action'] ?? '') ?></strong></div>
                                                    <?php if (($audit['confidence'] ?? null) !== null): ?>
                                                        <div>Confidence: <?= number_format((float)$audit['confidence'], 3) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($audit['error_message'])): ?>
                                                        <div class="text-danger">Fehler: <?= htmlspecialchars($audit['error_message']) ?></div>
                                                    <?php endif; ?>
                                                    <?php
                                                        $diff = json_decode($audit['diff_payload'] ?? '', true);
                                                        if ($diff):
                                                    ?>
                                                        <pre class="bg-light border p-2 mt-1 mb-0"><?= htmlspecialchars(json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const context = <?= json_encode($namingContext) ?>;
    const rules = <?= json_encode(default_naming_rules()) ?>;

    const viewInput = document.getElementById('view_label');
    const extInput = document.getElementById('file_extension');
    const filePathInput = document.getElementById('file_path');
    const hintText = document.getElementById('naming_hint_text');

    if (!viewInput || !extInput || !filePathInput) return;

    const slugify = (value) => {
        return (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
    };

    const renderPattern = (pattern, ctx) => {
        return pattern.replace(/\{([a-z_]+)\}/g, (_, key) => ctx[key] ?? `{${key}}`);
    };

    const updateHint = () => {
        const ext = (extInput.value || 'png').replace(/^\./, '');
        const currentCtx = {
            ...context.context,
            view: slugify(viewInput.value || 'main'),
            ext,
            version: context.version.toString().padStart(2, '0')
        };

        const rule = rules[context.asset_type] ?? rules.other;
        const folder = '/' + renderPattern(rule.folder, currentCtx).replace(/^\/+/, '');
        const fileName = renderPattern(rule.template, currentCtx);
        const suggested = folder.replace(/\/+$/, '') + '/' + fileName;

        const finalPath = suggested.replace(/\/+/g, '/');
        hintText.textContent = 'Vorschlag: ' + finalPath;

        // Auto-update path if it matches the previous suggestion or is empty
        // Simplified: just update if empty for now, or maybe always if "enforce template" is checked?
        // For now, let's just show the hint and let user click if they want (or maybe we need an "Apply" button like assets.php)
        // assets.php had a button. I didn't include one here, let's add logic to auto-fill if empty.
        if (filePathInput.value === '') {
             filePathInput.value = finalPath;
        }
    };

    ['input', 'change'].forEach(evt => {
        viewInput.addEventListener(evt, updateHint);
        extInput.addEventListener(evt, updateHint);
    });

    const prepassButton = document.getElementById('run-prepass-btn');
    const prepassStatus = document.getElementById('prepass-status');
    if (prepassButton && prepassStatus) {
        prepassButton.addEventListener('click', async () => {
            prepassButton.disabled = true;
            prepassStatus.textContent = 'Prepass wird ausgeführt...';
            try {
                const payload = { asset_id: parseInt(prepassButton.dataset.asset, 10) };
                if (prepassButton.dataset.revision) {
                    payload.revision_id = parseInt(prepassButton.dataset.revision, 10);
                }
                const response = await fetch('/api/v1/ai/prepass-subject.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();
                if (response.ok && (data.success ?? false)) {
                    prepassStatus.textContent = 'Prepass aktualisiert. Seite wird neu geladen...';
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    prepassStatus.textContent = 'Fehler: ' + (data.error || 'Unbekannter Fehler');
                }
            } catch (err) {
                prepassStatus.textContent = 'Fehler: ' + err;
            } finally {
                prepassButton.disabled = false;
            }
        });
    }

    // Initial call
    updateHint();
})();
</script>

<?php render_footer(); ?>
