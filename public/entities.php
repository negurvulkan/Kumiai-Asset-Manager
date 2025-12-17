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
$filterTypeId = isset($_GET['filter_type_id']) ? (int)$_GET['filter_type_id'] : 0;
$filterSearch = trim($_GET['search'] ?? '');

// --- Actions ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add Entity Type
    if ($action === 'add_type') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fieldDefs = trim($_POST['field_definitions'] ?? '[]');
        if (json_decode($fieldDefs) === null) $fieldDefs = '[]';

        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO entity_types (project_id, name, description, field_definitions, created_at) VALUES (:project_id, :name, :description, :field_definitions, NOW())');
            $stmt->execute(['project_id' => $projectId, 'name' => $name, 'description' => $description, 'field_definitions' => $fieldDefs]);
            $message = 'Entity-Typ gespeichert.';
        }
    }

    // Update Entity Type
    if ($action === 'update_type') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fieldDefs = trim($_POST['field_definitions'] ?? '[]');
        if (json_decode($fieldDefs) === null) $fieldDefs = '[]';

        if ($typeId > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE entity_types SET name = :name, description = :description, field_definitions = :field_definitions WHERE id = :id AND project_id = :project_id');
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'field_definitions' => $fieldDefs,
                'id' => $typeId,
                'project_id' => $projectId
            ]);
            $message = 'Entity-Typ aktualisiert.';
        }
    }

    // Add Entity
    if ($action === 'add_entity') {
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
            try {
                $stmt = $pdo->prepare('INSERT INTO entities (project_id, type_id, name, slug, description, metadata_json, created_at) VALUES (:project_id, :type_id, :name, :slug, :description, :metadata_json, NOW())');
                $stmt->execute([
                    'project_id' => $projectId,
                    'type_id' => $typeId,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'metadata_json' => $metadataJson,
                ]);
                $message = 'Entity angelegt.';
            } catch (PDOException $e) { $error = 'Fehler: ' . $e->getMessage(); }
        }
    }

    // Update Entity
    if ($action === 'update_entity') {
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
            try {
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
                $message = 'Entity aktualisiert.';
            } catch (PDOException $e) { $error = 'Fehler: ' . $e->getMessage(); }
        }
    }

    // Axes
    if ($action === 'add_axis') {
        $axisKey = kumiai_slug(trim($_POST['axis_key'] ?? ''));
        $label = trim($_POST['axis_label'] ?? '');
        $appliesTo = trim($_POST['applies_to'] ?? 'character');
        $hasValues = isset($_POST['has_predefined_values']) ? 1 : 0;
        if ($axisKey !== '' && $label !== '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO classification_axes (axis_key, label, applies_to, has_predefined_values, created_at) VALUES (:axis_key, :label, :applies_to, :has_predefined_values, NOW())');
                $stmt->execute(['axis_key' => $axisKey, 'label' => $label, 'applies_to' => $appliesTo, 'has_predefined_values' => $hasValues]);
                $message = 'Classification Axis gespeichert.';
            } catch (PDOException $e) { $error = 'Fehler: ' . $e->getMessage(); }
        }
    }

    if ($action === 'add_axis_value') {
        $axisId = (int)($_POST['axis_id'] ?? 0);
        $valueKey = kumiai_slug(trim($_POST['value_key'] ?? ''));
        $label = trim($_POST['value_label'] ?? '');
        if ($axisId > 0 && $valueKey !== '' && $label !== '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO classification_axis_values (axis_id, value_key, label, created_at) VALUES (:axis_id, :value_key, :label, NOW())');
                $stmt->execute(['axis_id' => $axisId, 'value_key' => $valueKey, 'label' => $label]);
                $message = 'Axis Value gespeichert.';
            } catch (PDOException $e) { $error = 'Fehler: ' . $e->getMessage(); }
        }
    }
}

// --- Fetch Data ---

$typesStmt = $pdo->prepare('SELECT * FROM entity_types WHERE project_id = :project_id ORDER BY name');
$typesStmt->execute(['project_id' => $projectId]);
$entityTypes = $typesStmt->fetchAll();

// Dynamic Filter Logic
$query = 'SELECT e.*, t.name AS type_name, t.field_definitions FROM entities e JOIN entity_types t ON e.type_id = t.id WHERE e.project_id = :project_id';
$params = ['project_id' => $projectId];
$activeType = null;

