<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/files.php';

function scan_project(PDO $pdo, int $projectId): array
{
    $result = [
        'success' => false,
        'message' => 'Unbekannter Fehler.',
        'files_scanned' => 0,
    ];

    if ($projectId <= 0) {
        $result['message'] = 'Ungültige Projekt-ID.';
        return $result;
    }

    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        $result['message'] = 'Projekt nicht gefunden.';
        return $result;
    }

    $root = rtrim($project['root_path'] ?? '', '/');
    if ($root === '' || !is_dir($root)) {
        $result['message'] = 'Root-Pfad des Projekts ist ungültig oder nicht erreichbar.';
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $now = date('Y-m-d H:i:s');
    $processed = 0;

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root));
        $hash = hash_file('sha256', $file->getPathname());
        $size = $file->getSize();
        $mime = mime_content_type($file->getPathname()) ?: 'application/octet-stream';

        $stmt = $pdo->prepare('INSERT INTO file_inventory (project_id, file_path, file_hash, file_size_bytes, mime_type, status, last_seen_at) VALUES (:project_id, :file_path, :file_hash, :file_size_bytes, :mime_type, "untracked", :last_seen_at) ON DUPLICATE KEY UPDATE file_hash = VALUES(file_hash), file_size_bytes = VALUES(file_size_bytes), mime_type = VALUES(mime_type), last_seen_at = VALUES(last_seen_at)');
        $stmt->execute([
            'project_id' => $projectId,
            'file_path' => $relative,
            'file_hash' => $hash,
            'file_size_bytes' => $size,
            'mime_type' => $mime,
            'last_seen_at' => $now,
        ]);
        $processed++;
    }

    $result['success'] = true;
    $result['files_scanned'] = $processed;
    $result['message'] = sprintf('Scan abgeschlossen für %s (%d) – %d Dateien geprüft.', $project['name'], $projectId, $processed);

    return $result;
}
