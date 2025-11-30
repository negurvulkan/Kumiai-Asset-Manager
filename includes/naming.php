<?php
// Hilfsfunktionen für Naming-Templates und Folder-Logik

function kumiai_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'item';
    }
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
        $value = $transliterated;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    return $value !== '' ? $value : 'item';
}

function default_naming_rules(): array
{
    return [
        'character_ref' => [
            'folder' => '/01_CHARACTER/{entity_slug}/reference',
            'template' => '{asset_slug}_{view}_v{version}.{ext}',
        ],
        'background' => [
            'folder' => '/03_BACKGROUNDS/{entity_slug}',
            'template' => '{asset_slug}_v{version}.{ext}',
        ],
        'scene_frame' => [
            'folder' => '/02_SCENES/{entity_slug}',
            'template' => '{asset_slug}_{view}_v{version}.{ext}',
        ],
        'concept' => [
            'folder' => '/04_CONCEPT_ART',
            'template' => '{project_slug}_{asset_slug}_{view}_v{version}.{ext}',
        ],
        'other' => [
            'folder' => '/99_TEMP',
            'template' => '{project_slug}_{asset_slug}_v{version}.{ext}',
        ],
    ];
}

function naming_rule_for_type(string $assetType, array $rules = []): array
{
    $rules = $rules ?: default_naming_rules();
    return $rules[$assetType] ?? $rules['other'];
}

function naming_placeholder_context(array $project, array $asset, ?array $entity, int $version, string $extension, string $view, array $classification = []): array
{
    $entitySlug = $entity['slug'] ?? ($entity['name'] ?? null);
    $entitySlug = $entitySlug ? kumiai_slug($entitySlug) : 'unassigned';
    $assetSlug = kumiai_slug($asset['name'] ?? 'asset');
    $projectSlug = $project['slug'] ?? kumiai_slug($project['name'] ?? 'project');
    $classification = array_change_key_case($classification, CASE_LOWER);

    return [
        'project' => $project['name'] ?? 'Project',
        'project_slug' => $projectSlug,
        'entity_slug' => $entitySlug,
        'entity_type' => $entity['type'] ?? ($entity['type_name'] ?? ''),
        'entity_name' => $entity['name'] ?? '',
        'asset_type' => $asset['asset_type'] ?? 'other',
        'asset_name' => $asset['name'] ?? '',
        'asset_slug' => $assetSlug,
        'view' => kumiai_slug($classification['view'] ?? $view ?: 'main'),
        'pose' => kumiai_slug($classification['pose'] ?? ''),
        'outfit' => kumiai_slug($classification['outfit'] ?? ''),
        'character_slug' => $entity && ($entity['type'] ?? '') === 'character' ? $entitySlug : $entitySlug,
        'version' => str_pad((string)$version, 2, '0', STR_PAD_LEFT),
        'date' => date('Ymd'),
        'datetime' => date('Ymd_His'),
        'ext' => ltrim($extension, '.'),
    ];
}

function render_naming_pattern(string $pattern, array $context): string
{
    return preg_replace_callback('/\{([a-z_]+)\}/i', function ($matches) use ($context) {
        $key = $matches[1];
        return $context[$key] ?? $matches[0];
    }, $pattern);
}

function generate_revision_path(array $project, array $asset, ?array $entity, int $version, string $extension, string $view = 'main', array $rules = [], array $classification = []): array
{
    $rule = naming_rule_for_type($asset['asset_type'] ?? 'other', $rules);
    $context = naming_placeholder_context($project, $asset, $entity, $version, $extension, $view, $classification);

    $folder = render_naming_pattern($rule['folder'], $context);
    $fileName = render_naming_pattern($rule['template'], $context);
    $folder = '/' . ltrim($folder, '/');
    $relativePath = trim($folder, '/') !== '' ? $folder . '/' . $fileName : '/' . $fileName;

    return [
        'folder' => $folder,
        'file_name' => $fileName,
        'relative_path' => $relativePath,
        'rule' => $rule,
        'context' => $context,
    ];
}

function extension_from_path(string $path): string
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    return $ext !== '' ? strtolower($ext) : 'dat';
}

function ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function ensure_unique_path(string $root, string $relativePath): string
{
    $cleanRoot = rtrim($root, '/');
    $candidate = $relativePath;
    $iteration = 1;
    $fullPath = $cleanRoot . $candidate;
    $info = pathinfo($candidate);
    $dir = $info['dirname'] ?? '';
    $dir = $dir === '.' ? '' : $dir;
    $basename = $info['filename'] ?? 'file';
    $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

    while (file_exists($fullPath)) {
        $suffix = '-' . $iteration;
        $candidate = ($dir ? $dir . '/' : '/') . $basename . $suffix . $ext;
        if (str_starts_with($candidate, '//')) {
            $candidate = substr($candidate, 1);
        }
        $fullPath = $cleanRoot . $candidate;
        $iteration++;
    }

    return str_starts_with($candidate, '/') ? $candidate : '/' . $candidate;
}
