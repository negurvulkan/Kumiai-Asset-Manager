<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/classification.php';
require_login();

function parse_selected_link_ids(): array
{
    $raw = $_POST['selected_links'] ?? [];
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : explode(',', $raw);
    }

    $ids = [];
    foreach ((array)$raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}

$entityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$entityStmt = $pdo->prepare('SELECT e.*, t.name AS type_name, t.id AS type_id FROM entities e JOIN entity_types t ON t.id = e.type_id WHERE e.id = :id');
$entityStmt->execute(['id' => $entityId]);
$entity = $entityStmt->fetch();
if (!$entity) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-danger">Entity nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectId = (int)$entity['project_id'];
$projectRow = null;
foreach ($projects as $project) {
    if ((int)$project['id'] === $projectId) {
        $projectRow = $project;
        break;
    }
}
if (!$projectRow) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-danger">Keine Berechtigung für dieses Projekt.</div>';
    render_footer();
    exit;
}

$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectFull = $projectStmt->fetch();
$projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

$message = null;
$error = null;

$axes = load_axes_for_entity($pdo, $entity['type_name'] ?? '');
$classInputs = [];

$selectedLinks = parse_selected_link_ids();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'classify' && !empty($selectedLinks)) {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $newAssetName = trim($_POST['new_asset_name'] ?? '');
        $newAssetType = trim($_POST['new_asset_type'] ?? 'concept');
        $newAssetDescription = trim($_POST['new_asset_description'] ?? '');
        $classInputs = [];

        foreach ($axes as $axis) {
            $inputKey = 'axis_' . $axis['id'];
            $value = trim($_POST[$inputKey] ?? '');
            if ($value === '') {
                continue;
            }
            $valueKey = !empty($axis['values']) ? $value : kumiai_slug($value);
            $classInputs[$axis['axis_key']] = $valueKey;
        }

        if ($assetId <= 0 && $newAssetName === '') {
            $error = 'Bitte bestehendes Asset wählen oder ein neues Asset benennen.';
        }

        if (!$error && $assetId <= 0 && $newAssetName !== '') {
            $assetInsert = $pdo->prepare('INSERT INTO assets (project_id, name, asset_type, primary_entity_id, description, status, created_by, created_at) VALUES (:project_id, :name, :asset_type, :primary_entity_id, :description, "active", :created_by, NOW())');
            $assetInsert->execute([
                'project_id' => $projectId,
                'name' => $newAssetName,
                'asset_type' => $newAssetType ?: 'concept',
                'primary_entity_id' => $entityId,
                'description' => $newAssetDescription,
                'created_by' => current_user()['id'],
            ]);
            $assetId = (int)$pdo->lastInsertId();
            $message = 'Asset erstellt.';
        }

        $assetRow = null;
        if ($assetId > 0) {
            $assetStmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON et.id = e.type_id WHERE a.id = :id AND a.project_id = :project_id');
            $assetStmt->execute(['id' => $assetId, 'project_id' => $projectId]);
            $assetRow = $assetStmt->fetch();
            if (!$assetRow) {
                $error = 'Asset nicht gefunden oder gehört nicht zum Projekt.';
            }
        }

        if (!$error && $assetRow) {
            $placeholders = implode(',', array_fill(0, count($selectedLinks), '?'));
            $fileStmt = $pdo->prepare("SELECT fi.*, l.id AS link_id FROM entity_file_links l JOIN file_inventory fi ON fi.id = l.file_inventory_id WHERE l.entity_id = ? AND fi.project_id = ? AND l.id IN ($placeholders)");
            $params = array_merge([$entityId, $projectId], $selectedLinks);
            $fileStmt->execute($params);
            $files = $fileStmt->fetchAll();

            if (empty($files)) {
                $error = 'Keine passenden Entity-Dateien gefunden.';
            } else {
                $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) AS max_version FROM asset_revisions WHERE asset_id = :asset_id');
                $versionStmt->execute(['asset_id' => $assetId]);
                $nextVersion = ((int)$versionStmt->fetchColumn()) + 1;
                $revisionClassStmt = $pdo->prepare('INSERT INTO revision_classifications (revision_id, axis_id, value_key) VALUES (:revision_id, :axis_id, :value_key)');

                foreach ($files as $file) {
                    $targetPath = sanitize_relative_path($file['file_path']);
                    $meta = collect_file_metadata($projectRoot . $file['file_path']);
                    $extension = extension_from_path($file['file_path']);
                    $viewLabel = $classInputs['view'] ?? 'main';
                    $generated = generate_revision_path($projectFull, $assetRow, ['id' => $entityId, 'name' => $entity['name'], 'slug' => $entity['slug'], 'type' => $entity['type_name']], $nextVersion, $extension, $viewLabel, [], $classInputs);
                    $targetPath = sanitize_relative_path($generated['relative_path']);

                    $source = $projectRoot . $file['file_path'];
                    $destination = $projectRoot . $targetPath;
                    $finalTarget = $targetPath;

                    if ($projectRoot !== '' && file_exists($source)) {
                        ensure_directory(dirname($destination));
                        $finalTarget = ensure_unique_path($projectRoot, $targetPath);
                        $destination = $projectRoot . $finalTarget;
                        ensure_directory(dirname($destination));
                        if (!@rename($source, $destination)) {
                            $error = sprintf('Datei %s konnte nicht verschoben werden.', $file['file_path']);
                        } else {
                            $meta = collect_file_metadata($destination);
                            generate_thumbnail($projectId, $finalTarget, $destination);
                        }
                    }

                    if ($error) {
                        break;
                    }

                    $revStmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, width, height, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :width, :height, :created_by, NOW(), "pending")');
                    $revStmt->execute([
                        'asset_id' => $assetId,
                        'version' => $nextVersion,
                        'file_path' => $finalTarget,
                        'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
                        'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
                        'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                        'created_by' => current_user()['id'],
                    ]);
                    $revisionId = (int)$pdo->lastInsertId();

                    foreach ($axes as $axis) {
                        $inputKey = 'axis_' . $axis['id'];
                        $value = trim($_POST[$inputKey] ?? '');
                        if ($value === '') {
                            continue;
                        }
                        $valueKey = !empty($axis['values']) ? $value : kumiai_slug($value);
                        $revisionClassStmt->execute([
                            'revision_id' => $revisionId,
                            'axis_id' => $axis['id'],
                            'value_key' => $valueKey,
                        ]);
                    }

                    $state = derive_classification_state($axes, $classInputs);
                    $updateInventory = $pdo->prepare('UPDATE file_inventory SET status = "linked", classification_state = :state, asset_revision_id = :revision_id, file_path = :file_path, file_hash = :file_hash, mime_type = :mime_type, file_size_bytes = :file_size_bytes, last_seen_at = NOW() WHERE id = :id');
                    $updateInventory->execute([
                        'state' => $state,
                        'revision_id' => $revisionId,
                        'file_path' => $finalTarget,
                        'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
                        'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
                        'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
                        'id' => $file['id'],
                    ]);

                    $nextVersion++;
                }

                if (!$error) {
                    $message = ($message ? $message . ' ' : '') . sprintf('%d Datei(en) klassifiziert und als Revision gespeichert.', count($files));
                }
            }
        }
    }
}

