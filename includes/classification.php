<?php
require_once __DIR__ . '/naming.php';

function entity_type_key(string $typeName): string
{
    $slug = kumiai_slug($typeName);
    if (str_starts_with($slug, 'char')) {
        return 'character';
    }
    if (str_starts_with($slug, 'loc')) {
        return 'location';
    }
    if (str_starts_with($slug, 'scene')) {
        return 'scene';
    }
    if (str_starts_with($slug, 'chapter')) {
        return 'chapter';
    }
    if (str_starts_with($slug, 'background')) {
        return 'background';
    }
    if (str_starts_with($slug, 'prop')) {
        return 'prop';
    }
    if (str_starts_with($slug, 'creature')) {
        return 'creature';
    }
    if (str_starts_with($slug, 'item')) {
        return 'item';
    }
    return 'project_custom';
}

function load_axes_for_entity(PDO $pdo, string $entityType): array
{
    $key = entity_type_key($entityType);
    $stmt = $pdo->prepare('SELECT * FROM classification_axes WHERE applies_to = :applies_to ORDER BY id');
    $stmt->execute(['applies_to' => $key]);
    $axes = $stmt->fetchAll();
    if (!$axes) {
        return [];
    }

    $axisIds = array_column($axes, 'id');
    $placeholders = implode(',', array_fill(0, count($axisIds), '?'));
    $valuesStmt = $pdo->prepare("SELECT * FROM classification_axis_values WHERE axis_id IN ($placeholders) ORDER BY id");
    $valuesStmt->execute($axisIds);
    $values = $valuesStmt->fetchAll();

    $grouped = [];
    foreach ($axes as $axis) {
        $grouped[(int)$axis['id']] = $axis + ['values' => []];
    }
    foreach ($values as $value) {
        $grouped[(int)$value['axis_id']]['values'][] = $value;
    }

    return array_values($grouped);
}

function normalize_axis_values(array $axes, array $values): array
{
    $normalized = [];
    foreach ($axes as $axis) {
        $key = $axis['axis_key'];
        $value = $values[$key] ?? '';
        if ($value === '' || $value === null) {
            continue;
        }

        $normalized[$key] = !empty($axis['values']) ? $value : kumiai_slug((string)$value);
    }

    return $normalized;
}

function build_asset_key(array $entity, array $axes, array $values, ?int $assetIdForMisc = null): string
{
    $slug = kumiai_slug($entity['slug'] ?? ($entity['name'] ?? 'item'));
    $parts = [$slug];

    foreach ($axes as $axis) {
        $value = $values[$axis['axis_key']] ?? '';
        if ($value === '' || $value === null) {
            continue;
        }
        $parts[] = kumiai_slug((string)$value);
    }

    if (count($parts) === 1) {
        $parts[] = 'misc';
        $parts[] = $assetIdForMisc ? str_pad((string)$assetIdForMisc, 3, '0', STR_PAD_LEFT) : 'pending';
    }

    return implode('_', $parts);
}

function fetch_asset_classifications(PDO $pdo, int $assetId): array
{
    $stmt = $pdo->prepare('SELECT ca.axis_key, ac.value_key FROM asset_classifications ac JOIN classification_axes ca ON ca.id = ac.axis_id WHERE ac.asset_id = :asset_id');
    $stmt->execute(['asset_id' => $assetId]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['axis_key']] = $row['value_key'];
    }

    return $map;
}

function replace_asset_classifications(PDO $pdo, int $assetId, array $axes, array $values): void
{
    $pdo->prepare('DELETE FROM asset_classifications WHERE asset_id = :asset_id')->execute(['asset_id' => $assetId]);
    if (empty($values)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO asset_classifications (asset_id, axis_id, value_key) VALUES (:asset_id, :axis_id, :value_key)');
    foreach ($axes as $axis) {
        $key = $axis['axis_key'];
        if (!isset($values[$key]) || $values[$key] === '') {
            continue;
        }
        $stmt->execute([
            'asset_id' => $assetId,
            'axis_id' => $axis['id'],
            'value_key' => $values[$key],
        ]);
    }
}

function fetch_inventory_classifications(PDO $pdo, array $inventoryIds): array
{
    if (empty($inventoryIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($inventoryIds), '?'));
    $stmt = $pdo->prepare("SELECT ic.file_inventory_id, ca.axis_key, ic.value_key FROM inventory_classifications ic JOIN classification_axes ca ON ca.id = ic.axis_id WHERE ic.file_inventory_id IN ($placeholders)");
    $stmt->execute($inventoryIds);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $fileId = (int)$row['file_inventory_id'];
        if (!isset($map[$fileId])) {
            $map[$fileId] = [];
        }
        $map[$fileId][$row['axis_key']] = $row['value_key'];
    }

    return $map;
}

function replace_inventory_classifications(PDO $pdo, int $inventoryId, array $axes, array $values): void
{
    $pdo->prepare('DELETE FROM inventory_classifications WHERE file_inventory_id = :id')->execute(['id' => $inventoryId]);
    if (empty($values)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO inventory_classifications (file_inventory_id, axis_id, value_key) VALUES (:inventory_id, :axis_id, :value_key)');
    foreach ($axes as $axis) {
        $key = $axis['axis_key'];
        if (!isset($values[$key]) || $values[$key] === '') {
            continue;
        }
        $stmt->execute([
            'inventory_id' => $inventoryId,
            'axis_id' => $axis['id'],
            'value_key' => $values[$key],
        ]);
    }
}

function replace_revision_classifications(PDO $pdo, int $revisionId, array $axes, array $values): void
{
    $pdo->prepare('DELETE FROM revision_classifications WHERE revision_id = :revision_id')->execute(['revision_id' => $revisionId]);
    if (empty($values)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO revision_classifications (revision_id, axis_id, value_key) VALUES (:revision_id, :axis_id, :value_key)');
    foreach ($axes as $axis) {
        $key = $axis['axis_key'];
        if (!isset($values[$key]) || $values[$key] === '') {
            continue;
        }

        $stmt->execute([
            'revision_id' => $revisionId,
            'axis_id' => $axis['id'],
            'value_key' => $values[$key],
        ]);
    }
}

function derive_classification_state(array $axes, array $values): string
{
    $normalized = array_change_key_case($values, CASE_LOWER);
    $axisKeys = array_map(fn($axis) => strtolower($axis['axis_key']), $axes);
    if (empty($axes)) {
        return 'fully_classified';
    }

    $hasAllValues = count(array_filter($axisKeys, fn($key) => ($normalized[$key] ?? '') !== '')) === count($axisKeys);

    if ($hasAllValues) {
        return 'fully_classified';
    }

    if (($normalized['view'] ?? '') !== '') {
        return 'view_assigned';
    }
    if (($normalized['pose'] ?? '') !== '') {
        return 'pose_assigned';
    }
    if (($normalized['outfit'] ?? '') !== '') {
        return 'outfit_assigned';
    }

    return 'entity_only';
}
