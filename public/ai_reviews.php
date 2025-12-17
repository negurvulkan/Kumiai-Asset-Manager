<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classification.php';
require_login();

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('AI Review Queue');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}

$projectId = (int)($_GET['project_id'] ?? ($_POST['project_id'] ?? $projects[0]['id']));
$projectRoles = [];
foreach ($projects as $p) {
    $projectRoles[(int)$p['id']] = $p['role'] ?? '';
}

if (!isset($projectRoles[$projectId])) {
    render_header('AI Review Queue');
    echo '<div class="alert alert-danger">Kein Zugriff auf dieses Projekt.</div>';
    render_footer();
    exit;
}

$canModerate = in_array($projectRoles[$projectId], ['owner', 'admin', 'editor'], true);
$statusFilter = $_GET['status'] ?? 'open';

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'review_decision') {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $note = trim($_POST['note'] ?? '');
        if (!$canModerate) {
            $error = 'Keine Berechtigung zum Freigeben/Ablehnen.';
        } elseif ($queueId <= 0) {
            $error = 'Ungültiger Queue-Eintrag.';
        } else {
            $entryStmt = $pdo->prepare('SELECT * FROM ai_review_queue WHERE id = :id AND project_id = :project_id');
            $entryStmt->execute(['id' => $queueId, 'project_id' => $projectId]);
            $entry = $entryStmt->fetch();
            if (!$entry) {
                $error = 'Eintrag nicht gefunden oder nicht im aktuellen Projekt.';
            } else {
                $newStatus = $entry['status'];
                $reason = $note;
                if ($decision === 'approve') {
                    $newStatus = 'auto_assigned';
                    $reason = $note !== '' ? $note : 'Manuell freigegeben.';
                } elseif ($decision === 'reject') {
                    $newStatus = 'needs_review';
                    $reason = $note !== '' ? 'Abgelehnt: ' . $note : 'Abgelehnt durch Review.';
                }
                $update = $pdo->prepare('UPDATE ai_review_queue SET status = :status, reason = :reason WHERE id = :id');
                $update->execute([
                    'status' => $newStatus,
                    'reason' => $reason,
                    'id' => $queueId,
                ]);
                $message = 'Review-Aktion gespeichert.';
            }
        }
    }
}

$clauses = ['rq.project_id = :project_id'];
$params = ['project_id' => $projectId];
if ($statusFilter === 'open') {
    $clauses[] = 'rq.status IN ("pending","needs_review")';
} elseif (in_array($statusFilter, ['pending', 'needs_review', 'auto_assigned'], true)) {
    $clauses[] = 'rq.status = :status';
    $params['status'] = $statusFilter;
}

$sql = 'SELECT rq.*, p.name AS project_name, fi.file_path, fi.status AS inventory_status, fi.classification_state, fi.asset_revision_id, ar.version AS revision_version, ar.review_status AS revision_review_status, a.asset_key, a.display_name, a.id AS asset_id
        FROM ai_review_queue rq
        JOIN projects p ON p.id = rq.project_id
        JOIN file_inventory fi ON fi.id = rq.file_inventory_id
        LEFT JOIN asset_revisions ar ON ar.id = fi.asset_revision_id
        LEFT JOIN assets a ON a.id = ar.asset_id
        WHERE ' . implode(' AND ', $clauses) . '
        ORDER BY rq.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$inventoryIds = array_map(fn($row) => (int)$row['file_inventory_id'], $entries);
$inventoryClassifications = fetch_inventory_classifications($pdo, $inventoryIds);

$latestAuditByInventory = [];
if (!empty($inventoryIds)) {
    $placeholders = implode(',', array_fill(0, count($inventoryIds), '?'));
    $auditStmt = $pdo->prepare("SELECT * FROM ai_audit_logs WHERE file_inventory_id IN ($placeholders) ORDER BY created_at DESC");
    $auditStmt->execute($inventoryIds);
    foreach ($auditStmt->fetchAll() as $auditRow) {
        $invId = (int)$auditRow['file_inventory_id'];
        if (!isset($latestAuditByInventory[$invId])) {
            $latestAuditByInventory[$invId] = $auditRow;
        }
    }
}