$linkStmt = $pdo->prepare('SELECT fi.*, l.id AS link_id, l.notes FROM entity_file_links l JOIN file_inventory fi ON fi.id = l.file_inventory_id WHERE l.entity_id = :entity_id AND fi.project_id = :project_id AND fi.classification_state <> "fully_classified" ORDER BY fi.last_seen_at DESC');
$linkStmt->execute(['entity_id' => $entityId, 'project_id' => $projectId]);
$links = $linkStmt->fetchAll();

$assetsStmt = $pdo->prepare('SELECT id, name, asset_type FROM assets WHERE project_id = :project_id ORDER BY name');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$inventoryThumbs = [];
foreach ($links as $file) {
    $thumb = thumbnail_public_if_exists($projectId, $file['file_path']);
    $absolutePath = $projectRoot . $file['file_path'];
    if (!$thumb && $projectRoot !== '' && file_exists($absolutePath)) {
        $thumb = generate_thumbnail($projectId, $file['file_path'], $absolutePath, 200);
    }
    $inventoryThumbs[(int)$file['id']] = $thumb;
}

if (empty($selectedLinks) && !empty($links)) {
    $selectedLinks = [(int)$links[0]['link_id']];
}

$selectedFile = null;
foreach ($links as $file) {
    if ((int)$file['link_id'] === (int)($selectedLinks[0] ?? 0)) {
        $selectedFile = $file;
        break;
    }
}

