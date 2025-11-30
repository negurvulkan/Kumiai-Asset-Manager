<?php
require_once __DIR__ . '/../includes/layout.php';
require_login();

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Entities');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (int)$projects[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_type') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name !== '') {
        $stmt = $pdo->prepare('INSERT INTO entity_types (project_id, name, description, created_at) VALUES (:project_id, :name, :description, NOW())');
        $stmt->execute(['project_id' => $projectId, 'name' => $name, 'description' => $description]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_entity') {
    $name = trim($_POST['entity_name'] ?? '');
    $slug = trim($_POST['entity_slug'] ?? '');
    $description = trim($_POST['entity_description'] ?? '');
    $typeId = (int)($_POST['type_id'] ?? 0);
    if ($name !== '' && $typeId > 0) {
        $stmt = $pdo->prepare('INSERT INTO entities (project_id, type_id, name, slug, description, metadata_json, created_at) VALUES (:project_id, :type_id, :name, :slug, :description, :metadata_json, NOW())');
        $stmt->execute([
            'project_id' => $projectId,
            'type_id' => $typeId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'metadata_json' => '{}',
        ]);
    }
}

$typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :project_id ORDER BY name');
$typesStmt->execute(['project_id' => $projectId]);
$entityTypes = $typesStmt->fetchAll();

$entitiesStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM entities e JOIN entity_types t ON e.type_id = t.id WHERE e.project_id = :project_id ORDER BY e.created_at DESC LIMIT 50');
$entitiesStmt->execute(['project_id' => $projectId]);
$entities = $entitiesStmt->fetchAll();

render_header('Entities');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Entities</h1>
        <small class="text-muted">Generisches Modell für Charaktere, Locations, Szenen u. a.</small>
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
    <div class="col-md-6">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Entity-Typen</h2>
                <?php if (empty($entityTypes)): ?>
                    <p class="text-muted">Noch keine Typen definiert.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($entityTypes as $type): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($type['name']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($type['description']) ?></div>
                                </div>
                                <span class="badge bg-light text-dark border">#<?= (int)$type['id'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="h6">Neuen Entity-Typ anlegen</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_type">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" name="name" id="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Beschreibung</label>
                        <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Entities</h2>
                <?php if (empty($entities)): ?>
                    <p class="text-muted">Noch keine Entities vorhanden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Typ</th>
                                    <th>Erstellt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entities as $entity): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($entity['name']) ?></td>
                                        <td><code><?= htmlspecialchars($entity['slug']) ?></code></td>
                                        <td><?= htmlspecialchars($entity['type_name']) ?></td>
                                        <td><?= htmlspecialchars($entity['created_at']) ?></td>
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
                <h3 class="h6">Neue Entity</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_entity">
                    <div class="mb-3">
                        <label class="form-label" for="entity_name">Name</label>
                        <input class="form-control" name="entity_name" id="entity_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="entity_slug">Slug</label>
                        <input class="form-control" name="entity_slug" id="entity_slug" placeholder="mika" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="type_id">Typ</label>
                        <select class="form-select" name="type_id" id="type_id" required>
                            <option value="">Bitte wählen</option>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="entity_description">Beschreibung</label>
                        <textarea class="form-control" name="entity_description" id="entity_description" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Anlegen</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
