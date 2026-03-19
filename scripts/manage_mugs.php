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

$options = getopt('', ['list', 'delete:', 'interactive', 'help']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php scripts/manage_mugs.php --list\n";
    echo "  php scripts/manage_mugs.php --delete=<item-id>\n";
    echo "  php scripts/manage_mugs.php --interactive\n";
    exit(0);
}

$outputDir = defined('CITY_MUG_OUTPUT_DIR') ? CITY_MUG_OUTPUT_DIR : APP_DIR . '/output';
$mainJsonPath = $outputDir . '/main.json';
$processedPath = $outputDir . '/processed_files.json';

$mainItems = JsonService::read($mainJsonPath, []);
$processedFiles = JsonService::read($processedPath, []);

if (!is_array($mainItems)) {
    throw new RuntimeException('output/main.json must contain an array.');
}

if (!is_array($processedFiles)) {
    $processedFiles = [];
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

    deleteItemById($itemId, $mainItems, $processedFiles, $mainJsonPath, $processedPath, $outputDir);
    exit(0);
}

if (isset($options['interactive'])) {
    interactiveDelete($mainItems, $processedFiles, $mainJsonPath, $processedPath, $outputDir);
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

function interactiveDelete(
    array $mainItems,
    array $processedFiles,
    string $mainJsonPath,
    string $processedPath,
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

    deleteItemById($itemId, $mainItems, $processedFiles, $mainJsonPath, $processedPath, $outputDir);
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
    string $mainJsonPath,
    string $processedPath,
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

    JsonService::write($mainJsonPath, $remainingItems);
    JsonService::write($processedPath, $processedFiles);

    echo "Updated output/main.json and output/processed_files.json\n";
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
