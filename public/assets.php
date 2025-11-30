<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_login();

$message = null;
$error = null;

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Assets');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (int)$projects[0]['id'];
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectRow = $projectStmt->fetch();
if (!$projectRow) {
    render_header('Assets');
    echo '<div class="alert alert-danger">Projekt wurde nicht gefunden.</div>';
    render_footer();
    exit;
}
$projectRoot = rtrim($projectRow['root_path'], '/');
$projectContext = null;
foreach ($projects as $project) {
    if ((int)$project['id'] === $projectId) {
        $projectContext = $project;
        break;
    }
}
$canReview = $projectContext && in_array($projectContext['role'], ['owner', 'admin', 'editor'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_asset') {
    $name = trim($_POST['asset_name'] ?? '');
    $type = trim($_POST['asset_type'] ?? 'other');
    $primaryEntityId = (int)($_POST['primary_entity_id'] ?? 0);
    $description = trim($_POST['asset_description'] ?? '');
    if ($name !== '') {
        $stmt = $pdo->prepare('INSERT INTO assets (project_id, name, asset_type, primary_entity_id, description, status, created_by, created_at) VALUES (:project_id, :name, :asset_type, :primary_entity_id, :description, "active", :created_by, NOW())');
        $stmt->execute([
            'project_id' => $projectId,
            'name' => $name,
            'asset_type' => $type,
            'primary_entity_id' => $primaryEntityId ?: null,
            'description' => $description,
            'created_by' => current_user()['id'],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_revision') {
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $filePath = trim($_POST['file_path'] ?? '');
    $fileHash = trim($_POST['file_hash'] ?? '');
    $mime = trim($_POST['mime_type'] ?? 'application/octet-stream');
    $status = trim($_POST['review_status'] ?? 'pending');
    $useTemplate = isset($_POST['enforce_template']);
    $viewLabel = trim($_POST['view_label'] ?? 'main');
    $manualExtension = trim($_POST['file_extension'] ?? '');
    $uploadedFile = $_FILES['revision_file'] ?? null;
    $projectRoot = rtrim($projectRow['root_path'], '/');

    $fileSize = (int)($_POST['file_size_bytes'] ?? 0);
    $width = null;
    $height = null;

    if ($assetId > 0) {
        $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id = :asset_id');
        $versionStmt->execute(['asset_id' => $assetId]);
        $nextVersion = (int)$versionStmt->fetchColumn();
        $assetLookup = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON e.type_id = et.id WHERE a.id = :id AND a.project_id = :project_id');
        $assetLookup->execute(['id' => $assetId, 'project_id' => $projectId]);
        $assetContext = $assetLookup->fetch();
        $entityContext = null;
        if ($assetContext && $assetContext['primary_entity_id']) {
            $entityContext = [
                'id' => $assetContext['primary_entity_id'],
                'name' => $assetContext['primary_entity_name'],
                'slug' => $assetContext['primary_entity_slug'],
                'type' => $assetContext['primary_entity_type'],
            ];
        }

        $filePath = sanitize_relative_path($filePath);

        if ($useTemplate && $assetContext) {
            $extension = $manualExtension !== '' ? ltrim($manualExtension, '.') : '';
            if ($extension === '') {
                $extensionSource = $filePath !== '' ? $filePath : ($uploadedFile['name'] ?? 'png');
                $extension = ltrim(extension_from_path((string)$extensionSource), '.');
            }
            $generated = generate_revision_path($projectRow, $assetContext, $entityContext, $nextVersion, $extension, $viewLabel);
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
                $filePath = ensure_unique_path($projectRoot, $filePath);
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
            $inventoryStmt = $pdo->prepare('INSERT INTO file_inventory (project_id, file_path, file_hash, status, asset_revision_id, last_seen_at, file_size_bytes, mime_type) VALUES (:project_id, :file_path, :file_hash, :status, :asset_revision_id, NOW(), :file_size_bytes, :mime_type) ON DUPLICATE KEY UPDATE file_hash = VALUES(file_hash), status = VALUES(status), asset_revision_id = VALUES(asset_revision_id), last_seen_at = NOW(), file_size_bytes = VALUES(file_size_bytes), mime_type = VALUES(mime_type)');
            $inventoryStmt->execute([
                'project_id' => $projectId,
                'file_path' => $filePath,
                'file_hash' => $fileHash,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mime,
                'status' => 'linked',
                'asset_revision_id' => $revisionId,
            ]);
            $message = 'Revision gespeichert, Datei einsortiert und Metadaten aktualisiert.';
        } elseif (!$error) {
            $error = 'Bitte Dateipfad angeben oder Template/Upload nutzen.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_revision') {
    $revisionId = (int)($_POST['revision_id'] ?? 0);
    $status = $_POST['review_status'] ?? 'pending';
    $notes = trim($_POST['notes'] ?? '');
    $validStatus = ['pending', 'approved', 'rejected'];
    if (!in_array($status, $validStatus, true)) {
        $error = 'Ungültiger Review-Status.';
    } elseif (!$canReview) {
        $error = 'Keine Berechtigung für Reviews.';
    } else {
        $updateStmt = $pdo->prepare('UPDATE asset_revisions r JOIN assets a ON a.id = r.asset_id SET r.review_status = :status, r.reviewed_by = :reviewed_by, r.reviewed_at = NOW(), r.notes = :notes WHERE r.id = :id AND a.project_id = :project_id');
        $updateStmt->execute([
            'status' => $status,
            'reviewed_by' => current_user()['id'],
            'notes' => $notes,
            'id' => $revisionId,
            'project_id' => $projectId,
        ]);
        if ($updateStmt->rowCount() > 0) {
            $message = 'Review-Status gespeichert.';
        } else {
            $error = 'Revision nicht gefunden.';
        }
    }
}

$entitiesStmt = $pdo->prepare('SELECT id, name FROM entities WHERE project_id = :project_id ORDER BY name');
$entitiesStmt->execute(['project_id' => $projectId]);
$entities = $entitiesStmt->fetchAll();

$assetsStmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON e.type_id = et.id WHERE a.project_id = :project_id ORDER BY a.created_at DESC LIMIT 50');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$assetVersions = [];
if (!empty($assets)) {
    $assetIds = array_column($assets, 'id');
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $versionQuery = $pdo->prepare("SELECT asset_id, COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id IN ($placeholders) GROUP BY asset_id");
    $versionQuery->execute($assetIds);
    foreach ($versionQuery->fetchAll() as $row) {
        $assetVersions[(int)$row['asset_id']] = (int)$row['next_version'];
    }
}

$namingHints = [];
foreach ($assets as $asset) {
    $entity = null;
    if ($asset['primary_entity_id']) {
        $entity = [
            'id' => $asset['primary_entity_id'],
            'name' => $asset['primary_entity_name'],
            'slug' => $asset['primary_entity_slug'],
            'type' => $asset['primary_entity_type'],
        ];
    }
    $hintVersion = $assetVersions[(int)$asset['id']] ?? 1;
    $generated = generate_revision_path($projectRow, $asset, $entity, $hintVersion, 'png');
    $namingHints[(int)$asset['id']] = [
        'version' => $hintVersion,
        'suggested' => $generated['relative_path'],
        'template' => $generated['rule'],
        'context' => $generated['context'],
        'asset_type' => $asset['asset_type'],
    ];
}

$revisionsStmt = $pdo->prepare('SELECT r.*, a.name AS asset_name, u.display_name AS reviewed_by_name FROM asset_revisions r JOIN assets a ON a.id = r.asset_id LEFT JOIN users u ON u.id = r.reviewed_by WHERE a.project_id = :project_id ORDER BY r.created_at DESC, r.version DESC LIMIT 50');
$revisionsStmt->execute(['project_id' => $projectId]);
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

render_header('Assets');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Assets</h1>
        <small class="text-muted">Asset-Container mit Revisionen und Datei-Bezug.</small>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0" for="project_id">Projekt</label>
        <select class="form-select" name="project_id" id="project_id" onchange="this.form.submit()">
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int)$project['id'] ?>" <?= $projectId === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($project['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Assets</h2>
                <?php if (empty($assets)): ?>
                    <p class="text-muted">Noch keine Assets angelegt.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Typ</th>
                                    <th>Entity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assets as $asset): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($asset['name']) ?></td>
                                        <td><code><?= htmlspecialchars($asset['asset_type']) ?></code></td>
                                        <td><?= htmlspecialchars($asset['primary_entity_name'] ?? '–') ?></td>
                                        <td><?= htmlspecialchars($asset['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="h6">Neues Asset</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_asset">
                    <div class="mb-3">
                        <label class="form-label" for="asset_name">Name</label>
                        <input class="form-control" name="asset_name" id="asset_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="asset_type">Typ</label>
                        <select class="form-select" name="asset_type" id="asset_type">
                            <option value="character_ref">character_ref</option>
                            <option value="background">background</option>
                            <option value="scene_frame">scene_frame</option>
                            <option value="concept">concept</option>
                            <option value="other" selected>other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="primary_entity_id">Primäre Entity</label>
                        <select class="form-select" name="primary_entity_id" id="primary_entity_id">
                            <option value="">Keine</option>
                            <?php foreach ($entities as $entity): ?>
                                <option value="<?= (int)$entity['id'] ?>"><?= htmlspecialchars($entity['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="asset_description">Beschreibung</label>
                        <textarea class="form-control" name="asset_description" id="asset_description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Anlegen</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Letzte Revisionen</h2>
                <?php if (empty($revisions)): ?>
                    <p class="text-muted">Noch keine Revisionen vorhanden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Version</th>
                                    <th>Preview</th>
                                    <th>Datei</th>
                                    <th>Status</th>
                                    <th>Reviewer</th>
                                    <th>Notizen</th>
                                    <th class="text-end">Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revisions as $revision): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($revision['asset_name']) ?></td>
                                        <td>v<?= (int)$revision['version'] ?></td>
                                        <td>
                                            <?php $thumb = $revisionThumbs[(int)$revision['id']] ?? null; ?>
                                            <?php if ($thumb): ?>
                                                <img src="<?= htmlspecialchars($thumb) ?>" alt="Thumbnail" class="img-thumbnail" style="max-width: 80px; max-height: 80px;">
                                            <?php else: ?>
                                                <span class="text-muted">–</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($revision['file_path']) ?></code></td>
                                        <td>
                                            <span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($revision['review_status']) ?></span>
                                            <div class="small text-muted"><?= $revision['reviewed_at'] ? 'am ' . htmlspecialchars($revision['reviewed_at']) : 'offen' ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($revision['reviewed_by_name'] ?? '—') ?></td>
                                        <td class="small"><?= $revision['notes'] ? nl2br(htmlspecialchars($revision['notes'])) : '–' ?></td>
                                        <td class="text-end">
                                            <?php if ($canReview): ?>
                                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                                                    <input type="hidden" name="action" value="review_revision">
                                                    <input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>">
                                                    <select class="form-select form-select-sm w-auto" name="review_status">
                                                        <option value="pending" <?= $revision['review_status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                                        <option value="approved" <?= $revision['review_status'] === 'approved' ? 'selected' : '' ?>>approved</option>
                                                        <option value="rejected" <?= $revision['review_status'] === 'rejected' ? 'selected' : '' ?>>rejected</option>
                                                    </select>
                                                    <input type="text" class="form-control form-control-sm" name="notes" value="<?= htmlspecialchars($revision['notes'] ?? '') ?>" placeholder="Kommentar" aria-label="Review-Notiz">
                                                    <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">–</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="h6">Revision erfassen</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_revision">
                    <div class="mb-3">
                        <label class="form-label" for="asset_id">Asset</label>
                        <select class="form-select" name="asset_id" id="asset_id" required>
                            <option value="">Bitte wählen</option>
                            <?php foreach ($assets as $asset): ?>
                                <option value="<?= (int)$asset['id'] ?>"><?= htmlspecialchars($asset['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="file_path">Dateipfad (relativ)</label>
                        <div class="input-group">
                            <input class="form-control" name="file_path" id="file_path" placeholder="/01_CHARACTER/kei/ref_v01.png" required>
                            <button class="btn btn-outline-secondary" type="button" id="apply_template">Template einsetzen</button>
                        </div>
                        <div class="form-text" id="naming_hint">Pfadvorschlag erscheint nach Asset-Auswahl.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="revision_file">Datei hochladen</label>
                        <input class="form-control" type="file" name="revision_file" id="revision_file" accept="image/*">
                        <div class="form-text">Upload wird nach Template abgelegt und in Inventar/Revision eingetragen.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="view_label">View/Shot Label</label>
                            <input class="form-control" name="view_label" id="view_label" value="main" placeholder="front">
                            <div class="form-text">Fließt in den Platzhalter <code>{view}</code> ein.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="file_extension">Dateiendung</label>
                            <input class="form-control" name="file_extension" id="file_extension" value="png" list="known_extensions">
                            <datalist id="known_extensions">
                                <option value="png">
                                <option value="jpg">
                                <option value="psd">
                                <option value="clip">
                                <option value="tif">
                            </datalist>
                            <div class="form-text">Wird automatisch als <code>{ext}</code> genutzt.</div>
                        </div>
                    </div>
                    <div class="form-check mb-3 mt-2">
                        <input class="form-check-input" type="checkbox" name="enforce_template" id="enforce_template" checked>
                        <label class="form-check-label" for="enforce_template">Pfad beim Speichern nach Template ableiten</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="file_hash">Datei-Hash</label>
                        <input class="form-control" name="file_hash" id="file_hash" placeholder="sha256...">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="mime_type">MIME-Type</label>
                            <input class="form-control" name="mime_type" id="mime_type" value="image/png">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="file_size_bytes">Dateigröße (Bytes)</label>
                            <input class="form-control" type="number" name="file_size_bytes" id="file_size_bytes" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="review_status">Review-Status</label>
                        <select class="form-select" name="review_status" id="review_status">
                            <option value="pending">pending</option>
                            <option value="approved">approved</option>
                            <option value="rejected">rejected</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">Revision speichern</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const hintMap = <?= json_encode($namingHints) ?>;
    const rules = <?= json_encode(default_naming_rules()) ?>;
    const assetSelect = document.getElementById('asset_id');
    const viewInput = document.getElementById('view_label');
    const extInput = document.getElementById('file_extension');
    const filePathInput = document.getElementById('file_path');
    const hintBox = document.getElementById('naming_hint');
    const applyBtn = document.getElementById('apply_template');

    if (!assetSelect || !viewInput || !extInput || !filePathInput || !hintBox) {
        return;
    }

    const slugify = (value) => {
        return (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
    };

    const renderPattern = (pattern, context) => {
        return pattern.replace(/\{([a-z_]+)\}/g, (_, key) => context[key] ?? `{${key}}`);
    };

    const buildContext = (assetId) => {
        const base = hintMap[assetId];
        if (!base) {
            return null;
        }
        const ext = (extInput.value || 'png').replace(/^\./, '');
        const version = (base.version ?? base.context.version ?? '01').toString().padStart(2, '0');
        return {
            ...base.context,
            view: slugify(viewInput.value || 'main'),
            ext,
            version,
        };
    };

    const updateHint = () => {
        const assetId = assetSelect.value;
        if (!assetId || !hintMap[assetId]) {
            hintBox.textContent = 'Pfadvorschlag erscheint nach Asset-Auswahl.';
            return null;
        }
        const context = buildContext(assetId);
        const rule = rules[hintMap[assetId].asset_type] ?? rules.other;
        const folder = '/' + renderPattern(rule.folder, context).replace(/^\/+/, '');
        const fileName = renderPattern(rule.template, context);
        const suggested = folder.replace(/\/+$/, '') + '/' + fileName;
        hintBox.textContent = 'Pfadvorschlag: ' + suggested;
        if (!filePathInput.value) {
            filePathInput.value = suggested;
        }
        return suggested.replace(/\/+/g, '/');
    };

    if (applyBtn) {
        applyBtn.addEventListener('click', () => {
            const suggestion = updateHint();
            if (suggestion) {
                filePathInput.value = suggestion;
            }
        });
    }

    ['change', 'input'].forEach((evt) => {
        assetSelect.addEventListener(evt, updateHint);
        viewInput.addEventListener(evt, updateHint);
        extInput.addEventListener(evt, updateHint);
    });

    updateHint();
})();
</script>
<?php render_footer(); ?>