if ($filterTypeId > 0) {
    $query .= ' AND e.type_id = :type_id';
    $params['type_id'] = $filterTypeId;
    foreach ($entityTypes as $et) {
        if ((int)$et['id'] === $filterTypeId) {
            $activeType = $et;
            break;
        }
    }
    // Check dynamic fields in GET
    if ($activeType && !empty($activeType['field_definitions'])) {
        $defs = json_decode($activeType['field_definitions'], true);
        if (is_array($defs)) {
            foreach ($defs as $def) {
                $key = $def['key'] ?? '';
                $paramKey = 'f_' . $key;
                if ($key !== '' && isset($_GET[$paramKey]) && trim($_GET[$paramKey]) !== '') {
                    $val = trim($_GET[$paramKey]);
                    // Sanitize key for PDO placeholder to prevent syntax errors with weird keys
                    $safeKey = md5($key);

                    // Using JSON_SEARCH or LIKE. Since it's unstructured JSON values, LIKE is safer for mixed types (though slower).
                    // We target the specific key.
                    // path: $.key
                    $query .= " AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata_json, :path_$safeKey)) LIKE :val_$safeKey";
                    $params["path_$safeKey"] = '$."' . $key . '"';
                    $params["val_$safeKey"] = '%' . $val . '%';
                }
            }
        }
    }
}

if ($filterSearch !== '') {
    $query .= ' AND (e.name LIKE :search OR e.slug LIKE :search OR e.description LIKE :search)';
    $params['search'] = '%' . $filterSearch . '%';
}

$query .= ' ORDER BY e.created_at DESC LIMIT 50';
$entitiesStmt = $pdo->prepare($query);
$entitiesStmt->execute($params);
$entities = $entitiesStmt->fetchAll();

// Axes
$axesStmt = $pdo->query('SELECT * FROM classification_axes ORDER BY applies_to, label');
$axes = $axesStmt->fetchAll();
$axisValuesStmt = $pdo->query('SELECT * FROM classification_axis_values ORDER BY axis_id, label');
$axisValues = $axisValuesStmt->fetchAll();
$axisValueMap = [];
foreach ($axisValues as $v) $axisValueMap[(int)$v['axis_id']][] = $v;

