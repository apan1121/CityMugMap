<?php

declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));

$configFile = APP_DIR . '/config/config.php';

if (!file_exists($configFile)) {
    fwrite(STDERR, "Missing config file: {$configFile}\n");
    fwrite(STDERR, "Copy config/config_sample.php to config/config.php and set OPENAI_API_KEY.\n");
    exit(1);
}

require_once $configFile;

if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
    fwrite(STDERR, "OPENAI_API_KEY is not configured in config/config.php.\n");
    exit(1);
}

require_once APP_DIR . '/scripts/lib/JsonService.php';
require_once APP_DIR . '/scripts/lib/FileService.php';
require_once APP_DIR . '/scripts/lib/OpenAIClient.php';
require_once APP_DIR . '/scripts/lib/GeoService.php';

$options = getopt('', ['file:', 'force', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php scripts/process_mugs.php\n";
    echo "  php scripts/process_mugs.php --file=IMG_7095.jpg\n";
    echo "  php scripts/process_mugs.php --file=IMG_7095.jpg --force\n";
    exit(0);
}

$targetFile = isset($options['file']) ? trim((string) $options['file']) : null;
$forceReprocess = isset($options['force']);

$inputDir = defined('CITY_MUG_INPUT_DIR') ? CITY_MUG_INPUT_DIR : APP_DIR . '/input';
$outputDir = defined('CITY_MUG_OUTPUT_DIR') ? CITY_MUG_OUTPUT_DIR : APP_DIR . '/output';

if (realpath(dirname($inputDir)) !== APP_DIR || realpath(dirname($outputDir)) !== APP_DIR) {
    fwrite(STDERR, "CITY_MUG_INPUT_DIR or CITY_MUG_OUTPUT_DIR is outside project root.\n");
    fwrite(STDERR, "Check APP_DIR in config/config.php.\n");
    exit(1);
}
$mainJsonPath = $outputDir . '/main.json';
$processedPath = $outputDir . '/processed_files.json';
$overridePath = $outputDir . '/manual_overrides.json';
$cityOutputDir = $outputDir . '/cities';
$mugOutputDir = $outputDir . '/mugs';
$userAgent = defined('CITY_MUG_USER_AGENT')
    ? CITY_MUG_USER_AGENT
    : 'CityMugMap/1.0 (local build script)';

FileService::ensureDirectory($inputDir);
FileService::ensureDirectory($outputDir);
FileService::ensureDirectory($cityOutputDir);
FileService::ensureDirectory($mugOutputDir);

$mainItems = JsonService::read($mainJsonPath, []);
$processedFiles = JsonService::read($processedPath, []);
$overrides = JsonService::read($overridePath, []);

if (!is_array($mainItems)) {
    throw new RuntimeException('output/main.json must contain an array.');
}

if (!is_array($processedFiles)) {
    $processedFiles = [];
}

if (!is_array($overrides)) {
    $overrides = [];
}

$mainItems = migrateLegacyItemsToSharedCityData($mainItems, $outputDir);
$mainItems = migrateLegacyItemsToMugFolder($mainItems, $outputDir, $mugOutputDir);

$openAI = new OpenAIClient(
    OPENAI_API_KEY,
    defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini',
    defined('OPENAI_API_BASE') ? OPENAI_API_BASE : 'https://api.openai.com/v1'
);
$geoService = new GeoService($userAgent);

$files = FileService::listImageFiles($inputDir);
$files = filterFiles($files, $targetFile);

if ($targetFile !== null && $files === []) {
    fwrite(STDERR, sprintf("Input file not found: %s\n", $targetFile));
    exit(1);
}

$startAt = microtime(true);
$logPrefix = '[CityMug]';

logMessage($logPrefix, sprintf('Input dir: %s', $inputDir));
logMessage($logPrefix, sprintf('Output dir: %s', $outputDir));
logMessage($logPrefix, sprintf('Found %d image file(s) to inspect.', count($files)));
if ($targetFile !== null) {
    logMessage($logPrefix, sprintf('Target file mode enabled: %s', $targetFile));
}
if ($forceReprocess) {
    logMessage($logPrefix, 'Force mode enabled. Existing output for matched files will be removed first.');
}

$processedCount = 0;
$skippedCount = 0;
$failedCount = 0;

foreach ($files as $sourcePath) {
    $sourceFileName = basename($sourcePath);
    $fileStartedAt = microtime(true);

    logMessage($logPrefix, str_repeat('-', 60));
    logMessage($logPrefix, sprintf('Start processing: %s', $sourceFileName));
    logMessage($logPrefix, sprintf('Source path: %s', $sourcePath));

    if ($forceReprocess) {
        logMessage($logPrefix, sprintf('Step 1/7 Remove old output for %s if it exists.', $sourceFileName));
        list($mainItems, $processedFiles) = purgeExistingItem($sourceFileName, $outputDir, $mainItems, $processedFiles);
    }

    if (isset($processedFiles[$sourceFileName])) {
        logMessage($logPrefix, sprintf('Skip: %s already processed.', $sourceFileName));
        $skippedCount++;
        continue;
    }

    try {
        logMessage($logPrefix, 'Step 2/7 Analyze image with OpenAI.');
        $analysis = resolveAnalysis($sourceFileName, $sourcePath, $overrides, $openAI);
        if (!empty($analysis['_save_override'])) {
            $overrides[$sourceFileName] = [
                'city' => $analysis['city'],
                'country' => $analysis['country'],
                'display_name' => $analysis['display_name'],
                'description' => $analysis['description'],
                'confidence' => $analysis['confidence'],
                'taken_at' => $analysis['taken_at'],
            ];
            JsonService::write($overridePath, $overrides);
            logMessage($logPrefix, sprintf('Saved manual override: %s', $overridePath));
        }
        logMessage($logPrefix, sprintf(
            'OpenAI result: city="%s", country="%s", display_name="%s", confidence=%.2f',
            $analysis['city'],
            $analysis['country'],
            $analysis['display_name'],
            (float) $analysis['confidence']
        ));

        logMessage($logPrefix, 'Step 3/7 Query geolocation and boundary from OpenStreetMap.');
        $geoCountry = normalizeLookupCountry((string) $analysis['country']);
        logMessage($logPrefix, sprintf(
            'Geocode query: city="%s", country="%s"',
            $analysis['city'],
            $geoCountry !== '' ? $geoCountry : '(empty)'
        ));
        $geo = $geoService->lookupCity($analysis['city'], $geoCountry);
        if (!empty($geo['resolved_city']) && strtolower($analysis['city']) === 'unknown') {
            $analysis['city'] = sanitizeTextValue((string) $geo['resolved_city']);
        }
        if (
            !empty($geo['resolved_country'])
            && (
                trim((string) $analysis['country']) === ''
                || strtolower((string) $analysis['country']) === 'unknown'
            )
        ) {
            $analysis['country'] = sanitizeTextValue((string) $geo['resolved_country']);
        }
        if (!empty($analysis['_manual_city_input'])) {
            $resolvedCity = normalizePlaceName((string) ($geo['resolved_city'] ?? 'unknown'));
            $resolvedCountry = normalizePlaceName((string) ($geo['resolved_country'] ?? 'unknown'));

            if ($resolvedCity !== 'unknown') {
                if (strcasecmp($analysis['city'], $resolvedCity) !== 0) {
                    logMessage($logPrefix, sprintf(
                        'Manual city corrected by geocoder: "%s" -> "%s"',
                        $analysis['city'],
                        $resolvedCity
                    ));
                }
                $analysis['city'] = $resolvedCity;
            }

            if ($resolvedCountry !== 'unknown') {
                $analysis['country'] = $resolvedCountry;
            }

            if (
                strpos((string) $analysis['display_name'], 'Starbucks ') === 0
                && substr((string) $analysis['display_name'], -4) === ' Mug'
            ) {
                $analysis['display_name'] = 'Starbucks ' . $analysis['city'] . ' Mug';
            }
        }
        logMessage($logPrefix, sprintf(
            'Geo result: lat=%s, lng=%s, boundary=%s, resolved_city="%s", resolved_country="%s"',
            formatFloat($geo['lat']),
            formatFloat($geo['lng']),
            $geo['geojson'] !== null ? 'yes' : 'no',
            (string) ($geo['resolved_city'] ?? 'unknown'),
            (string) ($geo['resolved_country'] ?? 'unknown')
        ));

        logMessage($logPrefix, 'Step 4/7 Build item id and destination folder.');
        $cityKey = buildCityKey($analysis['city'], $analysis['country']);
        $itemId = buildItemId($cityKey, $mainItems);
        $itemDir = $mugOutputDir . '/' . $itemId;
        $cityDir = $cityOutputDir . '/' . $cityKey;
        logMessage($logPrefix, sprintf('city_key=%s, id=%s', $cityKey, $itemId));

        logMessage($logPrefix, 'Step 5/7 Render mug.jpg without auto-cropping.');
        FileService::ensureDirectory($itemDir);
        FileService::ensureDirectory($cityDir);
        $imageFileName = FileService::copyImage($sourcePath, $itemDir);
        logMessage($logPrefix, sprintf('Rendered image: mugs/%s/%s', $itemId, $imageFileName));

        $cityMeta = [
            'city_key' => $cityKey,
            'city' => $analysis['city'],
            'country' => $analysis['country'],
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'updated_at' => gmdate('c'),
        ];

        JsonService::write($cityDir . '/meta.json', $cityMeta);
        logMessage($logPrefix, sprintf('Wrote city meta: cities/%s/meta.json', $cityKey));

        $meta = [
            'id' => $itemId,
            'city_key' => $cityKey,
            'city' => $analysis['city'],
            'country' => $analysis['country'],
            'display_name' => $analysis['display_name'],
            'description' => $analysis['description'],
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'source_image' => $sourceFileName,
            'confidence' => (float) $analysis['confidence'],
            'taken_at' => $analysis['taken_at'] ?? null,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        JsonService::write($itemDir . '/meta.json', $meta);
        logMessage($logPrefix, sprintf('Wrote meta: %s/meta.json', $itemId));

        if ($geo['geojson'] !== null) {
            JsonService::write($cityDir . '/boundary.geojson', $geo['geojson']);
            logMessage($logPrefix, sprintf('Wrote shared boundary: cities/%s/boundary.geojson', $cityKey));
        } else {
            logMessage($logPrefix, 'Boundary not available. Continue with marker-only data.');
        }

        logMessage($logPrefix, 'Step 6/7 Update main index and processed file registry.');
        $mainItems[] = [
            'id' => $itemId,
            'city_key' => $cityKey,
            'city' => $analysis['city'],
            'country' => $analysis['country'],
            'display_name' => $analysis['display_name'],
            'description' => $analysis['description'],
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'source_image' => FileService::relativePath($sourcePath),
            'created_at' => $meta['created_at'],
        ];

        $processedFiles[$sourceFileName] = [
            'id' => $itemId,
            'city_key' => $cityKey,
            'processed_at' => gmdate('c'),
        ];

        logMessage($logPrefix, sprintf('Indexed source file: %s -> %s', $sourceFileName, $itemId));
        $processedCount++;
        logMessage($logPrefix, sprintf(
            'Finished %s in %.2fs',
            $sourceFileName,
            microtime(true) - $fileStartedAt
        ));
    } catch (Throwable $e) {
        $failedCount++;
        fwrite(STDERR, sprintf("%s Failed to process %s: %s\n", $logPrefix, $sourceFileName, $e->getMessage()));
    }
}

logMessage($logPrefix, 'Step 7/7 Write output/main.json and output/processed_files.json.');
usort($mainItems, static function (array $a, array $b): int {
    return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
});

JsonService::write($mainJsonPath, $mainItems);
JsonService::write($processedPath, $processedFiles);

logMessage($logPrefix, sprintf('main.json items: %d', count($mainItems)));
logMessage($logPrefix, sprintf(
    'Done in %.2fs. processed=%d skipped=%d failed=%d',
    microtime(true) - $startAt,
    $processedCount,
    $skippedCount,
    $failedCount
));

function filterFiles(array $files, ?string $targetFile): array
{
    if ($targetFile === null || $targetFile === '') {
        return $files;
    }

    $filtered = [];
    foreach ($files as $path) {
        if (basename($path) === $targetFile) {
            $filtered[] = $path;
        }
    }

    return $filtered;
}

function migrateLegacyItemsToSharedCityData(array $mainItems, string $outputDir): array
{
    $updatedItems = [];

    foreach ($mainItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $cityKey = isset($item['city_key']) ? (string) $item['city_key'] : '';
        if ($cityKey === '') {
            $updatedItems[] = $item;
            continue;
        }

        $cityDir = $outputDir . '/cities/' . $cityKey;
        FileService::ensureDirectory($cityDir);

        $sharedBoundaryPath = $cityDir . '/boundary.geojson';
        $legacyBoundaryPath = '';
        if (!empty($item['boundary']) && is_string($item['boundary'])) {
            $legacyBoundaryPath = APP_DIR . '/' . ltrim($item['boundary'], '/');
        }

        if (!file_exists($sharedBoundaryPath) && $legacyBoundaryPath !== '' && file_exists($legacyBoundaryPath)) {
            copy($legacyBoundaryPath, $sharedBoundaryPath);
        }

        $cityMeta = [
            'city_key' => $cityKey,
            'city' => (string) ($item['city'] ?? 'unknown'),
            'country' => (string) ($item['country'] ?? 'unknown'),
            'lat' => isset($item['lat']) ? (float) $item['lat'] : 0.0,
            'lng' => isset($item['lng']) ? (float) $item['lng'] : 0.0,
            'updated_at' => (string) ($item['created_at'] ?? gmdate('c')),
        ];
        JsonService::write($cityDir . '/meta.json', $cityMeta);

        $itemMetaPath = '';
        $itemId = isset($item['id']) ? (string) $item['id'] : '';
        if ($itemId !== '') {
            $itemMetaPath = $outputDir . '/mugs/' . $itemId . '/meta.json';
            if (!file_exists($itemMetaPath)) {
                $itemMetaPath = $outputDir . '/' . $itemId . '/meta.json';
            }
        }

        if ($itemMetaPath !== '' && file_exists($itemMetaPath)) {
            $itemMeta = JsonService::read($itemMetaPath, []);
            if (is_array($itemMeta)) {
                unset($itemMeta['boundary_file'], $itemMeta['image'], $itemMeta['city_meta'], $itemMeta['boundary']);
                JsonService::write($itemMetaPath, $itemMeta);
            }
        }

        if (
            $legacyBoundaryPath !== ''
            && file_exists($legacyBoundaryPath)
            && realpath($legacyBoundaryPath) !== realpath($sharedBoundaryPath)
        ) {
            @unlink($legacyBoundaryPath);
        }

        $updatedItems[] = $item;
    }

    return $updatedItems;
}

function migrateLegacyItemsToMugFolder(array $mainItems, string $outputDir, string $mugOutputDir): array
{
    $updatedItems = [];

    foreach ($mainItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = isset($item['id']) ? (string) $item['id'] : '';
        if ($itemId === '') {
            $updatedItems[] = $item;
            continue;
        }

        $legacyItemDir = $outputDir . '/' . $itemId;
        $newItemDir = $mugOutputDir . '/' . $itemId;

        if (is_dir($legacyItemDir) && !is_dir($newItemDir)) {
            rename($legacyItemDir, $newItemDir);
        }

        unset($item['image'], $item['meta'], $item['city_meta'], $item['boundary']);

        $itemMetaPath = $newItemDir . '/meta.json';
        if (file_exists($itemMetaPath)) {
            $itemMeta = JsonService::read($itemMetaPath, []);
            if (is_array($itemMeta)) {
                unset($itemMeta['image'], $itemMeta['city_meta'], $itemMeta['boundary']);
                JsonService::write($itemMetaPath, $itemMeta);
            }
        }

        $updatedItems[] = $item;
    }

    return $updatedItems;
}

function logMessage(string $prefix, string $message): void
{
    echo sprintf("%s %s\n", $prefix, $message);
}

function resolveAnalysis(string $sourceFileName, string $sourcePath, array $overrides, OpenAIClient $openAI): array
{
    if (isset($overrides[$sourceFileName]) && is_array($overrides[$sourceFileName])) {
        $override = $overrides[$sourceFileName];
        logMessage('[CityMug]', sprintf('Manual override found for %s. Skip OpenAI analysis.', $sourceFileName));

        return [
            'city' => normalizePlaceName((string) ($override['city'] ?? 'unknown')),
            'country' => normalizePlaceName((string) ($override['country'] ?? 'unknown')),
            'display_name' => (string) ($override['display_name'] ?? 'Starbucks City Mug'),
            'description' => (string) ($override['description'] ?? ''),
            'confidence' => (float) ($override['confidence'] ?? 1.0),
            'taken_at' => $override['taken_at'] ?? null,
        ];
    }

    $analysis = $openAI->analyzeMugImage($sourcePath);
    logMessage('[CityMug]', sprintf(
        'OpenAI raw analysis for %s: city="%s", country="%s", display_name="%s", confidence=%s',
        $sourceFileName,
        normalizePlaceName((string) ($analysis['city'] ?? 'unknown')),
        normalizePlaceName((string) ($analysis['country'] ?? 'unknown')),
        sanitizeTextValue($analysis['display_name'] ?? 'Starbucks City Mug'),
        isset($analysis['confidence']) ? (string) $analysis['confidence'] : '0'
    ));

    $normalized = [
        'city' => normalizePlaceName((string) ($analysis['city'] ?? 'unknown')),
        'country' => normalizePlaceName((string) ($analysis['country'] ?? 'unknown')),
        'display_name' => sanitizeTextValue($analysis['display_name'] ?? 'Starbucks City Mug'),
        'description' => sanitizeTextValue($analysis['description'] ?? ''),
        'confidence' => max(0.0, min(1.0, (float) ($analysis['confidence'] ?? 0))),
        'taken_at' => $analysis['taken_at'] ?? null,
    ];

    if (shouldPromptForManualCity($normalized)) {
        logMessage('[CityMug]', sprintf('Unable to recognize a reliable city for %s. Manual input required.', $sourceFileName));
        logMessage('[CityMug]', sprintf('Image path: %s', $sourcePath));
        logMessage('[CityMug]', sprintf('Image URL: file://%s', $sourcePath));

        return promptForManualAnalysis($sourceFileName, $sourcePath, $normalized);
    }

    return $normalized;
}

function sanitizeTextValue(string $value): string
{
    $value = trim($value);

    return $value !== '' ? $value : 'unknown';
}

function normalizePlaceName(string $value): string
{
    $value = sanitizeTextValue($value);
    if ($value === 'unknown') {
        return $value;
    }

    $value = strtolower($value);
    $normalized = preg_replace_callback(
        "/(^|[\\s\\-\\(\\/'])+([a-z])/u",
        static function (array $matches): string {
            return $matches[1] . strtoupper($matches[2]);
        },
        $value
    );

    return $normalized !== null ? $normalized : $value;
}

function formatFloat(float $value): string
{
    return number_format($value, 6, '.', '');
}

function shouldPromptForManualCity(array $analysis): bool
{
    $city = strtolower(trim((string) ($analysis['city'] ?? 'unknown')));

    return $city === '' || $city === 'unknown';
}

function promptForManualAnalysis(string $sourceFileName, string $sourcePath, array $analysis): array
{
    $city = promptInput(sprintf('Enter city for %s', $sourceFileName));
    if ($city === '') {
        throw new RuntimeException(sprintf(
            'City is required. Review the image and run again: file://%s',
            $sourcePath
        ));
    }

    logMessage('[CityMug]', sprintf(
        'Manual city confirmed: city="%s". Country and coordinates will be resolved automatically.',
        $city
    ));

    return [
        'city' => normalizePlaceName($city),
        'country' => '',
        'display_name' => 'Starbucks ' . normalizePlaceName($city) . ' Mug',
        'description' => trim((string) ($analysis['description'] ?? '')),
        'confidence' => 1.0,
        'taken_at' => $analysis['taken_at'] ?? null,
        '_manual_city_input' => true,
        '_save_override' => true,
    ];
}

function promptInput(string $label, string $default = ''): string
{
    $prompt = $default !== ''
        ? sprintf('%s [%s]: ', $label, $default)
        : sprintf('%s: ', $label);

    if (function_exists('readline')) {
        $value = readline($prompt);
    } else {
        echo $prompt;
        $value = fgets(STDIN);
    }

    if ($value === false) {
        return $default;
    }

    $value = trim($value);

    return $value !== '' ? $value : $default;
}

function buildCityKey(string $city, string $country): string
{
    return FileService::slugify($city) . '-' . FileService::slugify($country);
}

function buildItemId(string $cityKey, array $mainItems): string
{
    $date = gmdate('Ymd');
    $prefix = $cityKey . '-' . $date . '-';
    $nextSequence = 1;

    foreach ($mainItems as $item) {
        $itemId = (string) ($item['id'] ?? '');
        if (strpos($itemId, $prefix) !== 0) {
            continue;
        }

        $sequence = (int) substr($itemId, strlen($prefix));
        if ($sequence >= $nextSequence) {
            $nextSequence = $sequence + 1;
        }
    }

    return sprintf('%s%02d', $prefix, $nextSequence);
}

function normalizeLookupCountry(string $country): string
{
    $country = trim($country);
    if ($country === '') {
        return '';
    }

    if (strtolower($country) === 'unknown') {
        return '';
    }

    return $country;
}

function purgeExistingItem(string $sourceFileName, string $outputDir, array $mainItems, array $processedFiles): array
{
    $removedIds = [];
    $removedCityKeys = [];

    foreach ($mainItems as $index => $item) {
        $itemSource = basename((string) ($item['source_image'] ?? ''));
        if ($itemSource !== $sourceFileName) {
            continue;
        }

        $itemId = (string) ($item['id'] ?? '');
        if ($itemId !== '') {
            $removedIds[] = $itemId;
        }
        $cityKey = (string) ($item['city_key'] ?? '');
        if ($cityKey !== '') {
            $removedCityKeys[] = $cityKey;
        }

        unset($mainItems[$index]);
    }

    if (isset($processedFiles[$sourceFileName]['id'])) {
        $processedId = (string) $processedFiles[$sourceFileName]['id'];
        if ($processedId !== '' && !in_array($processedId, $removedIds, true)) {
            $removedIds[] = $processedId;
        }
    }

    foreach ($removedIds as $itemId) {
        $itemDir = $outputDir . '/mugs/' . $itemId;
        if (!is_dir($itemDir)) {
            $itemDir = $outputDir . '/' . $itemId;
        }
        if (is_dir($itemDir)) {
            deleteDirectory($itemDir);
            logMessage('[CityMug]', sprintf('Removed old output for %s at %s', $sourceFileName, $itemId));
        }
    }

    foreach (array_unique($removedCityKeys) as $cityKey) {
        if (!cityKeyExistsInItems($cityKey, $mainItems)) {
            $cityDir = $outputDir . '/cities/' . $cityKey;
            if (is_dir($cityDir)) {
                deleteDirectory($cityDir);
                logMessage('[CityMug]', sprintf('Removed shared city data for %s', $cityKey));
            }
        }
    }

    unset($processedFiles[$sourceFileName]);

    return [array_values($mainItems), $processedFiles];
}

function cityKeyExistsInItems(string $cityKey, array $mainItems): bool
{
    foreach ($mainItems as $item) {
        if (($item['city_key'] ?? null) === $cityKey) {
            return true;
        }
    }

    return false;
}

function deleteDirectory(string $directory): void
{
    $items = scandir($directory);
    if ($items === false) {
        throw new RuntimeException(sprintf('Failed to read directory for deletion: %s', $directory));
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException(sprintf('Failed to delete file: %s', $path));
        }
    }

    if (!rmdir($directory)) {
        throw new RuntimeException(sprintf('Failed to delete directory: %s', $directory));
    }
}
