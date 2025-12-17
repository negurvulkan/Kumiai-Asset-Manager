<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/naming.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/classification.php';
require_once __DIR__ . '/../includes/services/ai_classification.php';
require_login();

function parse_selected_link_ids(): array
{
    $raw = $_POST['selected_links'] ?? [];
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

$projects = user_projects($pdo);
if (empty($projects)) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-warning">Keine Projekte zugewiesen.</div>';
    render_footer();
    exit;
}

$entityId = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$entityStmt = $pdo->prepare('SELECT e.*, t.name AS type_name, t.id AS type_id FROM entities e JOIN entity_types t ON t.id = e.type_id WHERE e.id = :id');
$entityStmt->execute(['id' => $entityId]);
$entity = $entityStmt->fetch();
if (!$entity) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-danger">Entity nicht gefunden.</div>';
    render_footer();
    exit;
}

$projectId = (int)$entity['project_id'];
$projectRow = null;
foreach ($projects as $project) {
    if ((int)$project['id'] === $projectId) {
        $projectRow = $project;
        break;
    }
}
if (!$projectRow) {
    render_header('Entity Dateien');
    echo '<div class="alert alert-danger">Keine Berechtigung für dieses Projekt.</div>';
    render_footer();
    exit;
}

$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$projectStmt->execute(['id' => $projectId]);
$projectFull = $projectStmt->fetch();
$projectRoot = rtrim($projectFull['root_path'] ?? '', '/');
$projectRole = $projectRow['role'] ?? '';
$canUseAi = in_array($projectRole, ['owner', 'admin', 'editor'], true);

$message = null;
$error = null;
$aiResult = null;

$axes = load_axes_for_entity($pdo, $entity['type_name'] ?? '');
$classInputs = [];

$selectedLinks = parse_selected_link_ids();
$selectedFiles = [];

