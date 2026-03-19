<?php

declare(strict_types=1);

final class JsonService
{
    public static function read(string $path, $default = null)
    {
        if (!file_exists($path)) {
            return $default;
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(sprintf('Invalid JSON in %s: %s', $path, json_last_error_msg()));
        }

        return $decoded;
    }

    public static function write(string $path, $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(sprintf('Failed to encode JSON for %s', $path));
        }

        $result = file_put_contents($path, $json . PHP_EOL);
        if ($result === false) {
            throw new RuntimeException(sprintf('Failed to write JSON file: %s', $path));
        }
    }
}
