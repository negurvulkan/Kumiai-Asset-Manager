<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_login();

$entityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;

$entityStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM entities e JOIN entity_types t ON t.id = e.type_id WHERE e.id = :id');
$entityStmt->execute(['id' => $entityId]);
$entity = $entityStmt->fetch();

if (!$entity) {
    render_header('Entity Details');
    echo '<div class="alert alert-danger">Entity nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectId = (int)$entity['project_id'];
$projects = user_projects($pdo);
$projectAccess = false;
foreach ($projects as $p) {
    if ((int)$p['id'] === $projectId) {
        $projectAccess = true;
        break;
    }
}

if (!$projectAccess) {
    render_header('Entity Details');
    echo '<div class="alert alert-danger">Keine Berechtigung für dieses Projekt.</div>';
    render_footer();
    exit;
}

$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectFull = $projectStmt->fetch();
$projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

// Assets laden
$assetsStmt = $pdo->prepare('SELECT * FROM assets WHERE primary_entity_id = :entity_id AND project_id = :project_id ORDER BY created_at DESC');
$assetsStmt->execute(['entity_id' => $entityId, 'project_id' => $projectId]);
$assets = $assetsStmt->fetchAll();

// Thumbnails für die neueste Revision laden
$assetThumbs = [];
$assetVersions = [];

foreach ($assets as $asset) {
    $revStmt = $pdo->prepare('SELECT * FROM asset_revisions WHERE asset_id = :asset_id ORDER BY version DESC LIMIT 1');
    $revStmt->execute(['asset_id' => $asset['id']]);
    $latestRev = $revStmt->fetch();

    if ($latestRev) {
        $assetVersions[$asset['id']] = $latestRev;
        $thumb = thumbnail_public_if_exists($projectId, $latestRev['file_path']);
        $absolutePath = $projectRoot . $latestRev['file_path'];
        if (!$thumb && $projectRoot !== '' && file_exists($absolutePath)) {
            $thumb = generate_thumbnail($projectId, $latestRev['file_path'], $absolutePath, 300);
        }
        $assetThumbs[$asset['id']] = $thumb;
    }
}

render_header('Entity: ' . htmlspecialchars($entity['name']));
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0"><?= htmlspecialchars($entity['name']) ?> <small class="text-muted">(<?= htmlspecialchars($entity['type_name']) ?>)</small></h1>
        <div class="text-muted small">Slug: <code><?= htmlspecialchars($entity['slug']) ?></code></div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="/entity_files.php?entity_id=<?= (int)$entity['id'] ?>">Unklassifizierte Dateien</a>
        <a class="btn btn-sm btn-outline-secondary" href="/entities.php?project_id=<?= (int)$projectId ?>">Zurück zur Übersicht</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h5 class="card-title">Beschreibung</h5>
                <p class="card-text"><?= nl2br(htmlspecialchars($entity['description'] ?? '')) ?></p>

                <?php if (!empty($entity['metadata_json'])): ?>
                    <h6 class="mt-3">Metadaten</h6>
                    <pre class="bg-light p-2 border rounded small" style="white-space: pre-wrap;"><?= htmlspecialchars($entity['metadata_json']) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <h2 class="h5 mb-3">Assets (<?= count($assets) ?>)</h2>
        <?php if (empty($assets)): ?>
            <div class="alert alert-light border">Keine Assets für diese Entity gefunden.</div>
        <?php else: ?>
            <div class="row row-cols-2 row-cols-lg-3 g-3">
                <?php foreach ($assets as $asset): ?>
                    <div class="col">
                        <div class="card h-100 shadow-sm">
                            <?php
                                $thumb = $assetThumbs[$asset['id']] ?? null;
                            ?>
                            <div class="ratio ratio-1x1 bg-light border-bottom d-flex align-items-center justify-content-center overflow-hidden">
                                <?php if ($thumb): ?>
                                    <img src="<?= htmlspecialchars($thumb) ?>" class="w-100 h-100" style="object-fit: contain;" alt="<?= htmlspecialchars($asset['display_name'] ?? $asset['asset_key']) ?>">
                                <?php else: ?>
                                    <div class="text-muted small">Keine Vorschau</div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-2">
                                <h6 class="card-title text-truncate mb-1" title="<?= htmlspecialchars($asset['display_name'] ?? '') ?>">
                                    <?= htmlspecialchars($asset['display_name'] ?? $asset['asset_key']) ?>
                                </h6>
                                <div class="small text-muted text-truncate mb-1">
                                    <code><?= htmlspecialchars($asset['asset_key']) ?></code>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($asset['asset_type']) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($asset['status']) ?></small>
                                </div>
                            </div>
                            <div class="card-footer bg-white p-2 text-end">
                                <a href="/assets.php?project_id=<?= $projectId ?>#asset-<?= $asset['id'] ?>" class="btn btn-sm btn-outline-primary stretched-link">Details</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
