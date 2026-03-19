<?php

declare(strict_types=1);

final class GeoService
{
    private string $userAgent;

    public function __construct(string $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    public function lookupCity(string $city, string $country = ''): array
    {
        $query = trim($city . ($country !== '' ? ', ' . $country : ''));

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
            'polygon_geojson' => 1,
        ]);

        $results = $this->requestJson($url);
        if (!is_array($results) || $results === []) {
            throw new RuntimeException(sprintf('No geocoding result for %s', $query));
        }

        $first = $results[0];
        $lat = isset($first['lat']) ? (float) $first['lat'] : null;
        $lng = isset($first['lon']) ? (float) $first['lon'] : null;

        if ($lat === null || $lng === null) {
            throw new RuntimeException(sprintf('Geocoding result missing coordinates for %s', $query));
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'geojson' => $this->normalizeGeoJson($first['geojson'] ?? null),
            'resolved_city' => $this->extractResolvedCity($first),
            'resolved_country' => $this->extractResolvedCountry($first),
            'raw' => $first,
        ];
    }

    private function extractResolvedCity(array $result): string
    {
        $address = isset($result['address']) && is_array($result['address']) ? $result['address'] : [];
        $candidates = [
            'city',
            'town',
            'municipality',
            'county',
            'state_district',
            'state',
            'region',
        ];

        foreach ($candidates as $key) {
            if (!empty($address[$key]) && is_string($address[$key])) {
                return $address[$key];
            }
        }

        if (!empty($result['name']) && is_string($result['name'])) {
            return $result['name'];
        }

        $displayName = isset($result['display_name']) ? (string) $result['display_name'] : '';
        if ($displayName !== '') {
            $parts = explode(',', $displayName);

            return trim((string) $parts[0]);
        }

        return 'unknown';
    }

    private function extractResolvedCountry(array $result): string
    {
        $address = isset($result['address']) && is_array($result['address']) ? $result['address'] : [];

        if (!empty($address['country']) && is_string($address['country'])) {
            return $address['country'];
        }

        $displayName = isset($result['display_name']) ? (string) $result['display_name'] : '';
        if ($displayName !== '') {
            $parts = explode(',', $displayName);

            return trim((string) end($parts));
        }

        return 'unknown';
    }

    private function normalizeGeoJson($geojson): ?array
    {
        if (!is_array($geojson) || !isset($geojson['type'])) {
            return null;
        }

        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => new stdClass(),
                    'geometry' => $geojson,
                ],
            ],
        ];
    }

    private function requestJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl for GeoService.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Geo request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('Geo request failed with HTTP %d for %s', $statusCode, $url));
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Geo service returned invalid JSON for %s', $url));
        }

        return $decoded;
    }
}
