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
require_once APP_DIR . '/scripts/lib/FileService.php';
require_once APP_DIR . '/scripts/lib/GeoService.php';

$userAgent = defined('CITY_MUG_USER_AGENT')
    ? CITY_MUG_USER_AGENT
    : 'CityMugMap/1.0 (local build script)';
$geoService = new GeoService(
    $userAgent,
    defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : ''
);

$options = getopt('', ['list', 'delete:', 'edit:', 'interactive', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php scripts/manage_mugs.php --list\n";
    echo "  php scripts/manage_mugs.php --delete=<item-id>\n";
    echo "  php scripts/manage_mugs.php --edit=<source-filename>   e.g. --edit=IMG_7130.jpeg\n";
    echo "  php scripts/manage_mugs.php --interactive\n";
    exit(0);
}

$outputDir = defined('CITY_MUG_OUTPUT_DIR') ? CITY_MUG_OUTPUT_DIR : APP_DIR . '/output';
$mainJsonPath = $outputDir . '/main.json';
$processedPath = $outputDir . '/processed_files.json';
$overridePath = $outputDir . '/manual_overrides.json';

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

if (isset($options['list'])) {
    listItems($mainItems);
    exit(0);
}

if (isset($options['delete'])) {
    $itemId = trim((string) $options['delete']);
    if ($itemId === '') {
        fwrite(STDERR, "Missing item id.\n");
        exit(1);
    }

    deleteItemById($itemId, $mainItems, $processedFiles, $overrides, $mainJsonPath, $processedPath, $overridePath, $outputDir);
    exit(0);
}

if (isset($options['edit'])) {
    $editInput = trim((string) $options['edit']);
    if ($editInput === '') {
        fwrite(STDERR, "Missing filename or item id.\n");
        exit(1);
    }

    // Accept item ID: resolve to source filename
    $sourceFileName = resolveEditInputToSourceFile($editInput, $mainItems);
    if ($sourceFileName === null) {
        fwrite(STDERR, sprintf("Cannot resolve to a source file: %s\n", $editInput));
        exit(1);
    }

    editOverride($sourceFileName, $mainItems, $overrides, $overridePath);
    exit(0);
}

if (isset($options['interactive'])) {
    interactiveDelete($mainItems, $processedFiles, $overrides, $mainJsonPath, $processedPath, $overridePath, $outputDir);
    exit(0);
}

echo "No action provided. Use --help.\n";
exit(1);

function listItems(array $mainItems): void
{
    if ($mainItems === []) {
        echo "No mug items found.\n";
        return;
    }

    echo "City mug items:\n";
    $rows = [];
    $headers = ['No', 'ID', 'Name', 'Location', 'Source File', 'Created At'];

    foreach (array_values($mainItems) as $index => $item) {
        $sourceImage = (string) ($item['source_image'] ?? '');

        $rows[] = [
            (string) ($index + 1),
            (string) ($item['id'] ?? ''),
            (string) ($item['display_name'] ?? ''),
            sprintf('%s, %s', (string) ($item['city'] ?? ''), (string) ($item['country'] ?? '')),
            $sourceImage !== '' ? $sourceImage : '-',
            (string) (($item['created_at'] ?? '') !== '' ? $item['created_at'] : '-'),
        ];
    }

    renderTable($headers, $rows);
}

function editOverride(string $sourceFileName, array $mainItems, array $overrides, string $overridePath): void
{
    global $geoService;

    // Build current values: override takes priority, fall back to main.json
    $current = $overrides[$sourceFileName] ?? null;
    if ($current === null) {
        foreach ($mainItems as $item) {
            if (basename((string) ($item['source_image'] ?? '')) === $sourceFileName) {
                $current = $item;
                break;
            }
        }
    }

    if ($current === null) {
        echo sprintf("No existing data found for %s. Starting with empty values.\n", $sourceFileName);
        $current = [];
    } else {
        echo sprintf("Editing override for: %s\n", $sourceFileName);
    }

    $typeDefault = $current['type'] ?? 'city';
    $type = promptInput('Type (city/country/store/region)', $typeDefault);
    if (!in_array($type, ['city', 'country', 'store', 'region'], true)) {
        $type = 'city';
    }

    $result = ['type' => $type];

    if ($type === 'store') {
        // Store: Google Maps URL drives everything
        $existingMapsUrl = $current['google_maps_url'] ?? '';
        $mapsUrl = promptInput('Google Maps URL', $existingMapsUrl);

        if ($mapsUrl === '') {
            fwrite(STDERR, "Google Maps URL is required for store type.\n");
            exit(1);
        }

        echo "Resolving Google Maps URL...\n";
        try {
            $mapsData = $geoService->resolveGoogleMapsUrl($mapsUrl);
            echo sprintf("  Name:     %s\n", $mapsData['name']);
            echo sprintf("  GPS:      lat=%s, lng=%s\n", $mapsData['lat'], $mapsData['lng']);
            echo sprintf("  Area:     %s, %s\n", $mapsData['resolved_city'], $mapsData['resolved_country']);
            echo "\n--- Confirm / edit each field (Enter to keep) ---\n";

            $result['google_maps_url'] = $mapsUrl;
            $result['lat']             = $mapsData['lat'];
            $result['lng']             = $mapsData['lng'];
            $result['venue']           = promptInput('Venue name (English)', $mapsData['name'] !== '' ? $mapsData['name'] : ($current['venue'] ?? ''));
            $result['city']            = promptInput('City', $mapsData['resolved_city']);
            $result['country']         = promptInput('Country', $mapsData['resolved_country']);
            $result['display_name']    = promptInput('Display name', $current['display_name'] ?? buildStoreDisplayName($result['venue']));
            $result['description']     = promptInput('Description', $current['description'] ?? '');
        } catch (RuntimeException $e) {
            fwrite(STDERR, sprintf("Could not resolve Google Maps URL: %s\n", $e->getMessage()));
            exit(1);
        }
    } else {
        $result['venue']         = '';
        $result['city']          = promptInput('City', $current['city'] ?? '');
        $result['country']       = promptInput('Country', $current['country'] ?? '');
        $result['display_name']  = promptInput('Display name', $current['display_name'] ?? '');
        $result['description']   = promptInput('Description', $current['description'] ?? '');
    }

    $result['confidence'] = (float) promptInput('Confidence (0-1)', (string) ($current['confidence'] ?? '1'));
    $result['taken_at']   = $current['taken_at'] ?? null;

    $overrides[$sourceFileName] = $result;
    JsonService::write($overridePath, $overrides);

    echo sprintf("\nSaved override for %s:\n", $sourceFileName);
    foreach ($result as $k => $v) {
        if ($k === 'taken_at') {
            continue;
        }
        echo sprintf("  %-14s %s\n", $k . ':', is_string($v) ? $v : (string) $v);
    }
    echo "\nRun to apply:\n";
    echo sprintf("  php scripts/manage_mugs.php --delete=%s  (if already processed)\n", findItemIdBySource($sourceFileName, $mainItems) ?? '<item-id>');
    echo sprintf("  php scripts/process_mugs.php --file=%s\n", $sourceFileName);
}

function resolveEditInputToSourceFile(string $input, array $mainItems): ?string
{
    // Already a filename (contains a dot extension)
    if (preg_match('/\.\w+$/', $input)) {
        return $input;
    }

    // Treat as item ID — find source_image in main.json
    foreach ($mainItems as $item) {
        if (($item['id'] ?? null) === $input) {
            $source = basename((string) ($item['source_image'] ?? ''));

            return $source !== '' ? $source : null;
        }
    }

    return null;
}

function findItemIdBySource(string $sourceFileName, array $mainItems): ?string
{
    foreach ($mainItems as $item) {
        if (basename((string) ($item['source_image'] ?? '')) === $sourceFileName) {
            return (string) ($item['id'] ?? '');
        }
    }

    return null;
}

function interactiveDelete(
    array $mainItems,
    array $processedFiles,
    array $overrides,
    string $mainJsonPath,
    string $processedPath,
    string $overridePath,
    string $outputDir
): void {
    if ($mainItems === []) {
        echo "No mug items found.\n";
        return;
    }

    listItems($mainItems);
    $selection = promptInput('Enter the number or id to delete');
    if ($selection === '') {
        echo "Cancelled.\n";
        return;
    }

    $itemId = resolveSelectionToId($selection, $mainItems);
    if ($itemId === null) {
        fwrite(STDERR, sprintf("Unknown selection: %s\n", $selection));
        exit(1);
    }

    deleteItemById($itemId, $mainItems, $processedFiles, $overrides, $mainJsonPath, $processedPath, $overridePath, $outputDir);
}

function resolveSelectionToId(string $selection, array $mainItems): ?string
{
    if (ctype_digit($selection)) {
        $index = (int) $selection - 1;
        $items = array_values($mainItems);

        if (isset($items[$index]['id'])) {
            return (string) $items[$index]['id'];
        }
    }

    foreach ($mainItems as $item) {
        if (($item['id'] ?? null) === $selection) {
            return (string) $item['id'];
        }
    }

    return null;
}

function deleteItemById(
    string $itemId,
    array $mainItems,
    array $processedFiles,
    array $overrides,
    string $mainJsonPath,
    string $processedPath,
    string $overridePath,
    string $outputDir
): void {
    $targetItem = null;
    $remainingItems = [];

    foreach ($mainItems as $item) {
        if (($item['id'] ?? null) === $itemId) {
            $targetItem = $item;
            continue;
        }

        $remainingItems[] = $item;
    }

    if ($targetItem === null) {
        fwrite(STDERR, sprintf("Item id not found: %s\n", $itemId));
        exit(1);
    }

    echo sprintf("Deleting: %s | %s\n", $itemId, (string) ($targetItem['display_name'] ?? ''));

    $cityKey = (string) ($targetItem['city_key'] ?? '');

    $sourceImage = basename((string) ($targetItem['source_image'] ?? ''));
    if ($sourceImage !== '' && isset($processedFiles[$sourceImage])) {
        unset($processedFiles[$sourceImage]);
        echo sprintf("Removed processed file record: %s\n", $sourceImage);
    }

    $itemDir = $outputDir . '/mugs/' . $itemId;
    if (!is_dir($itemDir)) {
        $itemDir = $outputDir . '/' . $itemId;
    }
    if (is_dir($itemDir)) {
        deleteDirectory($itemDir);
        echo sprintf("Removed directory: %s\n", $itemDir);
    }

    if ($cityKey !== '' && !cityKeyExistsInItems($cityKey, $remainingItems)) {
        $cityDir = $outputDir . '/cities/' . $cityKey;
        if (is_dir($cityDir)) {
            deleteDirectory($cityDir);
            echo sprintf("Removed shared city directory: %s\n", $cityDir);
        }
    }

    if ($sourceImage !== '' && isset($overrides[$sourceImage])) {
        unset($overrides[$sourceImage]);
        JsonService::write($overridePath, $overrides);
        echo sprintf("Removed manual override: %s\n", $sourceImage);
    }

    JsonService::write($mainJsonPath, $remainingItems);
    JsonService::write($processedPath, $processedFiles);

    echo "Updated main.json, processed_files.json, manual_overrides.json\n";
    echo "Delete completed.\n";
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

function buildStoreDisplayName(string $venue): string
{
    if (stripos($venue, 'starbucks') === 0) {
        return $venue;
    }

    return 'Starbucks ' . $venue;
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

function renderTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ($headers as $index => $header) {
        $widths[$index] = strlen($header);
    }

    foreach ($rows as $row) {
        foreach ($row as $index => $cell) {
            $cellLength = strlen($cell);
            if ($cellLength > $widths[$index]) {
                $widths[$index] = $cellLength;
            }
        }
    }

    echo buildTableRow($headers, $widths);
    echo buildSeparatorRow($widths);
    foreach ($rows as $row) {
        echo buildTableRow($row, $widths);
    }
}

function buildTableRow(array $cells, array $widths): string
{
    $parts = [];
    foreach ($cells as $index => $cell) {
        $parts[] = str_pad($cell, $widths[$index], ' ', STR_PAD_RIGHT);
    }

    return '| ' . implode(' | ', $parts) . " |\n";
}

function buildSeparatorRow(array $widths): string
{
    $parts = [];
    foreach ($widths as $width) {
        $parts[] = str_repeat('-', $width);
    }

    return '|-' . implode('-|-', $parts) . "-|\n";
}
