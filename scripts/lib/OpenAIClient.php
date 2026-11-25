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
            'required' => ['type', 'venue', 'city', 'country', 'display_name', 'description', 'confidence'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['city', 'country', 'store', 'region']],
                'venue' => ['type' => 'string'],
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
                'Always return city and country in English, regardless of what language appears on the mug.',
                'If uncertain, still return your best guess and lower confidence.',
                'If the city cannot be determined, return "unknown" for city.',
                'Keep display_name short and natural.',
                'Keep description to one concise sentence in English.',
                '',
                'Set the "type" field based on what the mug represents:',
                '- "city": a standard city mug (most common case)',
                '- "country": the mug represents an entire country, not a specific city; set city to the country name',
                '- "store": a specific Starbucks store or venue edition (e.g. hotel, airport, shopping mall); set venue to the store/venue name in English',
                '- "region": a geographic region or area that is not a specific city',
                'Set "venue" to the store or venue name in English if type is "store", otherwise set it to an empty string.',
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

    /**
     * Decide whether a stored place represents a city, country/region, or POI.
     * The existing mug type is deliberately treated as a hint only because
     * older records may have been classified as store before this decision.
     */
    public function decidePlaceType(
        string $cityKey,
        string $city,
        string $country,
        string $existingType = '',
        string $venue = ''
    ): array {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['type', 'confidence', 'reason'],
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['city', 'country', 'region', 'attraction'],
                ],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
        ];

        $payload = [
            'model' => $this->model,
            'instructions' => implode("\n", [
                'You classify a place for a travel mug map.',
                'Return JSON only.',
                'Use the place name and country as the primary evidence.',
                'Do not trust the existing type or the store- prefix if they conflict with the place name.',
                'Use "city" for a city or municipality.',
                'Use "country" for a sovereign country.',
                'Use "region" for a state, province, territory, island, or geographic region.',
                'Use "attraction" for a named point of interest, university, landmark, venue, or specific place.',
                'For example, Stanford University is an attraction, not a city or store.',
            ]),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => json_encode([
                        'city_key' => $cityKey,
                        'city' => $city,
                        'country' => $country,
                        'existing_type' => $existingType,
                        'venue' => $venue,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'place_type_decision',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $response = $this->request('/responses', $payload);
        $content = $response['output'][0]['content'][0] ?? null;
        if (!is_array($content)) {
            throw new RuntimeException('OpenAI decision response did not include message content.');
        }
        if (($content['type'] ?? '') === 'refusal') {
            throw new RuntimeException('OpenAI refused to classify the place.');
        }

        $rawText = $content['text'] ?? null;
        if (!is_string($rawText) || $rawText === '') {
            throw new RuntimeException('OpenAI decision response did not include text output.');
        }

        $decoded = json_decode($rawText, true);
        if (!is_array($decoded) || !isset($decoded['type'])) {
            throw new RuntimeException('OpenAI returned invalid place decision JSON.');
        }

        return $decoded;
    }

    /**
     * Generate a fallback English description when provider summaries are
     * unavailable. The prompt intentionally asks for broadly reliable facts
     * and avoids invented precise statistics or claims.
     */
    public function generatePlaceSummary(
        string $name,
        string $country,
        string $type
    ): array {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary'],
            'properties' => [
                'summary' => ['type' => 'string'],
            ],
        ];

        $payload = [
            'model' => $this->model,
            'instructions' => implode("\n", [
                'You write factual travel descriptions for a city mug map.',
                'Return JSON only.',
                'Use web search to verify the place identity and gather reliable background information before writing.',
                'Write the description in English.',
                'Write approximately 100 to 150 words in 1 or 2 readable paragraphs.',
                'Explain what the place is known for, its character, history or cultural importance, and what visitors commonly experience there.',
                'Do not invent exact statistics, awards, dates, attractions, or claims you are not reasonably confident about.',
                'Do not include URLs, Markdown links, inline citations, or source labels in the summary; sources are stored separately.',
                'If the identity is ambiguous, write a short cautious description rather than guessing.',
            ]),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => json_encode([
                        'name' => trim($name),
                        'country' => trim($country),
                        'type' => trim($type),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'place_summary',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'tools' => [[
                'type' => 'web_search',
                'search_context_size' => 'low',
            ]],
            'tool_choice' => 'required',
            'include' => ['web_search_call.action.sources'],
        ];

        $response = $this->request('/responses', $payload);
        $content = null;
        $textCandidates = [];
        $annotations = [];
        foreach (($response['output'] ?? []) as $outputItem) {
            if (!is_array($outputItem) || !isset($outputItem['content']) || !is_array($outputItem['content'])) {
                continue;
            }
            foreach ($outputItem['content'] as $outputContent) {
                if (!is_array($outputContent)) {
                    continue;
                }
                if (($outputContent['type'] ?? '') === 'output_text') {
                    if (is_string($outputContent['text'] ?? null) && trim($outputContent['text']) !== '') {
                        $textCandidates[] = trim($outputContent['text']);
                    }
                    $annotations = array_merge($annotations, is_array($outputContent['annotations'] ?? null)
                        ? $outputContent['annotations']
                        : []);
                }
            }
        }
        if ($textCandidates === []) {
            throw new RuntimeException('OpenAI summary response did not include message content.');
        }

        foreach ($textCandidates as $candidate) {
            $candidateDecoded = json_decode($candidate, true);
            if (is_array($candidateDecoded) && trim((string) ($candidateDecoded['summary'] ?? '')) !== '') {
                $content = ['text' => $candidate];
                break;
            }
        }
        if (!is_array($content)) {
            $content = ['text' => $textCandidates[count($textCandidates) - 1]];
        }

        $rawText = $content['text'] ?? null;
        if (!is_string($rawText) || $rawText === '') {
            throw new RuntimeException('OpenAI summary response did not include text output.');
        }

        $rawText = trim($rawText);
        $decoded = json_decode($rawText, true);
        if (!is_array($decoded)) {
            $jsonStart = strpos($rawText, '{');
            $jsonEnd = strrpos($rawText, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $decoded = json_decode(substr($rawText, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            }
        }
        $summary = is_array($decoded) ? trim((string) ($decoded['summary'] ?? '')) : '';
        if ($summary === '' && strpos($rawText, '{') === false) {
            $summary = $rawText;
        }
        if ($summary === '') {
            throw new RuntimeException('OpenAI returned an empty place summary.');
        }

        $sources = [];
        foreach ($annotations as $annotation) {
            if (!is_array($annotation) || ($annotation['type'] ?? '') !== 'url_citation') {
                continue;
            }
            $url = trim((string) ($annotation['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $sources[$url] = [
                'title' => trim((string) ($annotation['title'] ?? '')),
                'url' => $url,
            ];
        }

        foreach (($response['output'] ?? []) as $outputItem) {
            if (!is_array($outputItem) || ($outputItem['type'] ?? '') !== 'web_search_call') {
                continue;
            }
            $items = $outputItem['action']['sources'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $url = trim((string) ($source['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $sources[$url] = [
                    'title' => trim((string) ($source['title'] ?? '')),
                    'url' => $url,
                ];
            }
        }

        return [
            'summary' => $summary,
            'sources' => array_values($sources),
        ];
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
