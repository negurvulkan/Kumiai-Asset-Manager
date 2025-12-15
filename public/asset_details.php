<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/classification.php';
require_login();

$message = null;
$error = null;

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

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_asset') {
        $displayName = trim($_POST['asset_display_name'] ?? '');
        $type = trim($_POST['asset_type'] ?? 'other');
        $description = trim($_POST['asset_description'] ?? '');
        $status = trim($_POST['asset_status'] ?? 'active');
        $validStatus = ['active', 'deprecated', 'archived'];

        if (in_array($status, $validStatus, true)) {
            $updateStmt = $pdo->prepare('UPDATE assets SET display_name = :display_name, asset_type = :asset_type, description = :description, status = :status WHERE id = :id');
            $updateStmt->execute([
                'display_name' => $displayName !== '' ? $displayName : null,
                'asset_type' => $type,
                'description' => $description,
                'status' => $status,
                'id' => $assetId,
            ]);
            $message = 'Asset aktualisiert.';
            // Refresh Asset Data
            $stmt->execute(['id' => $assetId]);
            $asset = $stmt->fetch();
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
        $classificationMap = [];
        $axesForAsset = [];

        if ($asset['primary_entity_id']) {
            $entityContext = [
                'id' => $asset['primary_entity_id'],
                'name' => $asset['primary_entity_name'],
                'slug' => $asset['primary_entity_slug'],
                'type' => $asset['primary_entity_type'],
            ];
            $axesForAsset = load_axes_for_entity($pdo, $asset['primary_entity_type'] ?? '');
        }
        $classificationMap = fetch_asset_classifications($pdo, $assetId);

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

$revisionThumbs = [];
foreach ($revisions as $revision) {
    $thumb = thumbnail_public_if_exists($projectId, $revision['file_path']);
    $absolute = $projectRoot . $revision['file_path'];
    if (!$thumb && $projectRoot !== '' && file_exists($absolute)) {
        $thumb = generate_thumbnail($projectId, $revision['file_path'], $absolute);
    }
    $revisionThumbs[(int)$revision['id']] = $thumb;
}

// Naming Hint Setup
$entityContext = null;
$classificationMap = fetch_asset_classifications($pdo, $assetId);
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

                    <?php if (!empty($classificationMap)): ?>
                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">Klassifizierung</label>
                        <ul class="list-unstyled small ps-2 border-start">
                            <?php foreach ($classificationMap as $axis => $val): ?>
                                <li><strong class="text-muted"><?= htmlspecialchars($axis) ?>:</strong> <?= htmlspecialchars($val) ?></li>
                            <?php endforeach; ?>
                        </ul>
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

    // Initial call
    updateHint();
})();
</script>

<?php render_footer(); ?>
