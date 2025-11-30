<?php
// php scripts/scan.php <project_id>
require_once __DIR__ . '/../includes/scanner.php';

$projectId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($projectId <= 0) {
    fwrite(STDERR, "Usage: php scripts/scan.php <project_id>\n");
    exit(1);
}

$result = scan_project($pdo, $projectId);
if (!$result['success']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo $result['message'] . "\n";
