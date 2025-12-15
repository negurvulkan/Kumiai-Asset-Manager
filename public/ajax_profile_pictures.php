<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/files.php';
require_once __DIR__ . '/../includes/naming.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'list') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if (!$entityId) throw new Exception('Keine Entity ID');

        $stmt = $pdo->prepare('SELECT e.* FROM entities e JOIN projects p ON e.project_id = p.id WHERE e.id = :id');
        $stmt->execute(['id' => $entityId]);
        $entity = $stmt->fetch();
        if (!$entity) throw new Exception('Entity nicht gefunden');

        $projects = user_projects($pdo);
        $hasAccess = false;
        foreach ($projects as $p) {
            if ((int)$p['id'] === (int)$entity['project_id']) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) throw new Exception('Keine Berechtigung für dieses Projekt');

        $stmt = $pdo->prepare('SELECT * FROM entity_profile_pictures WHERE entity_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $entityId]);
        $pictures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $projectStmt->execute(['id' => $entity['project_id']]);
        $projectFull = $projectStmt->fetch();
        $projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

        $data = [];
        foreach ($pictures as $pic) {
            $thumb = thumbnail_public_if_exists($entity['project_id'], $pic['file_path']);
            if (!$thumb && $projectRoot !== '') {
                $absPath = $projectRoot . $pic['file_path'];
                if (file_exists($absPath)) {
                    $thumb = generate_thumbnail($entity['project_id'], $pic['file_path'], $absPath, 300);
                }
            }

            $data[] = [
                'id' => $pic['id'],
                'url' => $thumb,
                'file_path' => $pic['file_path'] // Useful for debugging or advanced UI
            ];
        }

        echo json_encode(['success' => true, 'data' => $data]);

    } elseif ($action === 'upload') {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        if (!$entityId) throw new Exception('Keine Entity ID');

        $stmt = $pdo->prepare('SELECT e.* FROM entities e JOIN projects p ON e.project_id = p.id WHERE e.id = :id');
        $stmt->execute(['id' => $entityId]);
        $entity = $stmt->fetch();
        if (!$entity) throw new Exception('Entity nicht gefunden');

        $projects = user_projects($pdo);
        $hasAccess = false;
        foreach ($projects as $p) {
            if ((int)$p['id'] === (int)$entity['project_id']) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) throw new Exception('Keine Berechtigung');

        // Check Limit
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM entity_profile_pictures WHERE entity_id = :id');
        $stmt->execute(['id' => $entityId]);
        if ($stmt->fetchColumn() >= 3) {
            throw new Exception('Maximal 3 Profilbilder erlaubt.');
        }

        // Handle File
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload Fehler');
        }

        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
             throw new Exception('Nur Bilder erlaubt (jpg, png, gif, webp)');
        }

        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $projectStmt->execute(['id' => $entity['project_id']]);
        $projectFull = $projectStmt->fetch();
        $projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

        if (!is_dir($projectRoot)) {
             throw new Exception('Projekt-Root existiert nicht: ' . $projectRoot);
        }

        $relDir = '/_meta/profiles';
        $absDir = $projectRoot . $relDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0775, true);
        }

        $filename = $entity['slug'] . '_' . uniqid() . '.' . $ext;
        $relPath = $relDir . '/' . $filename;
        $absPath = $absDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            throw new Exception('Konnte Datei nicht speichern');
        }

        // Insert DB
        $stmt = $pdo->prepare('INSERT INTO entity_profile_pictures (entity_id, file_path) VALUES (:eid, :fp)');
        $stmt->execute(['eid' => $entityId, 'fp' => $relPath]);

        // Generate thumbnail immediately
        generate_thumbnail($entity['project_id'], $relPath, $absPath, 300);

        echo json_encode(['success' => true]);

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('Keine ID');

        $stmt = $pdo->prepare('SELECT p.*, e.project_id FROM entity_profile_pictures p JOIN entities e ON p.entity_id = e.id WHERE p.id = :id');
        $stmt->execute(['id' => $id]);
        $pic = $stmt->fetch();

        if (!$pic) throw new Exception('Bild nicht gefunden');

        // Permission check
        $projects = user_projects($pdo);
        $hasAccess = false;
        foreach ($projects as $p) {
            if ((int)$p['id'] === (int)$pic['project_id']) {
                $hasAccess = true;
                break;
            }
        }
        if (!$hasAccess) throw new Exception('Keine Berechtigung');

        // Delete File
        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $projectStmt->execute(['id' => $pic['project_id']]);
        $projectFull = $projectStmt->fetch();
        $projectRoot = rtrim($projectFull['root_path'] ?? '', '/');

        $absPath = $projectRoot . $pic['file_path'];
        if (file_exists($absPath)) {
            unlink($absPath);
        }

        $thumbPaths = thumbnail_target_paths($pic['project_id'], $pic['file_path']);
        if (file_exists($thumbPaths['absolute'])) {
            unlink($thumbPaths['absolute']);
        }

        $delStmt = $pdo->prepare('DELETE FROM entity_profile_pictures WHERE id = :id');
        $delStmt->execute(['id' => $id]);

        echo json_encode(['success' => true]);

    } else {
        throw new Exception('Unbekannte Aktion');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
