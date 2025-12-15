<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/classification.php';
require_login();

function parse_selected_inventory_ids(): array
{
    $raw = $_POST['selected_ids'] ?? [];
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

function load_asset_with_entity(PDO $pdo, int $projectId, int $assetId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON e.type_id = et.id WHERE a.id = :id AND a.project_id = :project_id');
    $stmt->execute(['id' => $assetId, 'project_id' => $projectId]);
    $asset = $stmt->fetch();

    return $asset ?: null;
}

function link_inventory_batch(PDO $pdo, array $projectRow, int $projectId, array $inventoryIds, int $assetId, string $viewLabel, string $manualExtension, bool $applyTemplate): array
{
    $result = ['linked' => 0, 'errors' => []];
    if (empty($inventoryIds) || $assetId <= 0) {
        return $result;
    }

    $asset = load_asset_with_entity($pdo, $projectId, $assetId);
    if (!$asset) {
        $result['errors'][] = 'Asset nicht gefunden.';
        return $result;
    }

    $placeholders = implode(',', array_fill(0, count($inventoryIds), '?'));
    $inventoryStmt = $pdo->prepare("SELECT * FROM file_inventory WHERE project_id = ? AND id IN ($placeholders)");
    $inventoryStmt->execute(array_merge([$projectId], $inventoryIds));
    $files = $inventoryStmt->fetchAll();
    if (empty($files)) {
        return $result;
    }

    $projectRoot = rtrim($projectRow['root_path'], '/');
    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) AS max_version FROM asset_revisions WHERE asset_id = :asset_id');
    $versionStmt->execute(['asset_id' => $assetId]);
    $nextVersion = ((int)$versionStmt->fetchColumn()) + 1;

    $entityContext = null;
    if ($asset['primary_entity_id']) {
        $entityContext = [
            'id' => $asset['primary_entity_id'],
            'name' => $asset['primary_entity_name'],
            'slug' => $asset['primary_entity_slug'],
            'type' => $asset['primary_entity_type'],
        ];
    }

    foreach ($files as $file) {
        $targetPath = sanitize_relative_path($file['file_path']);
        $meta = collect_file_metadata($projectRoot . $file['file_path']);

        if ($applyTemplate) {
            $extension = $manualExtension !== '' ? ltrim($manualExtension, '.') : extension_from_path($file['file_path']);
            $generated = generate_revision_path($projectRow, $asset, $entityContext, $nextVersion, $extension, $viewLabel, [], ['view' => $viewLabel]);
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
                    $result['errors'][] = sprintf('Datei %s konnte nicht verschoben werden.', $file['file_path']);
                    $finalTarget = $file['file_path'];
                } else {
                    $meta = collect_file_metadata($destination);
                    generate_thumbnail($projectId, $finalTarget, $destination);
                }
            }
            $targetPath = $finalTarget;
        }

        $absoluteFinal = $projectRoot . $targetPath;
        if ($projectRoot !== '' && file_exists($absoluteFinal)) {
            $meta = collect_file_metadata($absoluteFinal);
            generate_thumbnail($projectId, $targetPath, $absoluteFinal);
        }

        $revStmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, width, height, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :width, :height, :created_by, NOW(), "pending")');
        $revStmt->execute([
            'asset_id' => $assetId,
            'version' => $nextVersion,
            'file_path' => $targetPath,
            'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
            'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
            'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
            'width' => $meta['width'],
            'height' => $meta['height'],
            'created_by' => current_user()['id'],
        ]);
        $revisionId = (int)$pdo->lastInsertId();

        $updateInventory = $pdo->prepare('UPDATE file_inventory SET status = "linked", classification_state = "fully_classified", asset_revision_id = :revision_id, file_path = :file_path, file_hash = :file_hash, mime_type = :mime_type, file_size_bytes = :file_size_bytes, last_seen_at = NOW() WHERE id = :id');
        $updateInventory->execute([
            'revision_id' => $revisionId,
            'file_path' => $targetPath,
            'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
            'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
            'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
            'id' => $file['id'],
        ]);

        $result['linked']++;
        $nextVersion++;
    }

    return $result;
}

