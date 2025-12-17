<?php
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/services/ai_prepass.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Nur POST wird unterstützt.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$decoded = json_decode($rawBody, true);
$assetId = null;
if (is_array($decoded) && isset($decoded['asset_id'])) {
    $assetId = (int)$decoded['asset_id'];
} elseif (isset($_POST['asset_id'])) {
    $assetId = (int)$_POST['asset_id'];
}

if (!$assetId || $assetId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'asset_id fehlt oder ist ungültig.']);
    exit;
}

$revisionId = null;
if (is_array($decoded) && isset($decoded['revision_id'])) {
    $revisionId = (int)$decoded['revision_id'];
}

$service = new AiPrepassService($pdo, $config);
$result = $service->runSubjectFirst($assetId, $revisionId, current_user());

if (!($result['success'] ?? false)) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
