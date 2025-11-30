<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Assets');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (int)$projects[0]['id'];

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
    if ($assetId > 0 && $filePath !== '') {
        $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM asset_revisions WHERE asset_id = :asset_id');
        $versionStmt->execute(['asset_id' => $assetId]);
        $nextVersion = (int)$versionStmt->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :created_by, NOW(), :review_status)');
        $stmt->execute([
            'asset_id' => $assetId,
            'version' => $nextVersion,
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'mime_type' => $mime,
            'file_size_bytes' => (int)($_POST['file_size_bytes'] ?? 0),
            'created_by' => current_user()['id'],
            'review_status' => $status,
        ]);
        $inventoryStmt = $pdo->prepare('INSERT INTO file_inventory (project_id, file_path, file_hash, status, asset_revision_id, last_seen_at) VALUES (:project_id, :file_path, :file_hash, :status, :asset_revision_id, NOW()) ON DUPLICATE KEY UPDATE file_hash = VALUES(file_hash), status = VALUES(status), asset_revision_id = VALUES(asset_revision_id), last_seen_at = NOW()');
        $inventoryStmt->execute([
            'project_id' => $projectId,
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'status' => 'linked',
            'asset_revision_id' => (int)$pdo->lastInsertId(),
        ]);
    }
}

$entitiesStmt = $pdo->prepare('SELECT id, name FROM entities WHERE project_id = :project_id ORDER BY name');
$entitiesStmt->execute(['project_id' => $projectId]);
$entities = $entitiesStmt->fetchAll();

$assetsStmt = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id WHERE a.project_id = :project_id ORDER BY a.created_at DESC LIMIT 50');
$assetsStmt->execute(['project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

$revisionsStmt = $pdo->prepare('SELECT r.*, a.name AS asset_name FROM asset_revisions r JOIN assets a ON a.id = r.asset_id WHERE a.project_id = :project_id ORDER BY r.created_at DESC, r.version DESC LIMIT 50');
$revisionsStmt->execute(['project_id' => $projectId]);
$revisions = $revisionsStmt->fetchAll();

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
                                    <th>Datei</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revisions as $revision): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($revision['asset_name']) ?></td>
                                        <td>v<?= (int)$revision['version'] ?></td>
                                        <td><code><?= htmlspecialchars($revision['file_path']) ?></code></td>
                                        <td><?= htmlspecialchars($revision['review_status']) ?></td>
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
                <form method="post">
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
                        <input class="form-control" name="file_path" id="file_path" placeholder="/01_CHARACTER/kei/ref_v1.png" required>
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
<?php render_footer(); ?>
