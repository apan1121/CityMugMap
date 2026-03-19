<?php

declare(strict_types=1);

final class FileService
{
    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }
    }

    public static function copyImage(string $sourcePath, string $destinationDirectory): string
    {
        self::ensureDirectory($destinationDirectory);

        $fileName = 'mug.jpg';
        $destinationPath = rtrim($destinationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        if (class_exists('Imagick')) {
            self::normalizeImageToJpeg($sourcePath, $destinationPath);

            return $fileName;
        }

        if (!copy($sourcePath, $destinationPath)) {
            throw new RuntimeException(sprintf('Failed to copy image from %s to %s', $sourcePath, $destinationPath));
        }

        return $fileName;
    }

    private static function normalizeImageToJpeg(string $sourcePath, string $destinationPath): void
    {
        $image = new Imagick($sourcePath);
        $image->autoOrient();
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $image->setImageFormat('jpeg');
        $image->setImageCompression(Imagick::COMPRESSION_JPEG);
        $image->setImageCompressionQuality(90);
        $image->stripImage();

        if (!$image->writeImage($destinationPath)) {
            throw new RuntimeException(sprintf('Failed to write processed image: %s', $destinationPath));
        }

        $image->clear();
        $image->destroy();
    }

    public static function relativePath(string $absolutePath): string
    {
        $normalized = str_replace(APP_DIR . DIRECTORY_SEPARATOR, '', $absolutePath);

        return str_replace(DIRECTORY_SEPARATOR, '/', $normalized);
    }

    public static function listImageFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $items = scandir($directory);
        if ($items === false) {
            throw new RuntimeException(sprintf('Failed to read directory: %s', $directory));
        }

        $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    public static function slugify(string $value): string
    {
        $value = trim($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'unknown';
    }
}
