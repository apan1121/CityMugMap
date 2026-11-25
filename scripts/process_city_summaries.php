<?php

declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));

$configFile = APP_DIR . '/config/config.php';

if (!file_exists($configFile)) {
    fwrite(STDERR, "Missing config file: {$configFile}\n");
    exit(1);
}

require_once $configFile;
require_once APP_DIR . '/scripts/lib/JsonService.php';
require_once APP_DIR . '/scripts/lib/GeoService.php';
require_once APP_DIR . '/scripts/lib/OpenAIClient.php';

$options = getopt('', ['city-key:', 'force', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php scripts/process_city_summaries.php\n";
    echo "  php scripts/process_city_summaries.php --city-key=california-united-states\n";
    echo "  php scripts/process_city_summaries.php --force\n";
    exit(0);
}

$outputDir = defined('CITY_MUG_OUTPUT_DIR') ? CITY_MUG_OUTPUT_DIR : APP_DIR . '/output';
$mainJsonPath = $outputDir . '/main.json';
$cityOutputDir = $outputDir . '/cities';
$targetCityKey = isset($options['city-key']) ? trim((string) $options['city-key']) : '';
$forceUpdate = isset($options['force']);
$userAgent = defined('CITY_MUG_USER_AGENT')
    ? CITY_MUG_USER_AGENT
    : 'CityMugMap/1.0 (city summary updater)';

if (realpath(dirname($outputDir)) !== APP_DIR) {
    fwrite(STDERR, "CITY_MUG_OUTPUT_DIR is outside project root.\n");
    fwrite(STDERR, "Check APP_DIR in config/config.php.\n");
    exit(1);
}

if (!is_dir($cityOutputDir)) {
    fwrite(STDERR, "City output directory does not exist: {$cityOutputDir}\n");
    exit(1);
}

$mainItems = JsonService::read($mainJsonPath, []);
if (!is_array($mainItems)) {
    fwrite(STDERR, "output/main.json must contain an array.\n");
    exit(1);
}

$cityTypes = collectCityTypes($mainItems);
$metaPaths = glob($cityOutputDir . '/*/meta.json') ?: [];
sort($metaPaths);

if ($targetCityKey !== '') {
    $targetMetaPath = $cityOutputDir . '/' . $targetCityKey . '/meta.json';
    $metaPaths = file_exists($targetMetaPath) ? [$targetMetaPath] : [];
    if ($metaPaths === []) {
        fwrite(STDERR, sprintf("City meta not found: %s\n", $targetCityKey));
        exit(1);
    }
}

$geoService = new GeoService(
    $userAgent,
    defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : ''
);
$openAI = new OpenAIClient(
    defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '',
    defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini',
    defined('OPENAI_API_BASE') ? OPENAI_API_BASE : 'https://api.openai.com/v1'
);

$updatedCount = 0;
$skippedCount = 0;
$failedCount = 0;

logSummaryMessage(sprintf('Found %d city meta file(s).', count($metaPaths)));
if ($targetCityKey !== '') {
    logSummaryMessage(sprintf('Target city mode enabled: %s', $targetCityKey));
}
if ($forceUpdate) {
    logSummaryMessage('Force mode enabled. Existing summaries will be refreshed.');
}

foreach ($metaPaths as $metaPath) {
    $cityKey = basename(dirname($metaPath));
    $meta = JsonService::read($metaPath, []);

    if (!is_array($meta)) {
        logSummaryMessage(sprintf('Skip invalid meta: %s', $metaPath));
        $failedCount++;
        continue;
    }

    $knownTypes = $cityTypes[$cityKey] ?? [];
    $knownType = firstNonStoreType($knownTypes);

    $city = trim((string) ($meta['city'] ?? ''));
    $country = trim((string) ($meta['country'] ?? ''));
    if ($city === '' || $country === '' || strtolower($city) === 'unknown' || strtolower($country) === 'unknown') {
        logSummaryMessage(sprintf('Skip incomplete location: %s', $cityKey));
        $skippedCount++;
        continue;
    }

    $changed = false;

    if (array_key_exists('tripadvisor', $meta)) {
        unset($meta['tripadvisor']);
        $changed = true;
    }

    $existingType = strtolower(trim((string) ($meta['type'] ?? $knownType)));
    $resolvedType = strtolower(trim((string) ($meta['resolved_type'] ?? '')));
    $shouldDecideType = $forceUpdate
        || $resolvedType === ''
        || $existingType === 'store'
        || strpos($cityKey, 'store-') === 0;

    if ($shouldDecideType && defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        try {
            $decision = $openAI->decidePlaceType(
                $cityKey,
                $city,
                $country,
                $existingType,
                (string) ($meta['venue'] ?? '')
            );
            $resolvedType = strtolower((string) ($decision['type'] ?? ''));
            if (!in_array($resolvedType, ['city', 'country', 'region', 'attraction'], true)) {
                throw new RuntimeException('OpenAI returned an unsupported place type.');
            }

            $meta['resolved_type'] = $resolvedType;
            $meta['decision_source'] = 'openai';
            $meta['decision_confidence'] = (float) ($decision['confidence'] ?? 0.0);
            $meta['decision_reason'] = (string) ($decision['reason'] ?? '');
            $changed = true;
            logSummaryMessage(sprintf(
                'GPT decision: city="%s", country="%s", type="%s", confidence=%.2f',
                $city,
                $country,
                $resolvedType,
                $meta['decision_confidence']
            ));
        } catch (Throwable $error) {
            logSummaryMessage('GPT decision failed: ' . $error->getMessage());
        }
    }

    if ($resolvedType === '') {
        $resolvedType = in_array($existingType, ['city', 'country', 'region', 'attraction'], true)
            ? $existingType
            : 'city';
        logSummaryMessage(sprintf('GPT decision fallback: type="%s"', $resolvedType));
    }
    if (array_key_exists('wikipedia', $meta)) {
        unset($meta['wikipedia']);
        $changed = true;
    }
    if (($meta['summary_source'] ?? '') === 'wikipedia') {
        unset($meta['summary'], $meta['summary_source']);
        $changed = true;
    }
    if (($meta['summary_source'] ?? '') === 'tripadvisor_geo') {
        unset($meta['summary'], $meta['summary_source']);
        $changed = true;
    }
    if (($meta['place_summary_source'] ?? '') === 'wikipedia') {
        unset($meta['place_summary'], $meta['place_summary_source']);
        $changed = true;
    }
    if (($meta['place_summary_source'] ?? '') === 'tripadvisor_geo') {
        unset($meta['place_summary'], $meta['place_summary_source']);
        $changed = true;
    }

    $currentSummary = trim((string) ($meta['summary'] ?? $meta['place_summary'] ?? ''));
    if (!$forceUpdate && $currentSummary !== '' && !$changed) {
        logSummaryMessage(sprintf('Skip existing summary: %s', $cityKey));
        $skippedCount++;
        continue;
    }

    logSummaryMessage(sprintf(
        'Updating %s: city="%s", country="%s", type="%s"',
        $cityKey,
        $city,
        $country,
        $resolvedType
    ));

    $placeSummary = [];
    $lat = isset($meta['lat']) ? (float) $meta['lat'] : 0.0;
    $lng = isset($meta['lng']) ? (float) $meta['lng'] : 0.0;

    if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '' && $lat !== 0.0 && $lng !== 0.0) {
        try {
            $placeSummary = $geoService->lookupCityPlaceSummary($city, $country, $lat, $lng);
            if ($placeSummary !== []) {
                foreach ($placeSummary as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $meta[$key] = $value;
                        $changed = true;
                    }
                }
                logSummaryMessage(sprintf(
                    'Google Places result: place="%s", summary=%s',
                    $placeSummary['place_name'] ?? 'unknown',
                    !empty($placeSummary['place_summary']) ? 'yes' : 'no'
                ));
            } else {
                logSummaryMessage('Google Places returned no place result.');
            }
        } catch (Throwable $error) {
            logSummaryMessage('Google Places failed: ' . $error->getMessage());
        }
    } else {
        logSummaryMessage('Google Places skipped: API key or GPS is unavailable.');
    }

    $googleSummary = trim((string) ($placeSummary['place_summary'] ?? ''));
    if ($googleSummary !== '') {
        $meta['summary'] = $googleSummary;
        $meta['summary_source'] = 'google_places';
        $meta['place_summary'] = $googleSummary;
        $meta['place_summary_source'] = $placeSummary['place_summary_source'] ?? 'google_places';
        $changed = true;
    }

    $summaryAfterProviders = trim((string) ($meta['summary'] ?? $meta['place_summary'] ?? ''));
    if ($summaryAfterProviders === '' && defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        try {
            $summaryName = trim((string) (
                $meta['place_name']
                ?? $meta['venue']
                ?? $city
            ));
            $generated = $openAI->generatePlaceSummary($summaryName, $country, $resolvedType);
            $generatedSummary = trim((string) ($generated['summary'] ?? ''));
            if ($generatedSummary !== '') {
                $meta['summary'] = $generatedSummary;
                $meta['summary_source'] = 'openai_web_search';
                $meta['summary_model'] = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
                $meta['summary_sources'] = is_array($generated['sources'] ?? null)
                    ? $generated['sources']
                    : [];
                $meta['summary_generated_at'] = gmdate('c');
                $changed = true;
                logSummaryMessage(sprintf(
                    'OpenAI web summary: generated, sources=%d.',
                    count($meta['summary_sources'])
                ));
            }
        } catch (Throwable $error) {
            logSummaryMessage('OpenAI web summary failed: ' . $error->getMessage());
        }
    }

    if (($meta['type'] ?? '') !== $resolvedType) {
        $meta['type'] = $resolvedType;
        $changed = true;
    }

    if ($changed) {
        $meta['summary_updated_at'] = gmdate('c');
        JsonService::write($metaPath, $meta);
        logSummaryMessage(sprintf('Wrote %s', $metaPath));
        $updatedCount++;
    } else {
        logSummaryMessage(sprintf('No summary data available: %s', $cityKey));
        $skippedCount++;
    }
}

logSummaryMessage(sprintf(
    'Done. updated=%d skipped=%d failed=%d',
    $updatedCount,
    $skippedCount,
    $failedCount
));

if ($failedCount > 0) {
    exit(1);
}

function collectCityTypes(array $mainItems): array
{
    $cityTypes = [];

    foreach ($mainItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $cityKey = trim((string) ($item['city_key'] ?? ''));
        if ($cityKey === '') {
            continue;
        }

        $cityTypes[$cityKey][] = strtolower(trim((string) ($item['type'] ?? 'city')));
    }

    foreach ($cityTypes as $cityKey => $types) {
        $cityTypes[$cityKey] = array_values(array_unique($types));
    }

    return $cityTypes;
}

function firstNonStoreType(array $types): string
{
    foreach ($types as $type) {
        if ($type !== '' && $type !== 'store') {
            return $type;
        }
    }

    return '';
}

function logSummaryMessage(string $message): void
{
    echo '[CitySummary] ' . $message . PHP_EOL;
}

