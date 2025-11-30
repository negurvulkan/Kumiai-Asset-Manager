<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $rootPath = trim($_POST['root_path'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '' || $slug === '' || $rootPath === '') {
        $error = 'Name, Slug und Root-Pfad sind Pflichtfelder.';
    } else {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO projects (name, slug, description, root_path, created_at) VALUES (:name, :slug, :description, :root_path, NOW())');
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'root_path' => $rootPath,
        ]);
        $projectId = (int)$pdo->lastInsertId();

        $stmtRole = $pdo->prepare('INSERT INTO project_roles (project_id, user_id, role) VALUES (:project_id, :user_id, :role)');
        $stmtRole->execute([
            'project_id' => $projectId,
            'user_id' => current_user()['id'],
            'role' => 'owner',
        ]);
        $pdo->commit();
        $message = 'Projekt wurde angelegt und Sie sind nun Owner.';
    }
}

$projects = user_projects($pdo);

$projectDetails = null;
if (isset($_GET['project_id'])) {
    $stmt = $pdo->prepare('SELECT p.*, pr.role FROM projects p LEFT JOIN project_roles pr ON pr.project_id = p.id AND pr.user_id = :uid WHERE p.id = :pid');
    $stmt->execute(['uid' => current_user()['id'], 'pid' => (int)$_GET['project_id']]);
    $projectDetails = $stmt->fetch();
    if ($projectDetails) {
        $typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :pid ORDER BY name');
        $typesStmt->execute(['pid' => $projectDetails['id']]);
        $projectDetails['entity_types'] = $typesStmt->fetchAll();
    }
}

render_header('Projects');
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h1 class="h4 mb-0">Meine Projekte</h1>
                    <span class="badge bg-secondary"><?= count($projects) ?></span>
                </div>
                <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if (empty($projects)): ?>
                    <p class="text-muted">Noch keine Projekte vorhanden.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($projects as $project): ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="?project_id=<?= (int)$project['id'] ?>">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($project['name']) ?></div>
                                    <small class="text-muted">Rolle: <?= htmlspecialchars($project['role']) ?></small>
                                </div>
                                <span class="text-muted">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Neues Projekt anlegen</h2>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="slug">Slug</label>
                        <input class="form-control" id="slug" name="slug" required>
                        <div class="form-text">Kürzerer, URL-tauglicher Name.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="root_path">Root-Pfad</label>
                        <input class="form-control" id="root_path" name="root_path" required placeholder="/srv/projects/mangaA">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Beschreibung</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>
        <?php if ($projectDetails): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Projektdetails</h2>
                <dl class="row mb-3">
                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['name']) ?></dd>
                    <dt class="col-sm-4">Slug</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['slug']) ?></dd>
                    <dt class="col-sm-4">Root</dt><dd class="col-sm-8"><code><?= htmlspecialchars($projectDetails['root_path']) ?></code></dd>
                    <dt class="col-sm-4">Rolle</dt><dd class="col-sm-8"><?= htmlspecialchars($projectDetails['role'] ?? 'n/a') ?></dd>
                </dl>
                <h3 class="h6">Entity-Typen</h3>
                <?php if (empty($projectDetails['entity_types'])): ?>
                    <p class="text-muted">Noch keine Entity-Typen angelegt.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($projectDetails['entity_types'] as $type): ?>
                            <li><span class="badge bg-light text-dark border"><?= htmlspecialchars($type['name']) ?></span> <small class="text-muted"><?= htmlspecialchars($type['description']) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>
