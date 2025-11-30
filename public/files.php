<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Files');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (int)$projects[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_orphan') {
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE file_inventory SET status = "orphaned" WHERE id = :id AND project_id = :project_id');
    $stmt->execute(['id' => $inventoryId, 'project_id' => $projectId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_asset') {
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM file_inventory WHERE id = :id AND project_id = :project_id');
    $stmt->execute(['id' => $inventoryId, 'project_id' => $projectId]);
    $file = $stmt->fetch();
    if ($file && $assetId > 0) {
        $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id = :asset_id');
        $versionStmt->execute(['asset_id' => $assetId]);
        $nextVersion = (int)$versionStmt->fetchColumn();
        $revStmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :created_by, NOW(), "pending")');
        $revStmt->execute([
            'asset_id' => $assetId,
            'version' => $nextVersion,
            'file_path' => $file['file_path'],
            'file_hash' => $file['file_hash'],
            'mime_type' => $file['mime_type'] ?? 'application/octet-stream',
            'file_size_bytes' => $file['file_size_bytes'] ?? 0,
            'created_by' => current_user()['id'],
        ]);
        $revisionId = (int)$pdo->lastInsertId();
        $updateInventory = $pdo->prepare('UPDATE file_inventory SET status = "linked", asset_revision_id = :revision_id, last_seen_at = NOW() WHERE id = :id');
        $updateInventory->execute(['revision_id' => $revisionId, 'id' => $inventoryId]);
    }
}

$assetsStmt = $pdo->prepare('SELECT id, name FROM assets WHERE project_id = :project_id ORDER BY name');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$inventoryStmt = $pdo->prepare('SELECT * FROM file_inventory WHERE project_id = :project_id ORDER BY last_seen_at DESC LIMIT 100');
$inventoryStmt->execute(['project_id' => $projectId]);
$inventory = $inventoryStmt->fetchAll();

render_header('Files');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Dateien</h1>
        <small class="text-muted">Inventarstatus und Review-Workflow.</small>
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
                                            <select name="asset_id" class="form-select form-select-sm d-inline-block w-auto">
                                                <option value="">Asset wählen</option>
                                                <?php foreach ($assets as $asset): ?>
                                                    <option value="<?= (int)$asset['id'] ?>"><?= htmlspecialchars($asset['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-primary" type="submit">Link + Revision</button>
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