$filterRaw = [];
$filterSelections = [];
foreach ($axes as $axis) {
    $paramKey = 'filter_axis_' . $axis['id'];
    $filterSelections[$paramKey] = isset($_GET[$paramKey]) ? (string)$_GET[$paramKey] : '';
    if (isset($_GET[$paramKey])) {
        $filterRaw[$axis['axis_key']] = trim((string)$_GET[$paramKey]);
    }
}
$filterValues = normalize_axis_values($axes, $filterRaw);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['classify', 'save_axes', 'ai_classify'], true) && !empty($selectedLinks)) {
        $placeholders = implode(',', array_fill(0, count($selectedLinks), '?'));
        $fileStmt = $pdo->prepare("SELECT fi.*, l.id AS link_id FROM entity_file_links l JOIN file_inventory fi ON fi.id = l.file_inventory_id WHERE l.entity_id = ? AND fi.project_id = ? AND l.id IN ($placeholders)");
        $params = array_merge([$entityId, $projectId], $selectedLinks);
        $fileStmt->execute($params);
        $selectedFiles = $fileStmt->fetchAll();
        if (empty($selectedFiles)) {
            $error = 'Keine passenden Entity-Dateien gefunden.';
        }
    }

    if ($action === 'save_axes' && !$error && !empty($selectedFiles)) {
        $rawInputs = [];
        foreach ($axes as $axis) {
            $inputKey = 'axis_' . $axis['id'];
            if (!array_key_exists($inputKey, $_POST)) {
                continue;
            }
            $rawValue = trim($_POST[$inputKey] ?? '');
            if ($rawValue === '') {
                continue;
            }
            $rawInputs[$axis['axis_key']] = $rawValue;
        }

        $classInputs = normalize_axis_values($axes, $rawInputs);
        $existing = fetch_inventory_classifications($pdo, array_map(fn($row) => (int)$row['id'], $selectedFiles));
        $updateInventory = $pdo->prepare('UPDATE file_inventory SET classification_state = :state, last_seen_at = NOW() WHERE id = :id');

        foreach ($selectedFiles as $file) {
            $finalValues = $existing[(int)$file['id']] ?? [];
            foreach ($axes as $axis) {
                $key = $axis['axis_key'];
                if (isset($classInputs[$key])) {
                    $finalValues[$key] = $classInputs[$key];
                }
            }

            replace_inventory_classifications($pdo, (int)$file['id'], $axes, $finalValues);
            $state = derive_classification_state($axes, $finalValues);
            $updateInventory->execute([
                'state' => $state,
                'id' => $file['id'],
            ]);
        }

        $message = sprintf('Vor-Klassifizierung für %d Datei(en) gespeichert.', count($selectedFiles));
    }

    if ($action === 'classify' && !$error && !empty($selectedLinks) && !empty($selectedFiles)) {
        $newAssetType = trim($_POST['new_asset_type'] ?? 'concept');
        $newAssetDescription = trim($_POST['new_asset_description'] ?? '');
        $displayName = trim($_POST['new_asset_display_name'] ?? '');
        $rawInputs = [];
        $assetId = 0;
        $classificationReady = false;
        $missingAxes = [];
        $mismatchedAxes = [];
        $assetRow = null;
        $assetKey = null;
        $assetClassifications = [];

        foreach ($axes as $axis) {
            $inputKey = 'axis_' . $axis['id'];
            if (!array_key_exists($inputKey, $_POST)) {
                continue;
            }
            $rawValue = trim($_POST[$inputKey] ?? '');
            if ($rawValue === '') {
                continue;
            }
            $rawInputs[$axis['axis_key']] = $rawValue;
        }

        $classInputs = normalize_axis_values($axes, $rawInputs);
        $existingInventoryClasses = fetch_inventory_classifications($pdo, array_map(fn($row) => (int)$row['id'], $selectedFiles));
        $classificationMap = [];
        $requestedMap = [];
        $conflicts = [];

        foreach ($axes as $axis) {
            $key = $axis['axis_key'];
            $override = $classInputs[$key] ?? '';
            if ($override !== '') {
                $classificationMap[$key] = $override;
                continue;
            }

            $valuesForAxis = [];
            foreach ($selectedFiles as $file) {
                $value = $existingInventoryClasses[(int)$file['id']][$key] ?? '';
                if ($value !== '') {
                    $valuesForAxis[$value] = true;
                }
            }

            if (count($valuesForAxis) > 1) {
                $conflicts[$key] = array_keys($valuesForAxis);
                continue;
            }

            if (count($valuesForAxis) === 1) {
                $classificationMap[$key] = array_keys($valuesForAxis)[0];
            }
        }

        if (!empty($conflicts)) {
            $error = 'Konflikt in gespeicherten Achsenwerten: ' . implode(', ', array_map(fn($key, $values) => $key . ' (' . implode(' / ', $values) . ')', array_keys($conflicts), $conflicts)) . '. Bitte einen eindeutigen Wert wählen.';
        }

        if (!$error) {
            $requestedMap = $classificationMap;
            foreach ($axes as $axis) {
                $value = $classificationMap[$axis['axis_key']] ?? '';
                if ($value === '') {
                    $missingAxes[] = $axis['label'] ?? $axis['axis_key'];
                }
            }

            if (empty($missingAxes)) {
                $assetKey = build_asset_key($entity, $axes, $classificationMap);

                $assetLookup = $pdo->prepare('SELECT a.*, e.name AS primary_entity_name, e.slug AS primary_entity_slug, et.name AS primary_entity_type FROM assets a LEFT JOIN entities e ON a.primary_entity_id = e.id LEFT JOIN entity_types et ON e.type_id = et.id WHERE a.project_id = :project_id AND a.asset_key = :asset_key LIMIT 1');
                $assetLookup->execute(['project_id' => $projectId, 'asset_key' => $assetKey]);
                $existingAsset = $assetLookup->fetch();

                if ($existingAsset) {
                    $assetRow = $existingAsset;
                    $assetClassifications = fetch_asset_classifications($pdo, (int)$existingAsset['id']);
                    $assetId = (int)$existingAsset['id'];

                    foreach ($axes as $axis) {
                        $key = $axis['axis_key'];
                        $assetValue = $assetClassifications[$key] ?? '';
                        $requestedValue = $requestedMap[$key] ?? '';
                        if ($assetValue !== '' && $requestedValue !== '' && $assetValue !== $requestedValue) {
                            $mismatchedAxes[] = $axis['label'] ?? $key;
                        }
                    }

                    if (!empty($assetClassifications) && empty($mismatchedAxes)) {
                        $classificationMap = $assetClassifications;
                    }

                    $classificationReady = empty($mismatchedAxes);
                } else {
                    $classificationReady = true;
                }
            }
        }

        $updateInventory = $pdo->prepare('UPDATE file_inventory SET classification_state = :state, last_seen_at = NOW() WHERE id = :id');
        $finalValuesByFile = [];

        foreach ($selectedFiles as $file) {
            $finalValues = $existingInventoryClasses[(int)$file['id']] ?? [];

            foreach ($axes as $axis) {
                $key = $axis['axis_key'];
                $value = $classificationMap[$key] ?? '';
                if ($value !== '') {
                    $finalValues[$key] = $value;
                }
            }

            replace_inventory_classifications($pdo, (int)$file['id'], $axes, $finalValues);
            $state = derive_classification_state($axes, $finalValues);
            $updateInventory->execute([
                'state' => $state,
                'id' => $file['id'],
            ]);
            $finalValuesByFile[(int)$file['id']] = ['values' => $finalValues, 'state' => $state];
        }

        if (!$classificationReady || $error) {
            if (!$error) {
                $parts = [];
                if (!empty($missingAxes)) {
                    $parts[] = 'fehlende Achsen: ' . implode(', ', $missingAxes);
                }
                if (!empty($mismatchedAxes)) {
                    $parts[] = 'abweichende Achsen zum Asset: ' . implode(', ', $mismatchedAxes);
                }
                $error = 'Revision nicht angelegt – ' . implode('; ', $parts) . '.';
            }
            $message = ($message ? $message . ' ' : '') . sprintf('Vor-Klassifizierung für %d Datei(en) aktualisiert.', count($selectedFiles));
        }

        if (!$error && $classificationReady) {
            if (!$assetRow) {
                $assetInsert = $pdo->prepare('INSERT INTO assets (project_id, name, asset_key, display_name, asset_type, primary_entity_id, description, status, created_by, created_at) VALUES (:project_id, :name, :asset_key, :display_name, :asset_type, :primary_entity_id, :description, "active", :created_by, NOW())');
                $assetInsert->execute([
                    'project_id' => $projectId,
                    'name' => $assetKey,
                    'asset_key' => $assetKey,
                    'display_name' => $displayName !== '' ? $displayName : null,
                    'asset_type' => $newAssetType ?: 'concept',
                    'primary_entity_id' => $entityId,
                    'description' => $newAssetDescription,
                    'created_by' => current_user()['id'],
                ]);
                $assetId = (int)$pdo->lastInsertId();

                if (str_contains($assetKey, 'pending')) {
                    $assetKey = build_asset_key($entity, $axes, $classificationMap, $assetId);
                    $pdo->prepare('UPDATE assets SET asset_key = :asset_key, name = :asset_key WHERE id = :id')->execute([
                        'asset_key' => $assetKey,
                        'id' => $assetId,
                    ]);
                }

                replace_asset_classifications($pdo, $assetId, $axes, $classificationMap);
                $assetRow = [
                    'id' => $assetId,
                    'asset_key' => $assetKey,
                    'asset_type' => $newAssetType ?: 'concept',
                    'primary_entity_id' => $entityId,
                    'primary_entity_name' => $entity['name'],
                    'primary_entity_slug' => $entity['slug'],
                    'primary_entity_type' => $entity['type_name'],
                    'display_name' => $displayName,
                ];
                $message = 'Asset erstellt.';
            }

            $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) AS max_version FROM asset_revisions WHERE asset_id = :asset_id');
            $versionStmt->execute(['asset_id' => $assetId]);
            $nextVersion = ((int)$versionStmt->fetchColumn()) + 1;
            $revisionClassStmt = $pdo->prepare('INSERT INTO revision_classifications (revision_id, axis_id, value_key) VALUES (:revision_id, :axis_id, :value_key)');
            $viewLabel = $classificationMap['view'] ?? 'main';
            $updateInventoryLink = $pdo->prepare('UPDATE file_inventory SET status = "linked", classification_state = :state, asset_revision_id = :revision_id, file_path = :file_path, file_hash = :file_hash, mime_type = :mime_type, file_size_bytes = :file_size_bytes, last_seen_at = NOW() WHERE id = :id');

            foreach ($selectedFiles as $file) {
                $targetPath = sanitize_relative_path($file['file_path']);
                $meta = collect_file_metadata($projectRoot . $file['file_path']);
                $extension = extension_from_path($file['file_path']);
                $generated = generate_revision_path($projectFull, $assetRow, ['id' => $entityId, 'name' => $entity['name'], 'slug' => $entity['slug'], 'type' => $entity['type_name']], $nextVersion, $extension, $viewLabel, [], $classificationMap);
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
                        $error = sprintf('Datei %s konnte nicht verschoben werden.', $file['file_path']);
                    } else {
                        $meta = collect_file_metadata($destination);
                        generate_thumbnail($projectId, $finalTarget, $destination);
                    }
                }

                if ($error) {
                    break;
                }

                $revStmt = $pdo->prepare('INSERT INTO asset_revisions (asset_id, version, file_path, file_hash, mime_type, file_size_bytes, width, height, created_by, created_at, review_status) VALUES (:asset_id, :version, :file_path, :file_hash, :mime_type, :file_size_bytes, :width, :height, :created_by, NOW(), "pending")');
                $revStmt->execute([
                    'asset_id' => $assetId,
                    'version' => $nextVersion,
                    'file_path' => $finalTarget,
                    'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
                    'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
                    'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
                    'width' => $meta['width'],
                    'height' => $meta['height'],
                    'created_by' => current_user()['id'],
                ]);
                $revisionId = (int)$pdo->lastInsertId();

                foreach ($axes as $axis) {
                    $valueKey = $classificationMap[$axis['axis_key']] ?? '';
                    if ($valueKey === '') {
                        continue;
                    }
                    $revisionClassStmt->execute([
                        'revision_id' => $revisionId,
                        'axis_id' => $axis['id'],
                        'value_key' => $valueKey,
                    ]);
                }

                $state = $finalValuesByFile[(int)$file['id']]['state'] ?? derive_classification_state($axes, $classificationMap);
                $updateInventoryLink->execute([
                    'state' => $state,
                    'revision_id' => $revisionId,
                    'file_path' => $finalTarget,
                    'file_hash' => $meta['file_hash'] ?? $file['file_hash'],
                    'mime_type' => $meta['mime_type'] ?? ($file['mime_type'] ?? 'application/octet-stream'),
                    'file_size_bytes' => $meta['file_size_bytes'] ?? ($file['file_size_bytes'] ?? 0),
                    'id' => $file['id'],
                ]);

                $nextVersion++;
            }

            if (!$error) {
                $message = ($message ? $message . ' ' : '') . sprintf('%d Datei(en) klassifiziert und als Revision gespeichert.', count($selectedFiles));
            }
        }
    }

    if ($action === 'ai_classify' && !$error) {
        if (!$canUseAi) {
            $error = 'Nur Admins/Editor dürfen den KI-Service nutzen.';
        } elseif (empty($selectedFiles)) {
            $error = 'Bitte mindestens eine Datei auswählen.';
        } else {
            $target = $selectedFiles[0];
            $service = new AiClassificationService($pdo, $config);
            $aiResult = $service->classifyInventoryFile((int)$target['id'], current_user());
            if (!($aiResult['success'] ?? false)) {
                $error = 'KI-Lauf fehlgeschlagen: ' . ($aiResult['error'] ?? 'Unbekannter Fehler');
            }
        }
    }
}