render_header('Entities');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Entities</h1>
        <small class="text-muted">Projekteinstellungen und Datenverwaltung</small>
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
    <!-- LEFT COLUMN -->
    <div class="col-md-5 col-lg-4">
        <!-- Entity Types -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="h6 mb-0">Entity-Typen</h5>
                <button class="btn btn-sm btn-primary" onclick="openTypeModal()">+ Neu</button>
            </div>
            <?php if (empty($entityTypes)): ?>
                <div class="card-body text-muted">Keine Typen definiert.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($entityTypes as $type): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($type['name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($type['description']) ?></div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary py-0" onclick='openTypeModal(<?= htmlspecialchars(json_encode($type), ENT_QUOTES, 'UTF-8') ?>)'>Edit</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Axes -->
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h5 class="h6 mb-0">Klassifizierung (Achsen)</h5></div>
            <div class="card-body">
                <?php if (!empty($axes)): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0" style="font-size: 0.9em;">
                            <thead class="table-light"><tr><th>Key</th><th>Label</th><th>Vals</th></tr></thead>
                            <tbody>
                                <?php foreach ($axes as $axis): ?>
                                    <?php $cnt = count($axisValueMap[(int)$axis['id']] ?? []); ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($axis['axis_key']) ?></code></td>
                                        <td><?= htmlspecialchars($axis['label']) ?></td>
                                        <td><?= $cnt ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary w-100" type="button" data-bs-toggle="collapse" data-bs-target="#addAxisCollapse">Achse / Wert hinzufügen</button>
                <div class="collapse mt-3" id="addAxisCollapse">
                    <form method="post" class="mb-4 border-bottom pb-3">
                        <input type="hidden" name="action" value="add_axis">
                        <h6 class="small fw-bold">Neue Achse</h6>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><input class="form-control form-control-sm" name="axis_key" placeholder="key (z.B. outfit)" required></div>
                            <div class="col-6"><input class="form-control form-control-sm" name="axis_label" placeholder="Label" required></div>
                        </div>
                        <div class="mb-2">
                            <select class="form-select form-select-sm" name="applies_to">
                                <option value="character">character</option>
                                <option value="location">location</option>
                                <option value="scene">scene</option>
                                <option value="prop">prop</option>
                                <option value="background">background</option>
                                <option value="item">item</option>
                            </select>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="has_predefined_values" id="hpv">
                            <label class="form-check-label small" for="hpv">Feste Werte</label>
                        </div>
                        <button class="btn btn-sm btn-primary w-100" type="submit">Achse anlegen</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="add_axis_value">
                        <h6 class="small fw-bold">Neuer Wert</h6>
                        <select class="form-select form-select-sm mb-2" name="axis_id" required>
                            <option value="">Achse wählen...</option>
                            <?php foreach ($axes as $axis): ?>
                                <option value="<?= (int)$axis['id'] ?>"><?= htmlspecialchars($axis['label']) ?> (<?= htmlspecialchars($axis['applies_to']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><input class="form-control form-control-sm" name="value_key" placeholder="key" required></div>
                            <div class="col-6"><input class="form-control form-control-sm" name="value_label" placeholder="Label" required></div>
                        </div>
                        <button class="btn btn-sm btn-secondary w-100" type="submit">Wert speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-md-7 col-lg-8">
        <!-- Entity List & Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="h6 mb-0">Entities</h5>
                    <button class="btn btn-primary btn-sm" onclick="openEntityModal()">+ Neue Entity</button>
                </div>
                <!-- Filter Form -->
                <form method="get" class="row g-2 align-items-center">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <div class="col-auto">
                        <select class="form-select form-select-sm" name="filter_type_id" id="filter_type_id" onchange="this.form.submit()">
                            <option value="0">Alle Typen</option>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>" <?= $filterTypeId === (int)$type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Suche..." value="<?= htmlspecialchars($filterSearch) ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Suchen</button>
                    </div>
                </form>

                <?php if ($activeType && !empty($activeType['field_definitions'])): ?>
                    <?php
                        $defs = json_decode($activeType['field_definitions'], true);
                        if (is_array($defs) && count($defs) > 0):
                    ?>
                    <form method="get" class="mt-2 pt-2 border-top">
                        <input type="hidden" name="project_id" value="<?= $projectId ?>">
                        <input type="hidden" name="filter_type_id" value="<?= $filterTypeId ?>">
                        <div class="row g-2">
                            <?php foreach ($defs as $def): ?>
                                <?php if (!empty($def['key'])): $pk = 'f_' . $def['key']; ?>
                                <div class="col-md-3 col-6">
                                    <input type="text" class="form-control form-control-sm" name="<?= htmlspecialchars($pk) ?>"
                                           placeholder="<?= htmlspecialchars($def['label'] ?? $def['key']) ?>"
                                           value="<?= htmlspecialchars($_GET[$pk] ?? '') ?>">
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-light border">Filter</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (empty($entities)): ?>
                <div class="card-body text-center text-muted">Keine Entities gefunden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Typ</th>
                                <th>Metadata</th>
                                <th class="text-end">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entities as $entity): ?>
                                <tr>
                                    <td>
                                        <a href="/entity_details.php?entity_id=<?= (int)$entity['id'] ?>" class="fw-semibold text-decoration-none">
                                            <?= htmlspecialchars($entity['name']) ?>
                                        </a>
                                        <div class="small text-muted">slug: <?= htmlspecialchars($entity['slug']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($entity['type_name']) ?></span></td>
                                    <td class="small">
                                        <?php
                                            // Show a preview of relevant metadata
                                            $md = json_decode($entity['metadata_json'] ?: '{}', true);
                                            // If type definitions exist, prioritize them
                                            $preview = [];
                                            if (!empty($entity['field_definitions'])) {
                                                $defs = json_decode($entity['field_definitions'], true);
                                                foreach ($defs as $d) {
                                                    if (isset($md[$d['key']]) && $md[$d['key']] !== '') {
                                                        $preview[] = htmlspecialchars($d['label']) . ': ' . htmlspecialchars($md[$d['key']]);
                                                    }
                                                }
                                            }
                                            // If empty, show raw first 2 entries
                                            if (empty($preview)) {
                                                $i = 0;
                                                foreach ($md as $k => $v) {
                                                    if ($i++ > 2) break;
                                                    if (is_scalar($v)) $preview[] = "$k: $v";
                                                }
                                            }
                                            echo implode(', ', $preview);
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border" onclick='openEntityModal(<?= htmlspecialchars(json_encode($entity), ENT_QUOTES, 'UTF-8') ?>)'>Edit</button>
                                        <a href="/entity_files.php?entity_id=<?= (int)$entity['id'] ?>" class="btn btn-sm btn-outline-primary">Files</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Type Modal -->
<div class="modal fade" id="typeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="typeForm">
                <input type="hidden" name="action" id="typeAction" value="add_type">
                <input type="hidden" name="type_id" id="typeId" value="">
                <input type="hidden" name="field_definitions" id="typeFieldDefs" value="[]">

                <div class="modal-header">
                    <h5 class="modal-title" id="typeModalTitle">Neuer Entity-Typ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" id="typeName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Beschreibung</label>
                            <input class="form-control" name="description" id="typeDesc">
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3">Dynamische Felder</h6>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm table-bordered" id="fieldsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:25%">Key (slug)</th>
                                    <th style="width:25%">Label</th>
                                    <th style="width:20%">Type</th>
                                    <th>Options (CSV)</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="fieldsBody"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addFieldRow()">+ Feld hinzufügen</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Entity Modal -->
<div class="modal fade" id="entityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="entityForm">
                <input type="hidden" name="action" id="entityAction" value="add_entity">
                <input type="hidden" name="entity_id" id="entityId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="entityModalTitle">Neue Entity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="entity_name" id="entityName" required oninput="generateSlug(this.value)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Slug</label>
                            <input class="form-control" name="entity_slug" id="entitySlug" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Typ</label>
                        <select class="form-select" name="type_id" id="entityTypeId" required onchange="renderEntityFields()">
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung</label>
                        <textarea class="form-control" name="entity_description" id="entityDesc" rows="2"></textarea>
                    </div>

                    <!-- Dynamic Fields Container -->
                    <div id="dynamicFieldsContainer" class="border rounded p-3 bg-light mb-3" style="display:none;">
                        <h6 class="h6 mb-3">Details</h6>
                        <div id="dynamicFieldsBody" class="row g-3"></div>
                    </div>

                    <div class="mb-3">
                        <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#rawJsonCollapse">Advanced: Raw JSON</button>
                        <div class="collapse" id="rawJsonCollapse">
                            <textarea class="form-control font-monospace small mt-2" name="metadata_json" id="entityMetadata" rows="3"></textarea>
                            <div class="form-text">Wird automatisch aktualisiert. Manuelle Änderungen überschreiben Felder.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JS -->
<script>
// Data from PHP
const entityTypes = <?= json_encode($entityTypes) ?>;
const typeMap = {};
entityTypes.forEach(t => typeMap[t.id] = t);

document.addEventListener('DOMContentLoaded', () => {
    // --- Entity Type Modal Logic ---
    const typeModalEl = document.getElementById('typeModal');
    const typeModal = new bootstrap.Modal(typeModalEl);
    const fieldsBody = document.getElementById('fieldsBody');

    function updateFieldJson() {
        const rows = fieldsBody.querySelectorAll('tr');
        const data = [];
        rows.forEach(tr => {
            const inputs = tr.querySelectorAll('input, select');
            const key = inputs[0].value.trim();
            if (key) {
                data.push({
                    key: key,
                    label: inputs[1].value.trim(),
                    type: inputs[2].value,
                    options: inputs[3].value.trim()
                });
            }
        });
        document.getElementById('typeFieldDefs').value = JSON.stringify(data);
    }

    function addFieldRow(data = {}) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input class="form-control form-control-sm" value="${data.key || ''}" placeholder="race" oninput="updateFieldJson()"></td>
            <td><input class="form-control form-control-sm" value="${data.label || ''}" placeholder="Race" oninput="updateFieldJson()"></td>
            <td>
                <select class="form-select form-select-sm" onchange="updateFieldJson()">
                    <option value="text" ${data.type === 'text' ? 'selected' : ''}>Text</option>
                    <option value="number" ${data.type === 'number' ? 'selected' : ''}>Number</option>
                    <option value="select" ${data.type === 'select' ? 'selected' : ''}>Select</option>
                    <option value="boolean" ${data.type === 'boolean' ? 'selected' : ''}>Boolean</option>
                </select>
            </td>
            <td><input class="form-control form-control-sm" value="${data.options || ''}" placeholder="A,B,C" oninput="updateFieldJson()"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="this.closest('tr').remove(); updateFieldJson()">&times;</button></td>
        `;
        fieldsBody.appendChild(tr);
        updateFieldJson();
    }

    function openTypeModal(data = null) {
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) { console.warn('Konnte Entity-Typ nicht parsen', e); data = null; }
        }
        document.getElementById('typeAction').value = data ? 'update_type' : 'add_type';
        document.getElementById('typeId').value = data ? data.id : '';
        document.getElementById('typeName').value = data ? data.name : '';
        document.getElementById('typeDesc').value = data ? data.description : '';
        document.getElementById('typeModalTitle').innerText = data ? 'Typ bearbeiten' : 'Neuer Typ';

        // Fields
        fieldsBody.innerHTML = '';
        let defs = [];
        if (data && data.field_definitions) {
            try { defs = JSON.parse(data.field_definitions); } catch(e){}
        }
        if (!Array.isArray(defs)) defs = [];
        defs.forEach(d => addFieldRow(d));

        typeModal.show();
    }

    document.getElementById('typeForm').addEventListener('submit', () => updateFieldJson()); // Ensure update on submit

    // --- Entity Modal Logic ---
    const entityModalEl = document.getElementById('entityModal');
    const entityModal = new bootstrap.Modal(entityModalEl);
    const entityTypeIdSel = document.getElementById('entityTypeId');
    const dynContainer = document.getElementById('dynamicFieldsContainer');
    const dynBody = document.getElementById('dynamicFieldsBody');
    const metadataArea = document.getElementById('entityMetadata');

    let currentEntityMetadata = {};

    function renderEntityFields() {
        const typeId = entityTypeIdSel.value;
        dynBody.innerHTML = '';

        if (!typeId || !typeMap[typeId] || !typeMap[typeId].field_definitions) {
            dynContainer.style.display = 'none';
            return;
        }

        let defs = [];
        try { defs = JSON.parse(typeMap[typeId].field_definitions); } catch(e){}
        if (!Array.isArray(defs)) defs = [];

        if (defs.length === 0) {
            dynContainer.style.display = 'none';
            return;
        }

        dynContainer.style.display = 'block';

        defs.forEach(def => {
            const col = document.createElement('div');
            col.className = 'col-md-6';

            const label = document.createElement('label');
            label.className = 'form-label small fw-bold';
            label.innerText = def.label || def.key;

            let input;
            const val = currentEntityMetadata[def.key] !== undefined ? currentEntityMetadata[def.key] : '';

            if (def.type === 'select') {
                input = document.createElement('select');
                input.className = 'form-select form-select-sm';
                input.innerHTML = '<option value=\"\">-</option>';
                if (def.options) {
                    def.options.split(',').forEach(opt => {
                        const o = opt.trim();
                        const optEl = document.createElement('option');
                        optEl.value = o;
                        optEl.innerText = o;
                        if (String(o) === String(val)) optEl.selected = true;
                        input.appendChild(optEl);
                    });
                }
            } else if (def.type === 'boolean') {
                input = document.createElement('select');
                input.className = 'form-select form-select-sm';
                input.innerHTML = '<option value=\"false\">Nein</option><option value=\"true\">Ja</option>';
                input.value = (val === true || val === 'true') ? 'true' : 'false';
            } else {
                input = document.createElement('input');
                input.className = 'form-control form-control-sm';
                input.type = def.type === 'number' ? 'number' : 'text';
                input.value = val;
            }

            input.onchange = (e) => {
                let v = e.target.value;
                if (def.type === 'boolean') v = (v === 'true');
                if (def.type === 'number') v = parseFloat(v);

                currentEntityMetadata[def.key] = v;
                metadataArea.value = JSON.stringify(currentEntityMetadata, null, 2);
            };

            col.appendChild(label);
            col.appendChild(input);
            dynBody.appendChild(col);
        });
    }

    function openEntityModal(data = null) {
        document.getElementById('entityAction').value = data ? 'update_entity' : 'add_entity';
        document.getElementById('entityId').value = data ? data.id : '';
        document.getElementById('entityName').value = data ? data.name : '';
        document.getElementById('entitySlug').value = data ? data.slug : '';
        document.getElementById('entityDesc').value = data ? data.description : '';
        document.getElementById('entityModalTitle').innerText = data ? 'Entity bearbeiten' : 'Neue Entity';

        entityTypeIdSel.value = data ? data.type_id : '';

        currentEntityMetadata = {};
        if (data && data.metadata_json) {
            try { currentEntityMetadata = (typeof data.metadata_json === 'string') ? JSON.parse(data.metadata_json) : data.metadata_json; } catch(e){}
        }
        if (!currentEntityMetadata) currentEntityMetadata = {};

        metadataArea.value = JSON.stringify(currentEntityMetadata, null, 2);

        renderEntityFields();
        entityModal.show();
    }

    function generateSlug(val) {
        if (document.getElementById('entityAction').value === 'update_entity') return; // Don't auto-change on edit
        const slug = val.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        document.getElementById('entitySlug').value = slug;
    }

    // Sync metadata textarea back to object if edited manually
    metadataArea.addEventListener('input', () => {
        try {
            currentEntityMetadata = JSON.parse(metadataArea.value);
            renderEntityFields(); // Re-render to reflect manual changes
        } catch(e) {}
    });

    window.updateFieldJson = updateFieldJson;
    window.openTypeModal = openTypeModal;
    window.addFieldRow = addFieldRow;
    window.openEntityModal = openEntityModal;
    window.generateSlug = generateSlug;
    window.renderEntityFields = renderEntityFields;
});

</script>

<?php render_footer(); ?>
