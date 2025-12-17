<?php

class AiOpenAiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $visionModel;
    private string $embeddingModel;

    public function __construct(array $config)
    {
        $this->apiKey = trim($config['api_key'] ?? '');
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->visionModel = $config['vision_model'] ?? 'gpt-4o-mini';
        $this->embeddingModel = $config['embedding_model'] ?? 'text-embedding-3-small';
    }

    public function analyzeImageWithSchema(string $imagePath, array $schema, string $instruction, int $maxRetries = 2): array
    {
        $this->ensureApiKey();
        $this->assertReadableFile($imagePath);

        $attempts = 0;
        $errors = [];
        $messages = [
            [
                'role' => 'system',
                'content' => 'Du bist eine präzise Vision-Pipeline. Antworte ausschließlich mit JSON, das exakt dem vorgegebenen Schema entspricht. Keine Floskeln, keine zusätzlichen Felder.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imagePath)]],
                ],
            ],
        ];

        while ($attempts <= $maxRetries) {
            $response = $this->postJson('/chat/completions', [
                'model' => $this->visionModel,
                'messages' => $messages,
                'temperature' => 0.2,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'asset_classification',
                        'schema' => $schema,
                        'strict' => true,
                    ],
                ],
            ]);

            $content = $response['choices'][0]['message']['content'] ?? '';
            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                $errors[] = 'Antwort konnte nicht als JSON interpretiert werden.';
            } else {
                $validation = $this->validateAgainstSchema($decoded, $schema);
                if ($validation['valid']) {
                    return $decoded;
                }
                $errors[] = implode('; ', $validation['errors']);
            }

            $messages[] = [
                'role' => 'system',
                'content' => 'Vorherige Antwort verletzte das Schema: ' . end($errors) . '. Liefere die gleiche Struktur erneut und halte alle Typen ein.',
            ];
            $attempts++;
        }

        throw new RuntimeException('Vision-Output konnte nicht validiert werden: ' . implode(' | ', $errors));
    }

    public function embedText(string $text): array
    {
        $this->ensureApiKey();

        $response = $this->postJson('/embeddings', [
            'model' => $this->embeddingModel,
            'input' => $text,
            'encoding_format' => 'float',
        ]);

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector)) {
            throw new RuntimeException('Embedding-Antwort ist ungültig oder leer.');
        }

        return array_map('floatval', $vector);
    }

    private function postJson(string $path, array $payload): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . 'Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $body = curl_exec($ch);
        if ($body === false) {
            $message = curl_error($ch) ?: 'Unbekannter cURL-Fehler';
            curl_close($ch);
            throw new RuntimeException('OpenAI-Request fehlgeschlagen: ' . $message);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI lieferte keine gültige JSON-Antwort.');
        }

        if ($status >= 400) {
            $errorMessage = $decoded['error']['message'] ?? 'Unbekannter Fehler';
            throw new RuntimeException(sprintf('OpenAI API-Fehler (%d): %s', $status, $errorMessage));
        }

        return $decoded;
    }

    private function imageToDataUrl(string $imagePath): string
    {
        $mime = mime_content_type($imagePath) ?: 'image/png';
        $data = base64_encode(file_get_contents($imagePath));

        return 'data:' . $mime . ';base64,' . $data;
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI API-Key fehlt in der Konfiguration.');
        }
    }

    private function assertReadableFile(string $path): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Bilddatei kann nicht gelesen werden: ' . $path);
        }
    }

    private function validateAgainstSchema(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            if (!is_array($data) || array_values($data) === $data) {
                $errors[] = $path . ' muss ein Objekt sein.';
                return ['valid' => false, 'errors' => $errors];
            }

            $required = $schema['required'] ?? [];
            foreach ($required as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = $path . ' fehlt Pflichtfeld "' . $key . '"';
                }
            }

            $additionalAllowed = $schema['additionalProperties'] ?? true;
            $properties = $schema['properties'] ?? [];
            foreach ($data as $key => $value) {
                if (!isset($properties[$key])) {
                    if ($additionalAllowed === false) {
                        $errors[] = $path . ' enthält unerwartetes Feld "' . $key . '"';
                    }
                    continue;
                }
                $childResult = $this->validateAgainstSchema($value, $properties[$key], $path . '.' . $key);
                if (!$childResult['valid']) {
                    $errors = array_merge($errors, $childResult['errors']);
                }
            }
        } elseif ($type === 'array') {
            if (!is_array($data) || array_values($data) !== $data) {
                $errors[] = $path . ' muss ein Array sein.';
            } else {
                $itemSchema = $schema['items'] ?? null;
                if ($itemSchema) {
                    foreach ($data as $idx => $value) {
                        $childResult = $this->validateAgainstSchema($value, $itemSchema, $path . '[' . $idx . ']');
                        if (!$childResult['valid']) {
                            $errors = array_merge($errors, $childResult['errors']);
                        }
                    }
                }
            }
        } elseif ($type === 'string') {
            if (!is_string($data)) {
                $errors[] = $path . ' muss ein String sein.';
            }
        } elseif ($type === 'number' || $type === 'integer') {
            if (!is_numeric($data)) {
                $errors[] = $path . ' muss eine Zahl sein.';
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
