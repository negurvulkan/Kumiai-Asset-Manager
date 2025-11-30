<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_login();

$message = null;
$error = null;

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
        $message = 'Entity-Typ gespeichert.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_entity') {
    $name = trim($_POST['entity_name'] ?? '');
    $slug = trim($_POST['entity_slug'] ?? '');
    $description = trim($_POST['entity_description'] ?? '');
    $typeId = (int)($_POST['type_id'] ?? 0);
    $metadataInput = trim($_POST['metadata_json'] ?? '');
    $metadataJson = '{}';
    if ($metadataInput !== '') {
        $decoded = json_decode($metadataInput, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $error = 'Metadata-JSON ist ungültig.';
        } else {
            $metadataJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    if (!$error && $name !== '' && $typeId > 0) {
        $stmt = $pdo->prepare('INSERT INTO entities (project_id, type_id, name, slug, description, metadata_json, created_at) VALUES (:project_id, :type_id, :name, :slug, :description, :metadata_json, NOW())');
        $stmt->execute([
            'project_id' => $projectId,
            'type_id' => $typeId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'metadata_json' => $metadataJson,
        ]);
        $message = 'Entity angelegt und Metadaten gespeichert.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_entity') {
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $name = trim($_POST['entity_name'] ?? '');
    $slug = trim($_POST['entity_slug'] ?? '');
    $description = trim($_POST['entity_description'] ?? '');
    $typeId = (int)($_POST['type_id'] ?? 0);
    $metadataInput = trim($_POST['metadata_json'] ?? '');
    $metadataJson = '{}';

    if ($metadataInput !== '') {
        $decoded = json_decode($metadataInput, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $error = 'Metadata-JSON ist ungültig.';
        } else {
            $metadataJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    if (!$error && $entityId > 0 && $name !== '' && $typeId > 0) {
        $stmt = $pdo->prepare('UPDATE entities SET name = :name, slug = :slug, type_id = :type_id, description = :description, metadata_json = :metadata WHERE id = :id AND project_id = :project_id');
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'type_id' => $typeId,
            'description' => $description,
            'metadata' => $metadataJson,
            'id' => $entityId,
            'project_id' => $projectId,
        ]);
        if ($stmt->rowCount() > 0) {
            $message = 'Entity aktualisiert.';
        } else {
            $error = 'Entity nicht gefunden.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_axis') {
    $axisKey = kumiai_slug(trim($_POST['axis_key'] ?? ''));
    $label = trim($_POST['axis_label'] ?? '');
    $appliesTo = trim($_POST['applies_to'] ?? 'character');
    $hasValues = isset($_POST['has_predefined_values']) ? 1 : 0;
    if ($axisKey !== '' && $label !== '') {
        $stmt = $pdo->prepare('INSERT INTO classification_axes (axis_key, label, applies_to, has_predefined_values, created_at) VALUES (:axis_key, :label, :applies_to, :has_predefined_values, NOW())');
        $stmt->execute([
            'axis_key' => $axisKey,
            'label' => $label,
            'applies_to' => $appliesTo,
            'has_predefined_values' => $hasValues,
        ]);
        $message = 'Classification Axis gespeichert.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_axis_value') {
    $axisId = (int)($_POST['axis_id'] ?? 0);
    $valueKey = kumiai_slug(trim($_POST['value_key'] ?? ''));
    $label = trim($_POST['value_label'] ?? '');
    if ($axisId > 0 && $valueKey !== '' && $label !== '') {
        $stmt = $pdo->prepare('INSERT INTO classification_axis_values (axis_id, value_key, label, created_at) VALUES (:axis_id, :value_key, :label, NOW())');
        $stmt->execute([
            'axis_id' => $axisId,
            'value_key' => $valueKey,
            'label' => $label,
        ]);
        $message = 'Axis Value gespeichert.';
    }
}

$typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :project_id ORDER BY name');
$typesStmt->execute(['project_id' => $projectId]);
$entityTypes = $typesStmt->fetchAll();

$entitiesStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM entities e JOIN entity_types t ON e.type_id = t.id WHERE e.project_id = :project_id ORDER BY e.created_at DESC LIMIT 50');
$entitiesStmt->execute(['project_id' => $projectId]);
$entities = $entitiesStmt->fetchAll();

$axesStmt = $pdo->query('SELECT * FROM classification_axes ORDER BY applies_to, label');
$axes = $axesStmt->fetchAll();

$axisValuesStmt = $pdo->query('SELECT * FROM classification_axis_values ORDER BY axis_id, label');
$axisValues = $axisValuesStmt->fetchAll();
$axisValueMap = [];
foreach ($axisValues as $value) {
    $axisValueMap[(int)$value['axis_id']][] = $value;
}

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
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
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
        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <h3 class="h6">Classification Axes</h3>
                <?php if (empty($axes)): ?>
                    <p class="text-muted">Noch keine Achsen definiert.</p>
                <?php else: ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Label</th>
                                    <th>Typ</th>
                                    <th>Values</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($axes as $axis): ?>
                                    <?php $values = $axisValueMap[(int)$axis['id']] ?? []; ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($axis['axis_key']) ?></code></td>
                                        <td><?= htmlspecialchars($axis['label']) ?></td>
                                        <td><?= htmlspecialchars($axis['applies_to']) ?></td>
                                        <td>
                                            <?php if (empty($values)): ?>
                                                <span class="text-muted">–</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border"><?= count($values) ?> Werte</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <h4 class="h6">Achse hinzufügen</h4>
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="add_axis">
                    <div class="mb-2">
                        <label class="form-label" for="axis_key">Key</label>
                        <input class="form-control" name="axis_key" id="axis_key" placeholder="outfit" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="axis_label">Label</label>
                        <input class="form-control" name="axis_label" id="axis_label" placeholder="Outfit" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="applies_to">Entity-Typ</label>
                        <select class="form-select" name="applies_to" id="applies_to">
                            <option value="character">character</option>
                            <option value="location">location</option>
                            <option value="scene">scene</option>
                            <option value="chapter">chapter</option>
                            <option value="prop">prop</option>
                            <option value="background">background</option>
                            <option value="item">item</option>
                            <option value="creature">creature</option>
                            <option value="project_custom">project_custom</option>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="has_predefined_values" id="has_predefined_values">
                        <label class="form-check-label" for="has_predefined_values">Vordefinierte Werte erwartet</label>
                    </div>
                    <button class="btn btn-primary" type="submit">Achse speichern</button>
                </form>
                <h4 class="h6">Werte pflegen</h4>
                <form method="post">
                    <input type="hidden" name="action" value="add_axis_value">
                    <div class="mb-2">
                        <label class="form-label" for="axis_id">Achse</label>
                        <select class="form-select" name="axis_id" id="axis_id" required>
                            <option value="">Bitte wählen</option>
                            <?php foreach ($axes as $axis): ?>
                                <option value="<?= (int)$axis['id'] ?>"><?= htmlspecialchars($axis['label']) ?> (<?= htmlspecialchars($axis['applies_to']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="value_key">Key</label>
                        <input class="form-control" name="value_key" id="value_key" placeholder="front" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="value_label">Label</label>
                        <input class="form-control" name="value_label" id="value_label" placeholder="Frontansicht" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Wert speichern</button>
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
                                    <th>Metadata</th>
                                    <th>Erstellt</th>
                                    <th>Unklass. Dateien</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entities as $entity): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($entity['name']) ?></td>
                                        <td><code><?= htmlspecialchars($entity['slug']) ?></code></td>
                                        <td><?= htmlspecialchars($entity['type_name']) ?></td>
                                        <td class="small"><pre class="mb-0 bg-light p-2 border rounded" style="max-width: 320px; white-space: pre-wrap; word-break: break-word;"><?= htmlspecialchars($entity['metadata_json'] ?: '{}') ?></pre></td>
                                        <td><?= htmlspecialchars($entity['created_at']) ?></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="/entity_files.php?entity_id=<?= (int)$entity['id'] ?>">Klassifizieren</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($entities)): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6">Entity bearbeiten</h3>
                <form method="post" id="edit_entity_form">
                    <input type="hidden" name="action" value="update_entity">
                    <div class="mb-3">
                        <label class="form-label" for="edit_entity_id">Entity wählen</label>
                        <select class="form-select" name="entity_id" id="edit_entity_id" required>
                            <?php foreach ($entities as $entity): ?>
                                <option value="<?= (int)$entity['id'] ?>"><?= htmlspecialchars($entity['name']) ?> (<?= htmlspecialchars($entity['slug']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_entity_name">Name</label>
                        <input class="form-control" name="entity_name" id="edit_entity_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_entity_slug">Slug</label>
                        <input class="form-control" name="entity_slug" id="edit_entity_slug" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_type_id">Typ</label>
                        <select class="form-select" name="type_id" id="edit_type_id" required>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_entity_description">Beschreibung</label>
                        <textarea class="form-control" name="entity_description" id="edit_entity_description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_metadata_json">Metadata (JSON)</label>
                        <textarea class="form-control" name="metadata_json" id="edit_metadata_json" rows="3"></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Änderungen speichern</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
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
                    <div class="mb-3">
                        <label class="form-label" for="metadata_json">Metadata (JSON)</label>
                        <textarea class="form-control" name="metadata_json" id="metadata_json" rows="3" placeholder='{"mood":"calm","age":18}'></textarea>
                        <div class="form-text">Beliebige strukturierte Zusatzinfos als JSON-Objekt.</div>
                    </div>
                    <button class="btn btn-primary" type="submit">Anlegen</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const entityForm = document.getElementById('edit_entity_form');
    if (!entityForm) return;

    const entityMap = <?= json_encode(array_reduce($entities, function ($carry, $entity) {
        $carry[(int)$entity['id']] = [
            'name' => $entity['name'],
            'slug' => $entity['slug'],
            'type_id' => (int)$entity['type_id'],
            'description' => $entity['description'] ?? '',
            'metadata_json' => is_string($entity['metadata_json']) ? $entity['metadata_json'] : json_encode($entity['metadata_json'] ?? '{}'),
        ];
        return $carry;
    }, [])) ?>;

    const select = document.getElementById('edit_entity_id');
    const nameInput = document.getElementById('edit_entity_name');
    const slugInput = document.getElementById('edit_entity_slug');
    const typeSelect = document.getElementById('edit_type_id');
    const descInput = document.getElementById('edit_entity_description');
    const metadataInput = document.getElementById('edit_metadata_json');

    const fillForm = () => {
        const entityId = select.value;
        const data = entityMap[entityId];
        if (!data) return;
        nameInput.value = data.name || '';
        slugInput.value = data.slug || '';
        typeSelect.value = data.type_id || '';
        descInput.value = data.description || '';
        metadataInput.value = data.metadata_json || '';
    };

    select.addEventListener('change', fillForm);
    fillForm();
})();
</script>
<?php render_footer(); ?>