$selectedMeta = null;
$selectedPreview = null;
if ($selectedFile) {
    $selectedPreview = $inventoryThumbs[(int)$selectedFile['id']] ?? null;
    $absolute = $projectRoot . $selectedFile['file_path'];
    if ($projectRoot !== '' && file_exists($absolute)) {
        $selectedMeta = collect_file_metadata($absolute);
    }
}

render_header('Entity Files');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Unklassifizierte Dateien · <?= htmlspecialchars($entity['name']) ?></h1>
        <small class="text-muted">Entity-first Workflow mit Schritt-für-Schritt-Klassifizierung.</small>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="/entities.php?project_id=<?= (int)$projectId ?>">Zurück zu Entities</a>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <form method="post" id="entity-bulk-form" class="card shadow-sm h-100">
            <input type="hidden" name="action" id="entity-bulk-action" value="">
            <input type="hidden" name="selected_links" id="entity-bulk-selected" value="">
            <div class="card-header">
                <div class="fw-semibold">Dateien dieser Entity (<?= count($links) ?>)</div>
                <small class="text-muted">Status ≠ fully_classified</small>
            </div>
            <div class="card-body p-0" style="max-height: 70vh; overflow-y: auto;">
                <?php if (empty($links)): ?>
                    <div class="p-3 text-muted">Keine offenen Dateien für diese Entity.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($links as $file): ?>
                            <?php $checked = in_array((int)$file['link_id'], $selectedLinks, true); ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <input class="form-check-input entity-file-checkbox" type="checkbox" value="<?= (int)$file['link_id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                <div class="flex-shrink-0">
                                    <?php $thumb = $inventoryThumbs[(int)$file['id']] ?? null; ?>
                                    <?php if ($thumb): ?>
                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="Preview" class="inventory-thumb rounded border">
                                    <?php else: ?>
                                        <div class="inventory-thumb placeholder rounded border d-flex align-items-center justify-content-center bg-light text-muted">n/a</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small mb-1">#<?= (int)$file['id'] ?> · <code><?= htmlspecialchars($file['file_path']) ?></code></div>
                                    <div class="small text-muted">State: <?= htmlspecialchars($file['classification_state']) ?> · Last seen <?= htmlspecialchars($file['last_seen_at']) ?></div>
                                    <?php if (!empty($file['notes'])): ?>
                                        <div class="small text-muted">Notiz: <?= htmlspecialchars($file['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <p class="small text-muted mb-2">Wähle Dateien und klassifiziere sie rechts.</p>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <div class="fw-semibold">Preview &amp; Metadaten</div>
                <small class="text-muted">Aus der aktuellen Auswahl.</small>
            </div>
            <div class="card-body">
                <?php if (!$selectedFile): ?>
                    <p class="text-muted">Wähle eine Datei aus der Liste links.</p>
                <?php else: ?>
                    <div class="mb-3 text-center">
                        <?php if ($selectedPreview): ?>
                            <img src="<?= htmlspecialchars($selectedPreview) ?>" alt="Preview" class="img-fluid rounded" style="max-height: 320px;">
                        <?php else: ?>
                            <div class="text-muted">Keine Vorschau verfügbar.</div>
                        <?php endif; ?>
                    </div>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Pfad</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars($selectedFile['file_path']) ?></code></dd>
                        <dt class="col-sm-4">State</dt>
                        <dd class="col-sm-8"><span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($selectedFile['classification_state']) ?></span></dd>
                        <dt class="col-sm-4">Hash</dt>
                        <dd class="col-sm-8"><span class="small text-monospace"><?= htmlspecialchars($selectedFile['file_hash'] ?? '') ?></span></dd>
                        <dt class="col-sm-4">MIME</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars($selectedMeta['mime_type'] ?? ($selectedFile['mime_type'] ?? 'unbekannt')) ?></dd>
                        <dt class="col-sm-4">Auflösung</dt>
                        <dd class="col-sm-8">
                            <?php if (($selectedMeta['width'] ?? null) && ($selectedMeta['height'] ?? null)): ?>
                                <?= (int)$selectedMeta['width'] ?> × <?= (int)$selectedMeta['height'] ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card shadow-sm">
            <div class="card-header">
                <div class="fw-semibold">Schrittweise Klassifizierung</div>
                <small class="text-muted">Outfit/Pose/View oder frei definierte Achsen.</small>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="classify">
                    <input type="hidden" name="selected_links" class="entity-selected-target" value="">
                    <?php if (empty($axes)): ?>
                        <p class="text-muted small">Keine Achsen für diesen Entity-Typ konfiguriert.</p>
                    <?php else: ?>
                        <?php foreach ($axes as $axis): ?>
                            <div class="mb-2">
                                <label class="form-label" for="axis_<?= (int)$axis['id'] ?>"><?= htmlspecialchars($axis['label']) ?></label>
                                <?php if (!empty($axis['values'])): ?>
                                    <select class="form-select form-select-sm" name="axis_<?= (int)$axis['id'] ?>" id="axis_<?= (int)$axis['id'] ?>">
                                        <option value="">–</option>
                                        <?php foreach ($axis['values'] as $value): ?>
                                            <option value="<?= htmlspecialchars($value['value_key']) ?>"><?= htmlspecialchars($value['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control form-control-sm" name="axis_<?= (int)$axis['id'] ?>" id="axis_<?= (int)$axis['id'] ?>" placeholder="Wert">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <hr>
                    <div class="mb-2">
                        <label class="form-label" for="asset_id">Bestehendes Asset</label>
                        <select class="form-select form-select-sm" name="asset_id" id="asset_id">
                            <option value="">–</option>
                            <?php foreach ($assets as $asset): ?>
                                <option value="<?= (int)$asset['id'] ?>"><?= htmlspecialchars($asset['name']) ?> (<?= htmlspecialchars($asset['asset_type']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="new_asset_name">Neues Asset anlegen</label>
                        <input class="form-control form-control-sm" name="new_asset_name" id="new_asset_name" placeholder="z. B. Outfit: Sommeruniform">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="new_asset_type">Asset-Typ</label>
                        <select class="form-select form-select-sm" name="new_asset_type" id="new_asset_type">
                            <option value="concept">concept</option>
                            <option value="outfit_ref">outfit_ref</option>
                            <option value="character_ref">character_ref</option>
                            <option value="scene_frame">scene_frame</option>
                            <option value="other">other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_asset_description">Beschreibung</label>
                        <textarea class="form-control form-control-sm" name="new_asset_description" id="new_asset_description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Auswahl klassifizieren &amp; speichern</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const checkboxes = document.querySelectorAll('.entity-file-checkbox');
    const targetInput = document.querySelector('.entity-selected-target');
    const previewInput = document.getElementById('entity-bulk-selected');

    function syncSelection() {
        const ids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
        const value = ids.join(',');
        if (targetInput) targetInput.value = value;
        if (previewInput) previewInput.value = value;
    }

    checkboxes.forEach(cb => cb.addEventListener('change', syncSelection));
    syncSelection();
})();
</script>
<?php render_footer(); ?>
