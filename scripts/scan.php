<?php
// php scripts/scan.php <project_id>
require_once __DIR__ . '/../includes/db.php';

$projectId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($projectId <= 0) {
    fwrite(STDERR, "Usage: php scripts/scan.php <project_id>\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch();
if (!$project) {
    fwrite(STDERR, "Project not found\n");
    exit(1);
}
$root = rtrim($project['root_path'], '/');
if (!is_dir($root)) {
    fwrite(STDERR, "Root path not found: {$root}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$now = date('Y-m-d H:i:s');
foreach ($it as $file) {
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
}

echo "Scan completed for project {$project['name']} ({$projectId})\n";
