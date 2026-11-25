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

$outputDir = defined('CITY_MUG_OUTPUT_DIR') ? CITY_MUG_OUTPUT_DIR : APP_DIR . '/output';
$metaPaths = glob($outputDir . '/cities/*/meta.json') ?: [];
sort($metaPaths);

$updated = 0;
$removedWikipedia = 0;
$removedTripadvisor = 0;

foreach ($metaPaths as $metaPath) {
    $meta = JsonService::read($metaPath, []);
    if (!is_array($meta)) {
        continue;
    }

    $changed = false;
    if (array_key_exists('wikipedia', $meta)) {
        unset($meta['wikipedia']);
        $removedWikipedia++;
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
    if (array_key_exists('tripadvisor', $meta)) {
        unset($meta['tripadvisor']);
        $removedTripadvisor++;
        $changed = true;
    }

    if (!$changed) {
        continue;
    }

    JsonService::write($metaPath, $meta);
    $updated++;
    echo '[MetaClean] Updated ' . $metaPath . PHP_EOL;
}

echo sprintf(
    '[MetaClean] Done. updated=%d wikipedia_removed=%d tripadvisor_removed=%d%s',
    $updated,
    $removedWikipedia,
    $removedTripadvisor,
    PHP_EOL
);