$filterJoins = '';
$filterParams = ['entity_id' => $entityId, 'project_id' => $projectId];
$joinIndex = 0;
foreach ($axes as $axis) {
    $filterValue = $filterValues[$axis['axis_key']] ?? '';
    if ($filterValue === '') {
        continue;
    }

    $alias = 'ic' . $joinIndex;
    $filterJoins .= " JOIN inventory_classifications $alias ON $alias.file_inventory_id = fi.id AND $alias.axis_id = :{$alias}_axis AND $alias.value_key = :{$alias}_value";
    $filterParams[$alias . '_axis'] = $axis['id'];
    $filterParams[$alias . '_value'] = $filterValue;
    $joinIndex++;
}

$linkQuery = 'SELECT fi.*, l.id AS link_id, l.notes FROM entity_file_links l JOIN file_inventory fi ON fi.id = l.file_inventory_id' . $filterJoins . ' WHERE l.entity_id = :entity_id AND fi.project_id = :project_id AND (fi.classification_state <> "fully_classified" OR fi.asset_revision_id IS NULL) ORDER BY fi.last_seen_at DESC';
$linkStmt = $pdo->prepare($linkQuery);
$linkStmt->execute($filterParams);
$links = $linkStmt->fetchAll();

$linkInventoryIds = array_map(fn($row) => (int)$row['id'], $links);
$linkClassifications = fetch_inventory_classifications($pdo, $linkInventoryIds);

