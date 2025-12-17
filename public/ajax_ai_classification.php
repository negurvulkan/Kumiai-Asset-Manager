<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/services/ai_classification.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$inventoryId = (int)($_POST['inventory_id'] ?? 0);
if ($inventoryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'inventory_id fehlt oder ist ungültig.']);
    exit;
}

$service = new AiClassificationService($pdo, $config);
$result = $service->classifyInventoryFile($inventoryId, current_user());

if (!$result['success']) {
    if (str_contains($result['error'] ?? '', 'Berechtigung')) {
        http_response_code(403);
    } else {
        http_response_code(400);
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
