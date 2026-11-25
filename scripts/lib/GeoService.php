<?php

declare(strict_types=1);

final class GeoService
{
    private string $userAgent;
    private string $googlePlacesApiKey;
    private ?int $lastRetryAfterSeconds = null;

    public function __construct(string $userAgent, string $googlePlacesApiKey = '')
    {
        $this->userAgent = $userAgent;
        $this->googlePlacesApiKey = $googlePlacesApiKey;
    }

    public function lookupByQuery(string $query): array
    {
        return $this->lookup(trim($query));
    }

    /**
     * Follow a Google Maps short/full URL, extract GPS + English place name.
     * Returns ['lat', 'lng', 'name', 'resolved_city', 'resolved_country'].
     */
    public function resolveGoogleMapsUrl(string $url): array
    {
        // Step 1: follow short URL redirect to get full Google Maps URL
        $fullUrl = $this->followRedirect($url);

        // Step 2: extract Place ID from !1s segment
        $placeId = null;
        if (preg_match('/!1s([^!]+)/', $fullUrl, $m)) {
            $placeId = urldecode($m[1]);
        }

        // Step 3: extract GPS from !3d{lat}!4d{lng}
        $lat = null;
        $lng = null;
        if (preg_match('/!3d(-?\d+\.\d+)/', $fullUrl, $m)) {
            $lat = (float) $m[1];
        }
        if (preg_match('/!4d(-?\d+\.\d+)/', $fullUrl, $m)) {
            $lng = (float) $m[1];
        }
        if ($lat === null && preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $fullUrl, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
        }

        if ($lat === null || $lng === null) {
            throw new RuntimeException(sprintf('Could not extract GPS coordinates from Google Maps URL: %s', $url));
        }

        // Step 4: get English name via Google Places API (if key available and place ID found)
        $name = '';
        if ($this->googlePlacesApiKey !== '') {
            $name = $this->fetchPlaceNameFromApi($placeId ?? '', $lat, $lng);
        }

        // Step 5: reverse geocode for city/country
        $reverseUrl = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat'             => $lat,
            'lon'             => $lng,
            'format'          => 'jsonv2',
            'accept-language' => 'en',
        ]);
        $reverseResult = $this->requestJson($reverseUrl);
        $address = isset($reverseResult['address']) && is_array($reverseResult['address'])
            ? $reverseResult['address']
            : [];

        $resolvedCity    = $this->extractResolvedCity($reverseResult);
        $resolvedCountry = (string) ($address['country'] ?? 'unknown');

        return [
            'lat'              => $lat,
            'lng'              => $lng,
            'name'             => $this->normalizeStoreName($name),
            'place_id'         => $placeId,
            'resolved_city'    => $resolvedCity,
            'resolved_country' => $resolvedCountry,
        ];
    }

    private function fetchPlaceNameFromApi(string $placeId, float $lat, float $lng): string
    {
        $url  = 'https://places.googleapis.com/v1/places:searchText';
        $body = json_encode([
            'textQuery'      => 'Starbucks',
            'maxResultCount' => 1,
            'languageCode'   => 'en',
            'locationBias'   => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => 100,
                ],
            ],
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->googlePlacesApiKey,
                'X-Goog-FieldMask: places.displayName',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            return '';
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return '';
        }

        return (string) ($data['places'][0]['displayName']['text'] ?? '');
    }

    private function normalizeStoreName(string $name): string
    {
        // Google Places API returns "STARBUCKS Huilan Shop" — normalize to title case brand prefix
        if (stripos($name, 'STARBUCKS') === 0 && strlen($name) > 9 && $name[9] === ' ') {
            $name = 'Starbucks' . substr($name, 9);
        }

        return $name;
    }

    private function followRedirect(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl for redirect follow.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . $this->userAgent,
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_NOBODY  => true,  // HEAD only — we only need the final URL
        ]);

        curl_exec($ch);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($finalUrl === '') {
            throw new RuntimeException(sprintf('Could not resolve redirect for URL: %s', $url));
        }

        return $finalUrl;
    }

    private function injectHlEn(string $url): string
    {
        $sep = strpos($url, '?') !== false ? '&' : '?';

        return $url . $sep . 'hl=en';
    }

    public function lookupCity(string $city, string $country = ''): array
    {
        $query = trim($city . ($country !== '' ? ', ' . $country : ''));

        return $this->lookup($query);
    }

    /**
     * Look up the corresponding city in Google Places and return shared place
     * metadata for output/cities/{city_key}/meta.json.
     */
    public function lookupCityPlaceSummary(string $city, string $country, float $lat, float $lng): array
    {
        if ($this->googlePlacesApiKey === '') {
            return [];
        }

        $city = trim($city);
        $country = trim($country);
        if ($city === '') {
            return [];
        }

        $textQuery = $city;
        if ($country !== '' && strcasecmp($city, $country) !== 0) {
            $textQuery .= ', ' . $country;
        }

        $response = $this->postJson(
            'https://places.googleapis.com/v1/places:searchText',
            [
                'textQuery' => $textQuery,
                'maxResultCount' => 1,
                'languageCode' => 'en',
                'locationBias' => [
                    'circle' => [
                        'center' => [
                            'latitude' => $lat,
                            'longitude' => $lng,
                        ],
                        'radius' => 50000,
                    ],
                ],
            ],
            'places.id,places.displayName,places.editorialSummary,places.generativeSummary,places.googleMapsUri'
        );

        $place = isset($response['places'][0]) && is_array($response['places'][0])
            ? $response['places'][0]
            : null;
        if ($place === null) {
            return [];
        }

        $editorialSummary = trim((string) ($place['editorialSummary']['text'] ?? ''));
        $generativeSummary = trim((string) ($place['generativeSummary']['overview']['text'] ?? ''));
        $summary = $generativeSummary !== '' ? $generativeSummary : $editorialSummary;

        return [
            'place_id' => (string) ($place['id'] ?? ''),
            'place_name' => (string) ($place['displayName']['text'] ?? ''),
            'google_maps_uri' => (string) ($place['googleMapsUri'] ?? ''),
            'place_summary' => $summary !== '' ? $summary : null,
            'place_summary_source' => $generativeSummary !== '' ? 'generativeSummary' : ($editorialSummary !== '' ? 'editorialSummary' : null),
            'place_summary_disclosure' => (string) ($place['generativeSummary']['disclosureText']['text'] ?? ''),
            'place_summary_flag_uri' => (string) ($place['generativeSummary']['overviewFlagContentUri'] ?? ''),
        ];
    }

    public function lookupCountry(string $country): array
    {
        $country = trim($country);
        if ($country === '') {
            throw new RuntimeException('Country is required for country geocoding.');
        }

        $result = $this->lookup($country, ['featuretype' => 'country']);
        $addresstype = strtolower((string) ($result['raw']['addresstype'] ?? ''));
        if ($addresstype !== 'country') {
            throw new RuntimeException(sprintf(
                'Geocoding result for %s is not a country (addresstype=%s).',
                $country,
                $addresstype !== '' ? $addresstype : 'unknown'
            ));
        }

        return $result;
    }

    private function lookup(string $query, array $extraParams = []): array
    {
        $params = array_merge([
            'q'               => $query,
            'format'          => 'jsonv2',
            'limit'           => 1,
            'polygon_geojson' => 1,
            'accept-language' => 'en',
        ], $extraParams);
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

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

        $retryAfterSeconds = null;
        $this->lastRetryAfterSeconds = null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfterSeconds): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, strlen('Retry-After:')));
                    if (ctype_digit($value)) {
                        $retryAfterSeconds = (int) $value;
                    } else {
                        $timestamp = strtotime($value);
                        if ($timestamp !== false) {
                            $retryAfterSeconds = max(0, $timestamp - time());
                        }
                    }
                }

                return strlen($header);
            },
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
            $this->lastRetryAfterSeconds = $retryAfterSeconds;
            throw new RuntimeException(sprintf('Geo request failed with HTTP %d for %s', $statusCode, $url));
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Geo service returned invalid JSON for %s', $url));
        }

        return $decoded;
    }

    private function postJson(string $url, array $payload, string $fieldMask): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize Google Places request.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            curl_close($ch);
            throw new RuntimeException('Failed to encode Google Places request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->googlePlacesApiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Google Places request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('Google Places request failed with HTTP %d.', $statusCode));
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Places returned invalid JSON.');
        }

        return $decoded;
    }
}