$inventoryThumbs = [];
foreach ($links as $file) {
    $thumb = thumbnail_public_if_exists($projectId, $file['file_path']);
    $absolutePath = $projectRoot . $file['file_path'];
    if (!$thumb && $projectRoot !== '' && file_exists($absolutePath)) {
        $thumb = generate_thumbnail($projectId, $file['file_path'], $absolutePath, 200);
    }
    $inventoryThumbs[(int)$file['id']] = $thumb;
}

if (empty($selectedLinks) && !empty($links)) {
    $selectedLinks = [(int)$links[0]['link_id']];
}

$selectedFile = null;
foreach ($links as $file) {
    if ((int)$file['link_id'] === (int)($selectedLinks[0] ?? 0)) {
        $selectedFile = $file;
        break;
    }
}

$selectedMeta = null;
$selectedPreview = null;
$selectedClassificationValues = [];
if ($selectedFile) {
    $selectedPreview = $inventoryThumbs[(int)$selectedFile['id']] ?? null;
    $absolute = $projectRoot . $selectedFile['file_path'];
    if ($projectRoot !== '' && file_exists($absolute)) {
        $selectedMeta = collect_file_metadata($absolute);
    }
    $selectedClassificationValues = $linkClassifications[(int)$selectedFile['id']] ?? [];
}

