<?php

declare(strict_types=1);

final class OpenAIClient
{
    private string $apiKey;
    private string $model;
    private string $apiBase;

    public function __construct(string $apiKey, string $model, string $apiBase)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->apiBase = rtrim($apiBase, '/');
    }

    public function analyzeMugImage(string $imagePath): array
    {
        $imageUrl = $this->buildImageDataUrl($imagePath);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['city', 'country', 'display_name', 'description', 'confidence'],
            'properties' => [
                'city' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'display_name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
        ];

        $payload = [
            'model' => $this->model,
            'instructions' => implode("\n", [
                'You analyze Starbucks city mug photos.',
                'Return JSON only.',
                'Identify the most likely city and country shown on the mug.',
                'If uncertain, still return your best guess and lower confidence.',
                'If the city cannot be determined, return "unknown" for city.',
                'Keep display_name short and natural.',
                'Keep description to one concise sentence in English.',
            ]),
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'Analyze this Starbucks city mug photo and extract the city information as JSON.',
                        ],
                        [
                            'type' => 'input_image',
                            'image_url' => $imageUrl,
                            'detail' => 'high',
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'city_mug_analysis',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $response = $this->request('/responses', $payload);
        $content = $response['output'][0]['content'][0] ?? null;

        if (!is_array($content)) {
            throw new RuntimeException('OpenAI response did not include message content.');
        }

        if (($content['type'] ?? '') === 'refusal') {
            throw new RuntimeException('OpenAI refused to analyze the image.');
        }

        $rawText = $content['text'] ?? null;
        if (!is_string($rawText) || $rawText === '') {
            throw new RuntimeException('OpenAI response did not include text output.');
        }

        $decoded = json_decode($rawText, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON content.');
        }

        return $decoded;
    }

    private function buildImageDataUrl(string $imagePath): string
    {
        $mimeType = mime_content_type($imagePath);
        if ($mimeType === false) {
            $mimeType = 'image/jpeg';
        }

        $imageBytes = file_get_contents($imagePath);
        if ($imageBytes === false) {
            throw new RuntimeException(sprintf('Failed to read image: %s', $imagePath));
        }

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($imageBytes));
    }

    private function request(string $path, array $payload): array
    {
        $ch = curl_init($this->apiBase . $path);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl for OpenAI request.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode OpenAI request payload.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 120,
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OpenAI request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned a non-JSON response.');
        }

        if ($statusCode >= 400) {
            $message = $decoded['error']['message'] ?? 'Unknown OpenAI API error.';
            throw new RuntimeException(sprintf('OpenAI API error (%d): %s', $statusCode, $message));
        }

        return $decoded;
    }
}
