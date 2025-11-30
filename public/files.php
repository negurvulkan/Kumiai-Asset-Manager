<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/scanner.php';
require_login();

$message = null;
$error = null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    if (!user_can_manage_project($projectRow)) {
        $error = 'Nur Owner/Admin dürfen den Scanner starten.';
    } else {
        $result = scan_project($pdo, $projectId);
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_orphan') {
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE file_inventory SET status = "orphaned" WHERE id = :id AND project_id = :project_id');
    $stmt->execute(['id' => $inventoryId, 'project_id' => $projectId]);
    $message = 'Datei als orphaned markiert.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_asset') {
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $viewLabel = trim($_POST['view_label'] ?? 'scan');
    $manualExtension = trim($_POST['file_extension'] ?? '');
    $applyTemplate = isset($_POST['apply_template']);
    $stmt = $pdo->prepare('SELECT * FROM file_inventory WHERE id = :id AND project_id = :project_id');
    $stmt->execute(['id' => $inventoryId, 'project_id' => $projectId]);
    $file = $stmt->fetch();
    if ($file && $assetId > 0) {
        $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id = :asset_id');
        $versionStmt->execute(['asset_id' => $assetId]);
        $nextVersion = (int)$versionStmt->fetchColumn();
        $assetStmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON e.type_id = et.id WHERE a.id = :id AND a.project_id = :project_id');
        $assetStmt->execute(['id' => $assetId, 'project_id' => $projectId]);
        $asset = $assetStmt->fetch();
        $entityContext = null;
        if ($asset && $asset['primary_entity_id']) {
            $entityContext = [
                'id' => $asset['primary_entity_id'],
                'name' => $asset['primary_entity_name'],
                'slug' => $asset['primary_entity_slug'],
                'type' => $asset['primary_entity_type'],
            ];
        }

        $targetPath = sanitize_relative_path($file['file_path']);
        $meta = collect_file_metadata($projectRoot . $file['file_path']);
        if ($applyTemplate && $asset) {
            $extension = $manualExtension !== '' ? ltrim($manualExtension, '.') : extension_from_path($file['file_path']);
            $generated = generate_revision_path($projectRow, $asset, $entityContext, $nextVersion, $extension, $viewLabel);
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
                    $error = 'Datei konnte nicht in den Zielordner verschoben werden. Pfad bleibt unverändert.';
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
        $updateInventory = $pdo->prepare('UPDATE file_inventory SET status = "linked", asset_revision_id = :revision_id, file_path = :file_path, file_hash = :file_hash, mime_type = :mime_type, file_size_bytes = :file_size_bytes, last_seen_at = NOW() WHERE id = :id');
        $updateInventory->execute([
            'revision_id' => $revisionId,
            'file_path' => $targetPath,
            'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
            'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
            'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
            'id' => $inventoryId,
        ]);
        $message = $message ?: 'Datei verknüpft, einsortiert und Metadaten aktualisiert.';
    }
}

$assetsStmt = $pdo->prepare('SELECT id, name FROM assets WHERE project_id = :project_id ORDER BY name');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$inventoryStmt = $pdo->prepare('SELECT * FROM file_inventory WHERE project_id = :project_id ORDER BY last_seen_at DESC LIMIT 100');
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

render_header('Files');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Dateien</h1>
        <small class="text-muted">Inventarstatus und Review-Workflow.</small>
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
                <button class="btn btn-sm btn-outline-primary" type="submit">Scanner starten</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6">File Inventory</h2>
        <?php if (empty($inventory)): ?>
            <p class="text-muted">Keine Dateien erfasst. Führen Sie den Scanner aus.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 120px">Vorschau</th>
                            <th>Pfad</th>
                            <th>Status</th>
                            <th>Hash</th>
                            <th>Last Seen</th>
                            <th class="text-end">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $file): ?>
                            <tr>
                                <?php $thumb = $inventoryThumbs[(int)$file['id']] ?? null; ?>
                                <td class="align-middle">
                                    <?php if ($thumb): ?>
                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="Preview" class="img-thumbnail" style="max-width: 96px; max-height: 96px;">
                                    <?php else: ?>
                                        <div class="text-muted small">Keine Vorschau</div>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($file['file_path']) ?></code></td>
                                <td><?= htmlspecialchars($file['status']) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($file['file_hash']) ?></td>
                                <td><?= htmlspecialchars($file['last_seen_at']) ?></td>
                                <td class="text-end">
                                    <?php if ($file['status'] === 'untracked'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="mark_orphan">
                                            <input type="hidden" name="inventory_id" value="<?= (int)$file['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Orphan</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="link_asset">
                                            <input type="hidden" name="inventory_id" value="<?= (int)$file['id'] ?>">
                                            <input type="hidden" name="view_label" value="scan">
                                            <input type="hidden" name="file_extension" value="<?= htmlspecialchars(extension_from_path($file['file_path'])) ?>">
                                            <input type="hidden" name="apply_template" value="1">
                                            <select name="asset_id" class="form-select form-select-sm d-inline-block w-auto">
                                                <option value="">Asset wählen</option>
                                                <?php foreach ($assets as $asset): ?>
                                                    <option value="<?= (int)$asset['id'] ?>"><?= htmlspecialchars($asset['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-primary" type="submit">Link + Revision</button>
                                            <div class="small text-muted">Auto-Pfad gem. Template und Dateiendung.</div>
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
<?php render_footer(); ?>
