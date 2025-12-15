<?php
require_once __DIR__ . '/../includes/auth.php';

// Ensure JSON response
header('Content-Type: application/json');

// Check login
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false];

/**
 * Verifies that the current user has access to the project owning the given entity.
 * Returns the project_id if successful, throws Exception otherwise.
 */
function verify_entity_access(PDO $pdo, int $entityId): int {
    if ($entityId <= 0) {
        throw new Exception('Invalid entity ID');
    }

    $stmt = $pdo->prepare('SELECT project_id FROM entities WHERE id = :id');
    $stmt->execute(['id' => $entityId]);
    $entity = $stmt->fetch();

    if (!$entity) {
        throw new Exception('Entity not found');
    }

    $projectId = (int)$entity['project_id'];
    $projects = user_projects($pdo);
    $hasAccess = false;
    foreach ($projects as $p) {
        if ((int)$p['id'] === $projectId) {
            $hasAccess = true;
            break;
        }
    }

    if (!$hasAccess) {
        throw new Exception('Access denied');
    }

    return $projectId;
}

try {
    if ($action === 'list') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        verify_entity_access($pdo, $entityId);

        $stmt = $pdo->prepare('SELECT * FROM entity_infos WHERE entity_id = :entity_id ORDER BY sort_order ASC, created_at ASC');
        $stmt->execute(['entity_id' => $entityId]);
        $response['data'] = $stmt->fetchAll();
        $response['success'] = true;

    } elseif ($action === 'create') {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($title === '') {
            throw new Exception('Missing title');
        }

        verify_entity_access($pdo, $entityId);

        $stmt = $pdo->prepare('INSERT INTO entity_infos (entity_id, title, content, created_at) VALUES (:entity_id, :title, :content, NOW())');
        $stmt->execute(['entity_id' => $entityId, 'title' => $title, 'content' => $content]);

        $response['id'] = $pdo->lastInsertId();
        $response['success'] = true;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($id <= 0 || $title === '') {
            throw new Exception('Missing required fields');
        }

        // Get entity_id from the info record to verify access
        $stmt = $pdo->prepare('SELECT entity_id FROM entity_infos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $info = $stmt->fetch();

        if (!$info) {
            throw new Exception('Info not found');
        }

        verify_entity_access($pdo, (int)$info['entity_id']);

        $stmt = $pdo->prepare('UPDATE entity_infos SET title = :title, content = :content, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['title' => $title, 'content' => $content, 'id' => $id]);

        $response['success'] = true;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new Exception('Invalid ID');
        }

        // Get entity_id from the info record to verify access
        $stmt = $pdo->prepare('SELECT entity_id FROM entity_infos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $info = $stmt->fetch();

        if (!$info) {
            throw new Exception('Info not found');
        }

        verify_entity_access($pdo, (int)$info['entity_id']);

        $stmt = $pdo->prepare('DELETE FROM entity_infos WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $response['success'] = true;
    } else {
        throw new Exception('Unknown action');
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
