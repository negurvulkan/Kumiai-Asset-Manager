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
