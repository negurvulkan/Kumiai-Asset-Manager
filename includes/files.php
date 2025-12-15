<?php
// Hilfsfunktionen für Uploads, Metadaten und Thumbnails

require_once __DIR__ . '/naming.php';

function sanitize_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }

    if (empty($segments)) {
        return '';
    }

    return '/' . implode('/', $segments);
}

function collect_file_metadata(string $absolutePath): array
{
    $metadata = [
        'file_hash' => null,
        'mime_type' => null,
        'file_size_bytes' => null,
        'width' => null,
        'height' => null,
    ];

    if (!file_exists($absolutePath)) {
        return $metadata;
    }

    $metadata['file_size_bytes'] = filesize($absolutePath) ?: null;
    $metadata['file_hash'] = hash_file('sha256', $absolutePath) ?: null;
    $metadata['mime_type'] = mime_content_type($absolutePath) ?: null;

    if (function_exists('getimagesize')) {
        $info = @getimagesize($absolutePath);
        if ($info) {
            $metadata['width'] = $info[0] ?? null;
            $metadata['height'] = $info[1] ?? null;
        }
    }

    return $metadata;
}

function thumbnail_target_paths(int $projectId, string $relativePath, ?string $baseDir = null): array
{
    $baseDir = $baseDir ?: __DIR__ . '/../public/thumbnails';
    $hash = md5($projectId . ':' . $relativePath);
    $absolute = rtrim($baseDir, '/') . '/' . $hash . '.jpg';
    $public = '/thumbnails/' . $hash . '.jpg';

    return ['absolute' => $absolute, 'public' => $public];
}

function generate_thumbnail(int $projectId, string $relativePath, string $absoluteSource, int $maxSize = 480): ?string
{
    if (!function_exists('imagecreatetruecolor') || !file_exists($absoluteSource)) {
        return null;
    }

    $info = @getimagesize($absoluteSource);
    if (!$info) {
        return null;
    }

    [$width, $height, $type] = $info;
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    $scale = min($maxSize / max($width, 1), $maxSize / max($height, 1), 1);
    $targetWidth = (int)floor($width * $scale);
    $targetHeight = (int)floor($height * $scale);
    if ($targetWidth < 1 || $targetHeight < 1) {
        return null;
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = @imagecreatefromjpeg($absoluteSource);
            break;
        case IMAGETYPE_PNG:
            $source = @imagecreatefrompng($absoluteSource);
            break;
        case IMAGETYPE_GIF:
            $source = @imagecreatefromgif($absoluteSource);
            break;
        default:
            return null;
    }

    if (!$source) {
        return null;
    }

    $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $paths = thumbnail_target_paths($projectId, $relativePath);
    ensure_directory(dirname($paths['absolute']));
    imagejpeg($thumbnail, $paths['absolute'], 82);

    imagedestroy($source);
    imagedestroy($thumbnail);

    return $paths['public'];
}

function thumbnail_public_if_exists(int $projectId, string $relativePath): ?string
{
    $paths = thumbnail_target_paths($projectId, $relativePath);
    return file_exists($paths['absolute']) ? $paths['public'] : null;
}

function format_file_size(?int $bytes, int $precision = 2): string
{
    if ($bytes === null) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}
