#!/usr/bin/env php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services/ai_prepass.php';

$options = getopt('', ['asset:', 'revision::']);
$assetId = (int)($options['asset'] ?? 0);
$revisionId = isset($options['revision']) ? (int)$options['revision'] : null;

if ($assetId <= 0) {
    fwrite(STDERR, "Usage: php bin/ai-prepass-subject.php --asset=<asset_id> [--revision=<revision_id>]\n");
    exit(1);
}

$service = new AiPrepassService($pdo, $config);
$result = $service->runSubjectFirst($assetId, $revisionId);

if (!($result['success'] ?? false)) {
    fwrite(STDERR, "Prepass fehlgeschlagen: " . ($result['error'] ?? 'Unbekannter Fehler') . PHP_EOL);
    exit(1);
}

$features = $result['features'] ?? [];
$priors = $result['priors'] ?? [];

echo sprintf(
    "Prepass gespeichert für Asset #%d (Revision #%d) [%s]\n",
    (int)$result['asset_id'],
    (int)$result['revision_id'],
    $result['stage'] ?? 'SUBJECT_FIRST'
);
echo 'Primary Subject: ' . ($features['primary_subject'] ?? 'unknown') . PHP_EOL;
echo 'Background: ' . ($features['background_type'] ?? 'unknown') . PHP_EOL;
echo 'Image-Kind: ' . ($features['image_kind'] ?? 'unknown') . PHP_EOL;
echo 'Subjects: ' . implode(', ', $features['subjects_present'] ?? []) . PHP_EOL;
echo 'Priors: ' . json_encode($priors, JSON_UNESCAPED_UNICODE) . PHP_EOL;