$message = null;
$error = null;
$notices = [];

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Files');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (int)$projects[0]['id'];
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectRow = $projectStmt->fetch();
if (!$projectRow) {
    render_header('Files');
    echo '<div class="alert alert-danger">Projekt nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectRole = null;
foreach ($projects as $projectMeta) {
    if ((int)$projectMeta['id'] === $projectId) {
        $projectRole = $projectMeta['role'];
        break;
    }
}
$projectRow['role'] = $projectRole;
$projectRoot = rtrim($projectRow['root_path'], '/');
$canReview = in_array($projectRole, ['owner', 'admin', 'editor'], true);

$selectedIds = parse_selected_inventory_ids();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'scan') {
        if (!user_can_manage_project($projectRow)) {
            $error = 'Nur Owner/Admin dürfen den Scanner starten.';
        } else {
            $result = scan_project($pdo, $projectId);
            $message = $result['message'];
        }
    }

    if ($action === 'mark_orphan' && !empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$projectId], $selectedIds);
        $stmt = $pdo->prepare("UPDATE file_inventory SET status = 'orphaned' WHERE project_id = ? AND id IN ($placeholders)");
        $stmt->execute($params);
        $message = 'Ausgewählte Dateien als orphaned markiert.';
    }

    if ($action === 'assign_entity' && !empty($selectedIds)) {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $note = trim($_POST['entity_note'] ?? '');
        $entityStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM entities e JOIN entity_types t ON t.id = e.type_id WHERE e.id = :id AND e.project_id = :project_id');
        $entityStmt->execute(['id' => $entityId, 'project_id' => $projectId]);
        $entity = $entityStmt->fetch();
        if (!$entity) {
            $error = 'Entity nicht gefunden.';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $insertLink = $pdo->prepare('INSERT IGNORE INTO entity_file_links (entity_id, file_inventory_id, notes, created_at) VALUES (:entity_id, :file_inventory_id, :notes, NOW())');
            $updateInventory = $pdo->prepare("UPDATE file_inventory SET classification_state = 'entity_only' WHERE id IN ($placeholders) AND project_id = ?");
            foreach ($selectedIds as $id) {
                $insertLink->execute([
                    'entity_id' => $entityId,
                    'file_inventory_id' => $id,
                    'notes' => $note,
                ]);
            }
            $updateInventory->execute(array_merge($selectedIds, [$projectId]));
            $message = sprintf('%d Datei(en) zur Entity "%s" zugeordnet.', count($selectedIds), $entity['name']);
        }
    }

    if ($action === 'link_asset' && $canReview) {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $viewLabel = trim($_POST['view_label'] ?? 'scan');
        $manualExtension = trim($_POST['file_extension'] ?? '');
        $applyTemplate = isset($_POST['apply_template']);
        if ($assetId > 0 && !empty($selectedIds)) {
            $result = link_inventory_batch($pdo, $projectRow, $projectId, $selectedIds, $assetId, $viewLabel, $manualExtension, $applyTemplate);
            if ($result['linked'] > 0) {
                $message = sprintf('%d Datei(en) verknüpft und als Revision gespeichert.', $result['linked']);
            }
            if (!empty($result['errors'])) {
                $error = implode(' ', $result['errors']);
            }
        } else {
            $error = 'Bitte Asset und mindestens eine Datei wählen.';
        }
    }

    if ($action === 'create_entity') {
        $name = trim($_POST['entity_name'] ?? '');
        $typeId = (int)($_POST['type_id'] ?? 0);
        $metadataInput = trim($_POST['metadata_json'] ?? '');
        $metadataJson = '{}';
        if ($metadataInput !== '') {
            $decoded = json_decode($metadataInput, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Metadata-JSON ist ungültig.';
            } else {
                $metadataJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (!$error && $name !== '' && $typeId > 0) {
            $stmt = $pdo->prepare('INSERT INTO entities (project_id, type_id, name, slug, description, metadata_json, created_at) VALUES (:project_id, :type_id, :name, :slug, :description, :metadata_json, NOW())');
            $stmt->execute([
                'project_id' => $projectId,
                'type_id' => $typeId,
                'name' => $name,
                'slug' => kumiai_slug($name),
                'description' => trim($_POST['entity_description'] ?? ''),
                'metadata_json' => $metadataJson,
            ]);
            $message = 'Neue Entity im Review erstellt.';
        }
    }

    if ($action === 'create_asset' && $canReview) {
        $name = trim($_POST['asset_name'] ?? '');
        $type = trim($_POST['asset_type'] ?? 'other');
        $primaryEntityId = (int)($_POST['primary_entity_id'] ?? 0);
        $description = trim($_POST['asset_description'] ?? '');
        $linkSelected = isset($_POST['link_selected_files']);
        $viewLabel = trim($_POST['view_label'] ?? 'scan');
        $manualExtension = trim($_POST['file_extension'] ?? '');
        $applyTemplate = isset($_POST['apply_template']);

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
            $newAssetId = (int)$pdo->lastInsertId();
            $message = 'Asset erstellt.';

            if ($linkSelected && !empty($selectedIds)) {
                $result = link_inventory_batch($pdo, $projectRow, $projectId, $selectedIds, $newAssetId, $viewLabel, $manualExtension, $applyTemplate);
                if ($result['linked'] > 0) {
                    $message = sprintf('Asset erstellt und %d Datei(en) verknüpft.', $result['linked']);
                }
                if (!empty($result['errors'])) {
                    $error = implode(' ', $result['errors']);
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && user_can_manage_project($projectRow)) {
    $scan = scan_project($pdo, $projectId, true);
    $notices[] = $scan['message'];
}

$assetsStmt = $pdo->prepare('SELECT id, name FROM assets WHERE project_id = :project_id ORDER BY name');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :project_id ORDER BY name');
$typesStmt->execute(['project_id' => $projectId]);
$entityTypes = $typesStmt->fetchAll();

$entitiesStmt = $pdo->prepare('SELECT e.id, e.name, t.name AS type_name FROM entities e JOIN entity_types t ON e.type_id = t.id WHERE e.project_id = :project_id ORDER BY e.name');
$entitiesStmt->execute(['project_id' => $projectId]);
$entities = $entitiesStmt->fetchAll();

$inventoryStmt = $pdo->prepare('SELECT * FROM file_inventory WHERE project_id = :project_id AND status = "untracked" ORDER BY last_seen_at DESC LIMIT 250');
$inventoryStmt->execute(['project_id' => $projectId]);
$inventory = $inventoryStmt->fetchAll();

$inventoryThumbs = [];
foreach ($inventory as $file) {
    $thumb = thumbnail_public_if_exists($projectId, $file['file_path']);
    $absolutePath = $projectRoot . $file['file_path'];
    if (!$thumb && $projectRoot !== '' && file_exists($absolutePath)) {
        $thumb = generate_thumbnail($projectId, $file['file_path'], $absolutePath, 200);
    }
    $inventoryThumbs[(int)$file['id']] = $thumb;
}

if (empty($selectedIds) && !empty($inventory)) {
    $selectedIds = [(int)$inventory[0]['id']];
}

$selectedFile = null;
foreach ($inventory as $file) {
    if ((int)$file['id'] === (int)($selectedIds[0] ?? 0)) {
        $selectedFile = $file;
        break;
    }
}

$selectedPreview = null;
$selectedMeta = null;
if ($selectedFile) {
    $selectedPreview = $inventoryThumbs[(int)$selectedFile['id']] ?? null;
    $absolute = $projectRoot . $selectedFile['file_path'];
    if ($projectRoot !== '' && file_exists($absolute)) {
        $selectedMeta = collect_file_metadata($absolute);
    }
}

$suggestedAssetName = $selectedFile ? pathinfo($selectedFile['file_path'], PATHINFO_FILENAME) : '';

render_header('Files');
?>
<style>
.inventory-thumb {
    width: 64px;
    height: 64px;
    object-fit: cover;
}

.inventory-thumb.placeholder {
    width: 64px;
    height: 64px;
    font-size: 0.8rem;
}
</style>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Untracked Files / Review Center</h1>
        <small class="text-muted">File-first Import mit Auto-Scan, Preview und Sofort-Zuweisung.</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
            <label class="form-label mb-0" for="project_id">Projekt</label>
            <select class="form-select" name="project_id" id="project_id" onchange="this.form.submit()">
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int)$project['id'] ?>" <?= $projectId === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($project['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (user_can_manage_project($projectRow)): ?>
            <form method="post" class="d-flex align-items-center gap-2 mb-0">
                <input type="hidden" name="action" value="scan">
                <button class="btn btn-sm btn-outline-primary" type="submit">Vollständigen Scan starten</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php foreach ($notices as $notice): ?>
    <div class="alert alert-info"><?= htmlspecialchars($notice) ?></div>
<?php endforeach; ?>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <form method="post" id="bulk-form" class="card shadow-sm h-100">
            <input type="hidden" name="action" id="bulk-action" value="">
            <input type="hidden" name="selected_ids" id="bulk-selected" value="">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="select_all_files" aria-label="Alle Dateien auswählen">
                    </div>
                    <div>
                        <div class="fw-semibold">Untracked Files (<?= count($inventory) ?>)</div>
                        <small class="text-muted">Automatisch gescannt – Mehrfachauswahl möglich.</small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="submitBulk('mark_orphan')">Orphan</button>
                </div>
            </div>
            <div class="card-body p-0" style="max-height: 70vh; overflow-y: auto;">
                <?php if (empty($inventory)): ?>
                    <div class="p-3 text-muted">Keine untracked Dateien gefunden.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($inventory as $file): ?>
                            <?php
                                $checked = in_array((int)$file['id'], $selectedIds, true);
                                $thumb = $inventoryThumbs[(int)$file['id']] ?? null;
                            ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <input class="form-check-input file-checkbox" type="checkbox" value="<?= (int)$file['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                <div class="flex-shrink-0">
                                    <?php if ($thumb): ?>
                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="Preview" class="inventory-thumb rounded border">
                                    <?php else: ?>
                                        <div class="inventory-thumb placeholder rounded border d-flex align-items-center justify-content-center bg-light text-muted">n/a</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small mb-1">#<?= (int)$file['id'] ?> · <code><?= htmlspecialchars($file['file_path']) ?></code></div>
                                    <div class="small text-muted">Hash <?= htmlspecialchars(substr($file['file_hash'], 0, 12)) ?>… · <?= htmlspecialchars($file['mime_type'] ?? 'n/a') ?></div>
                                    <div class="small text-muted">Last seen: <?= htmlspecialchars($file['last_seen_at']) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <div class="mb-2">
                    <label class="form-label" for="bulk_entity">Mit Entity verknüpfen</label>
                    <select class="form-select form-select-sm" name="entity_id" id="bulk_entity">
                        <option value="">Entity wählen</option>
                        <?php foreach ($entities as $entity): ?>
                            <option value="<?= (int)$entity['id'] ?>"><?= htmlspecialchars($entity['name']) ?> (<?= htmlspecialchars($entity['type_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="bulk_entity_note">Notiz</label>
                    <textarea class="form-control form-control-sm" name="entity_note" id="bulk_entity_note" rows="2" placeholder="optional"></textarea>
                </div>
                <button class="btn btn-outline-primary w-100 mb-3" type="button" onclick="submitBulk('assign_entity')">Entity-Zuordnung speichern</button>
                <hr>
                <div class="mb-2">
                    <label class="form-label" for="bulk_asset">Mit Asset verknüpfen</label>
                    <select class="form-select form-select-sm" name="asset_id" id="bulk_asset">
                        <option value="">Asset wählen</option>
                        <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int)$asset['id'] ?>"><?= htmlspecialchars($asset['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label" for="bulk_view">View Label</label>
                        <input class="form-control form-control-sm" type="text" name="view_label" id="bulk_view" value="scan">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="bulk_ext">Dateiendung</label>
                        <input class="form-control form-control-sm" type="text" name="file_extension" id="bulk_ext" placeholder="z. B. png">
                    </div>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="apply_template" id="bulk_template" checked>
                    <label class="form-check-label" for="bulk_template">Naming-Template anwenden & verschieben</label>
                </div>
                <button class="btn btn-primary w-100" type="button" onclick="submitBulk('link_asset')">Auswahl verknüpfen</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <div class="fw-semibold">Preview &amp; Metadaten</div>
                <small class="text-muted">Detailansicht der zuerst gewählten Datei.</small>
            </div>
            <div class="card-body">
                <?php if (!$selectedFile): ?>
                    <p class="text-muted">Wählen Sie eine oder mehrere Dateien aus der Liste links.</p>
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
                        <dt class="col-sm-4">Hash</dt>
                        <dd class="col-sm-8"><span class="small text-monospace"><?= htmlspecialchars($selectedFile['file_hash']) ?></span></dd>
                        <dt class="col-sm-4">Größe</dt>
                        <dd class="col-sm-8"><?= $selectedMeta && $selectedMeta['file_size_bytes'] ? number_format((float)$selectedMeta['file_size_bytes']) . ' Bytes' : 'unbekannt' ?></dd>
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
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <div class="fw-semibold">Entity on the fly</div>
                <small class="text-muted">Direkt im Review anlegen.</small>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create_entity">
                    <input type="hidden" name="selected_ids" class="selected-target" value="">
                    <div class="mb-2">
                        <label class="form-label" for="entity_name">Name</label>
                        <input class="form-control form-control-sm" name="entity_name" id="entity_name" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="type_id">Entity-Type</label>
                        <select class="form-select form-select-sm" name="type_id" id="type_id" required>
                            <option value="">Wählen…</option>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="entity_description">Beschreibung</label>
                        <textarea class="form-control form-control-sm" name="entity_description" id="entity_description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="metadata_json">Metadata (JSON)</label>
                        <textarea class="form-control form-control-sm" name="metadata_json" id="metadata_json" rows="2" placeholder='{"scale": "real"}'></textarea>
                    </div>
                    <button class="btn btn-sm btn-primary w-100" type="submit">Entity speichern</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header">
                <div class="fw-semibold">Asset on the fly</div>
                <small class="text-muted">Asset erstellen &amp; Dateien verknüpfen.</small>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create_asset">
                    <input type="hidden" name="selected_ids" class="selected-target" value="">
                    <div class="mb-2">
                        <label class="form-label" for="asset_name">Asset-Name</label>
                        <input class="form-control form-control-sm" name="asset_name" id="asset_name" value="<?= htmlspecialchars($suggestedAssetName) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="asset_type">Asset-Type</label>
                        <input class="form-control form-control-sm" name="asset_type" id="asset_type" value="concept">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="primary_entity_id">Entity-Verknüpfung</label>
                        <select class="form-select form-select-sm" name="primary_entity_id" id="primary_entity_id">
                            <option value="">Keine</option>
                            <?php foreach ($entities as $entity): ?>
                                <option value="<?= (int)$entity['id'] ?>"><?= htmlspecialchars($entity['name']) ?> (<?= htmlspecialchars($entity['type_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="asset_description">Beschreibung</label>
                        <textarea class="form-control form-control-sm" name="asset_description" id="asset_description" rows="2"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="link_selected_files" id="link_selected_files" <?= $selectedFile ? 'checked' : '' ?>>
                        <label class="form-check-label" for="link_selected_files">Auswahl als Revision anhängen</label>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label" for="asset_view">View Label</label>
                            <input class="form-control form-control-sm" type="text" name="view_label" id="asset_view" value="main">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="asset_ext">Dateiendung</label>
                            <input class="form-control form-control-sm" type="text" name="file_extension" id="asset_ext" placeholder="png">
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="apply_template" id="asset_template" checked>
                        <label class="form-check-label" for="asset_template">Naming-Template anwenden</label>
                    </div>
                    <button class="btn btn-sm btn-success w-100" type="submit">Asset erstellen &amp; verknüpfen</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function gatherSelectedIds() {
    const ids = [];
    document.querySelectorAll('.file-checkbox:checked').forEach((checkbox) => {
        ids.push(checkbox.value);
    });
    return ids;
}

function pushSelection() {
    const ids = gatherSelectedIds();
    const csv = ids.join(',');
    document.querySelectorAll('.selected-target').forEach((input) => {
        input.value = csv;
    });
    const bulk = document.getElementById('bulk-selected');
    if (bulk) {
        bulk.value = csv;
    }
}

function submitBulk(action) {
    pushSelection();
    document.getElementById('bulk-action').value = action;
    document.getElementById('bulk-form').submit();
}

const selectAllCheckbox = document.getElementById('select_all_files');

function updateSelectAllState() {
    if (!selectAllCheckbox) return;
    const checkboxes = document.querySelectorAll('.file-checkbox');
    if (checkboxes.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.disabled = true;
    } else {
        const allChecked = Array.from(checkboxes).every(c => c.checked);
        selectAllCheckbox.checked = allChecked;
        selectAllCheckbox.disabled = false;
    }
}

document.querySelectorAll('.file-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
        updateSelectAllState();
        pushSelection();
    });
});

if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.file-checkbox').forEach((checkbox) => {
            checkbox.checked = isChecked;
        });
        pushSelection();
    });
}

updateSelectAllState();
pushSelection();
</script>
<?php render_footer(); ?>
