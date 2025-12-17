<?php
// Copy this file to config.php and adjust settings.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'kumiai_asset_manager',
        'user' => 'kumiai',
        'pass' => 'secret',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => '/',
        'session_name' => 'kumiai_session',
        'studio_name' => 'Your Studio',
    ],
    'openai' => [
        // API-Key optional über ENV `OPENAI_API_KEY` setzen
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'base_url' => 'https://api.openai.com/v1',
        'vision_model' => 'gpt-4o-mini',
        'embedding_model' => 'text-embedding-3-small',
        'classification' => [
            'max_retries' => 2,
            'score_threshold' => 0.45,
            'margin' => 0.08,
            'top_k' => 3,
        ],
    ],
];