if (empty($classInputs) && !empty($selectedClassificationValues)) {
    $classInputs = $selectedClassificationValues;
}

render_header('Entity Files');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Unklassifizierte Dateien · <?= htmlspecialchars($entity['name']) ?></h1>
        <small class="text-muted">Entity-first Workflow mit Schritt-für-Schritt-Klassifizierung.</small>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="/entities.php?project_id=<?= (int)$projectId ?>">Zurück zu Entities</a>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <form method="post" id="entity-bulk-form" class="card shadow-sm h-100">
            <input type="hidden" name="action" id="entity-bulk-action" value="">
            <input type="hidden" name="selected_links" id="entity-bulk-selected" value="">
            <div class="card-header">
                <div class="fw-semibold">Dateien dieser Entity (<?= count($links) ?>)</div>
                <small class="text-muted">Offene Dateien (keine Revision)</small>
            </div>
            <div class="card-body p-0" style="max-height: 70vh; overflow-y: auto;">
                <div class="p-3 border-bottom">
                    <form method="get" class="row g-2 align-items-end">
                        <input type="hidden" name="entity_id" value="<?= (int)$entityId ?>">
                        <?php foreach ($axes as $axis): ?>
                            <?php $filterName = 'filter_axis_' . (int)$axis['id']; ?>
                            <div class="col-12">
                                <label class="form-label mb-1" for="<?= htmlspecialchars($filterName) ?>">Filter: <?= htmlspecialchars($axis['label']) ?></label>
                                <?php if (!empty($axis['values'])): ?>
                                    <select class="form-select form-select-sm" name="<?= htmlspecialchars($filterName) ?>" id="<?= htmlspecialchars($filterName) ?>">
                                        <option value="">–</option>
                                        <?php foreach ($axis['values'] as $value): ?>
                                            <option value="<?= htmlspecialchars($value['value_key']) ?>" <?= ($filterSelections[$filterName] ?? '') === $value['value_key'] ? 'selected' : '' ?>><?= htmlspecialchars($value['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control form-control-sm" type="text" name="<?= htmlspecialchars($filterName) ?>" id="<?= htmlspecialchars($filterName) ?>" value="<?= htmlspecialchars($filterSelections[$filterName] ?? '') ?>" placeholder="Wert oder Slug">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Filter anwenden</button>
                            <a class="btn btn-sm btn-outline-secondary" href="/entity_files.php?entity_id=<?= (int)$entityId ?>">Zurücksetzen</a>
                        </div>
                    </form>
                </div>
                <?php if (empty($links)): ?>
                    <div class="p-3 text-muted">Keine offenen Dateien für diese Entity.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($links as $file): ?>
                            <?php $checked = in_array((int)$file['link_id'], $selectedLinks, true); ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <input class="form-check-input entity-file-checkbox" type="checkbox" value="<?= (int)$file['link_id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                <div class="flex-shrink-0">
                                    <?php $thumb = $inventoryThumbs[(int)$file['id']] ?? null; ?>
                                    <?php if ($thumb): ?>
                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="Preview" class="inventory-thumb rounded border">
                                    <?php else: ?>
                                        <div class="inventory-thumb placeholder rounded border d-flex align-items-center justify-content-center bg-light text-muted">n/a</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small mb-1">#<?= (int)$file['id'] ?> · <code><?= htmlspecialchars($file['file_path']) ?></code></div>
                                    <div class="small text-muted">State: <?= htmlspecialchars($file['classification_state']) ?> · Last seen <?= htmlspecialchars($file['last_seen_at']) ?></div>
                                    <?php $savedValues = $linkClassifications[(int)$file['id']] ?? []; ?>
                                    <?php if (!empty($savedValues)): ?>
                                        <div class="small text-muted">Vor-Klassifizierung: <?= htmlspecialchars(implode(', ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys($savedValues), $savedValues))) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($file['notes'])): ?>
                                        <div class="small text-muted">Notiz: <?= htmlspecialchars($file['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <p class="small text-muted mb-2">Wähle Dateien und klassifiziere sie rechts.</p>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <div class="fw-semibold">Preview &amp; Metadaten</div>
                <small class="text-muted">Aus der aktuellen Auswahl.</small>
            </div>
            <div class="card-body">
                <?php if (!$selectedFile): ?>
                    <p class="text-muted">Wähle eine Datei aus der Liste links.</p>
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
                        <dt class="col-sm-4">State</dt>
                        <dd class="col-sm-8"><span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($selectedFile['classification_state']) ?></span></dd>
                        <?php if ($selectedFile['classification_state'] !== 'fully_classified'): ?>
                            <dd class="col-sm-12 text-muted small">Vor-Klassifizierung gespeichert – eine Revision wird erst angelegt, wenn alle Achsen ausgefüllt sind und die Werte zum Ziel-Asset passen.</dd>
                        <?php endif; ?>
                        <dt class="col-sm-4">Hash</dt>
                        <dd class="col-sm-8"><span class="small text-monospace"><?= htmlspecialchars($selectedFile['file_hash'] ?? '') ?></span></dd>
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
                        <?php if (!empty($selectedClassificationValues)): ?>
                            <dt class="col-sm-4">Vor-Klassifizierung</dt>
                            <dd class="col-sm-8">
                                <div class="small text-muted mb-0"><?php foreach ($selectedClassificationValues as $axisKey => $valueKey): ?><span class="me-2"><strong><?= htmlspecialchars($axisKey) ?>:</strong> <?= htmlspecialchars($valueKey) ?></span><?php endforeach; ?></div>
                            </dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($canUseAi): ?>
                        <div class="border-top pt-3 mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <div class="fw-semibold">KI-Klassifizierung</div>
                                    <small class="text-muted">Nur für Admin/Editor verfügbar.</small>
                                </div>
                                <form method="post" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="action" value="ai_classify">
                                    <input type="hidden" name="selected_links" class="entity-selected-target" value="">
                                    <button class="btn btn-sm btn-outline-primary" type="submit">KI-Run starten</button>
                                </form>
                            </div>
                            <?php if ($aiResult && ($aiResult['success'] ?? false)): ?>
                                <?php $decision = $aiResult['decision'] ?? []; ?>
                                <div class="mb-2">
                                    <span class="badge bg-<?= ($decision['status'] ?? '') === 'auto_assigned' ? 'success' : 'warning text-dark' ?>">
                                        <?= htmlspecialchars($decision['status'] ?? 'needs_review') ?>
                                    </span>
                                    <span class="small text-muted ms-2"><?= htmlspecialchars($decision['reason'] ?? 'Ohne Begründung') ?></span>
                                </div>
                                <dl class="row small mb-3">
                                    <dt class="col-sm-4">Overall</dt>
                                    <dd class="col-sm-8"><?= number_format((float)($decision['overall_confidence'] ?? 0.0), 3) ?></dd>
                                    <dt class="col-sm-4">Schwelle</dt>
                                    <dd class="col-sm-8"><?= number_format((float)($decision['score_threshold'] ?? 0.0), 3) ?></dd>
                                    <dt class="col-sm-4">Margin</dt>
                                    <dd class="col-sm-8"><?= number_format((float)($decision['score_margin'] ?? 0.0), 3) ?> (Runner-up <?= number_format((float)($decision['runner_up_score'] ?? 0.0), 3) ?>)</dd>
                                </dl>
                                <div class="small text-muted mb-1">Vorschläge:</div>
                                <div class="list-group list-group-flush small">
                                    <?php foreach ($aiResult['candidates'] ?? [] as $candidate): ?>
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
                            <?php elseif ($aiResult && !($aiResult['success'] ?? false)): ?>
                                <div class="alert alert-warning mb-0 small"><?= htmlspecialchars($aiResult['error'] ?? 'Unbekannter Fehler') ?></div>
                            <?php else: ?>
                                <p class="small text-muted mb-0">Starte einen Lauf, um Vorschläge (Score, Margin, Auto-Assign-Status) zu sehen.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card shadow-sm">
            <div class="card-header">
                <div class="fw-semibold">Schrittweise Klassifizierung</div>
                <small class="text-muted">Outfit/Pose/View oder frei definierte Achsen.</small>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" id="classification-action" value="classify">
                    <input type="hidden" name="selected_links" class="entity-selected-target" value="">
                    <?php if (empty($axes)): ?>
                        <p class="text-muted small">Keine Achsen für diesen Entity-Typ konfiguriert.</p>
                    <?php else: ?>
                        <?php foreach ($axes as $axis): ?>
                            <div class="mb-2">
                                <label class="form-label" for="axis_<?= (int)$axis['id'] ?>"><?= htmlspecialchars($axis['label']) ?></label>
                                <?php if (!empty($axis['values'])): ?>
                                    <select class="form-select form-select-sm axis-field" name="axis_<?= (int)$axis['id'] ?>" id="axis_<?= (int)$axis['id'] ?>" data-axis-key="<?= htmlspecialchars($axis['axis_key']) ?>">
                                        <option value="">–</option>
                                        <?php foreach ($axis['values'] as $value): ?>
                                            <option value="<?= htmlspecialchars($value['value_key']) ?>" <?= (($classInputs[$axis['axis_key']] ?? '') === $value['value_key']) ? 'selected' : '' ?>><?= htmlspecialchars($value['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control form-control-sm axis-field" name="axis_<?= (int)$axis['id'] ?>" id="axis_<?= (int)$axis['id'] ?>" data-axis-key="<?= htmlspecialchars($axis['axis_key']) ?>" value="<?= htmlspecialchars($classInputs[$axis['axis_key']] ?? '') ?>" placeholder="Wert">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <hr>
                    <div class="mb-2">
                        <label class="form-label">Asset-Key (aus Entity + Achsen)</label>
                        <div class="form-control form-control-sm bg-light" id="asset_key_preview">Wird nach Eingabe der Achsen berechnet.</div>
                        <small class="text-muted">Existiert die Kombination, wird automatisch die nächste Revision angelegt.</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="new_asset_display_name">Anzeigename (optional)</label>
                        <input class="form-control form-control-sm" name="new_asset_display_name" id="new_asset_display_name" placeholder="Freies Label für Listen">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="new_asset_type">Asset-Typ</label>
                        <select class="form-select form-select-sm" name="new_asset_type" id="new_asset_type">
                            <option value="concept">concept</option>
                            <option value="outfit_ref">outfit_ref</option>
                            <option value="character_ref">character_ref</option>
                            <option value="scene_frame">scene_frame</option>
                            <option value="other">other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_asset_description">Beschreibung</label>
                        <textarea class="form-control form-control-sm" name="new_asset_description" id="new_asset_description" rows="2"></textarea>
                    </div>
                    <p class="small text-muted">Revisionen werden nur gespeichert, wenn alle Achsen ausgefüllt sind und mit den bestehenden Asset-Klassifikationen übereinstimmen. Ansonsten bleibt die Auswahl im Vor-Klassifizierungsstatus.</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary" type="button" onclick="submitClassification('save_axes')">Nur Vor-Klassifizierung speichern</button>
                        <button class="btn btn-primary" type="button" onclick="submitClassification('classify')">Auswahl klassifizieren &amp; speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const checkboxes = document.querySelectorAll('.entity-file-checkbox');
    const targetInputs = document.querySelectorAll('.entity-selected-target');
    const previewInput = document.getElementById('entity-bulk-selected');

    function syncSelection() {
        const ids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
        const value = ids.join(',');
        if (targetInputs.length > 0) {
            targetInputs.forEach((input) => input.value = value);
        }
        if (previewInput) previewInput.value = value;
    }

    checkboxes.forEach(cb => cb.addEventListener('change', syncSelection));
    syncSelection();
})();
</script>
<script>
(function() {
    const actionInput = document.getElementById('classification-action');
    const classificationForm = actionInput ? actionInput.closest('form') : null;

    window.submitClassification = function(action) {
        if (!actionInput || !classificationForm) {
            return;
        }
        actionInput.value = action;
        classificationForm.submit();
    };
})();
</script>
<script>
(function() {
    const preview = document.getElementById('asset_key_preview');
    const axisFields = document.querySelectorAll('.axis-field');
    if (!preview || axisFields.length === 0) {
        return;
    }

    const entitySlug = <?= json_encode(kumiai_slug($entity['slug'] ?? $entity['name'])) ?>;
    const axisOrder = <?= json_encode(array_map(fn($axis) => $axis['axis_key'], $axes)) ?>;

    const slugify = (value) => {
        return (value || '')
            .toString()
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'item';
    };

    const updatePreview = () => {
        const values = {};
        axisFields.forEach((field) => {
            const key = field.dataset.axisKey;
            if (!key) return;
            values[key] = slugify(field.value || '');
        });

        const parts = [entitySlug];
        axisOrder.forEach((key) => {
            const value = values[key] || '';
            if (value) {
                parts.push(value);
            }
        });

        if (parts.length === 1) {
            parts.push('misc', 'pending');
        }

        preview.textContent = parts.join('_');
    };

    axisFields.forEach((field) => field.addEventListener('input', updatePreview));
    updatePreview();
})();
</script>
<?php render_footer(); ?>