render_header('AI Review Queue');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">AI Review Queue</h1>
        <div class="text-muted small">Filter, Genehmigen/Ablehnen und Details zu KI-Vorschlägen.</div>
    </div>
    <div>
        <a href="assets.php?project_id=<?= $projectId ?>" class="btn btn-sm btn-outline-secondary">Zurück zu Assets</a>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label small text-muted">Projekt</label>
        <select name="project_id" class="form-select form-select-sm">
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projectId ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select form-select-sm">
            <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>offen (pending + needs_review)</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>pending</option>
            <option value="needs_review" <?= $statusFilter === 'needs_review' ? 'selected' : '' ?>>needs_review</option>
            <option value="auto_assigned" <?= $statusFilter === 'auto_assigned' ? 'selected' : '' ?>>auto_assigned</option>
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>alle</option>
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-sm btn-primary mt-4" type="submit">Filtern</button>
    </div>
</form>

<?php if (empty($entries)): ?>
    <div class="alert alert-light border">Keine Einträge für die gewählten Filter.</div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($entries as $entry): ?>
            <?php
                $invId = (int)$entry['file_inventory_id'];
                $audit = $latestAuditByInventory[$invId] ?? null;
                $auditOutput = $audit ? json_decode($audit['output_payload'] ?? '', true) : null;
                $decision = $auditOutput['decision'] ?? [];
                $candidates = $auditOutput['candidates'] ?? [];
                if (empty($candidates) && !empty($entry['suggested_assignment'])) {
                    $assignment = json_decode($entry['suggested_assignment'], true);
                    if ($assignment) {
                        $candidates[] = $assignment;
                    }
                }
                $classValues = $inventoryClassifications[$invId] ?? [];
                $aiStatus = $decision['status'] ?? ($entry['status'] ?? 'pending');
                $overallConfidence = $decision['overall_confidence'] ?? ($entry['confidence'] ?? null);
            ?>
            <div class="list-group-item p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold">#<?= $invId ?> · <code><?= htmlspecialchars($entry['file_path']) ?></code></div>
                        <div class="small text-muted">
                            Status: <span class="badge bg-light text-dark border"><?= htmlspecialchars($entry['status']) ?></span>
                            <?php if ($entry['asset_key'] && $entry['asset_id']): ?>
                                · Asset: <a href="asset_details.php?id=<?= (int)$entry['asset_id'] ?>"><?= htmlspecialchars($entry['asset_key']) ?></a>
                                <?php if ($entry['revision_version']): ?>
                                    <span class="badge bg-light text-dark border ms-1">v<?= (int)$entry['revision_version'] ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($classValues)): ?>
                            <div class="small text-muted mt-1">
                                Klassifizierte Details:
                                <?php foreach ($classValues as $key => $value): ?>
                                    <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($key) ?>: <?= htmlspecialchars($value) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($entry['reason']): ?>
                            <div class="text-muted small mt-1">Grund: <?= htmlspecialchars($entry['reason']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div>
                            <span class="badge bg-<?= $aiStatus === 'auto_assigned' ? 'success' : 'warning text-dark' ?>">
                                <?= htmlspecialchars($aiStatus) ?>
                            </span>
                        </div>
                        <?php if ($overallConfidence !== null): ?>
                            <div class="small text-muted mt-1">Confidence: <?= number_format((float)$overallConfidence, 3) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-8">
                        <div class="small text-muted mb-1">Vorschläge mit Score/Margin:</div>
                        <?php if (empty($candidates)): ?>
                            <div class="alert alert-light border p-2 small mb-0">Keine Kandidaten im Audit gefunden.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush small">
                                <?php foreach ($candidates as $candidate): ?>
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
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body p-2">
                                <div class="small text-muted">Auto-Assign / Margin</div>
                                <div class="fw-semibold"><?= htmlspecialchars($decision['status'] ?? 'needs_review') ?></div>
                                <div class="small">
                                    Margin: <?= number_format((float)($decision['score_margin'] ?? 0.0), 3) ?><br>
                                    Threshold: <?= number_format((float)($decision['score_threshold'] ?? 0.0), 3) ?><br>
                                    Runner-Up: <?= number_format((float)($decision['runner_up_score'] ?? 0.0), 3) ?>
                                </div>
                                <?php if ($audit && !empty($audit['created_at'])): ?>
                                    <div class="small text-muted mt-1">Audit: <?= htmlspecialchars($audit['created_at']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($canModerate): ?>
                    <form method="post" class="mt-3 d-flex gap-2 align-items-center">
                        <input type="hidden" name="action" value="review_decision">
                        <input type="hidden" name="queue_id" value="<?= (int)$entry['id'] ?>">
                        <input type="hidden" name="project_id" value="<?= $projectId ?>">
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Notiz (optional)">
                        <button class="btn btn-sm btn-outline-success" type="submit" name="decision" value="approve">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Reject</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
