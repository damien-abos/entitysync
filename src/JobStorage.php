<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

/**
 * Files of the import jobs: the uploaded CSV and the preview snapshot.
 *
 * They live in GLPI's plugin documents directory (files/_plugins/entitysync/jobs),
 * which GLPI's temporary files cleanup does not touch, because a queued job may wait
 * for the background task.
 */
final class JobStorage
{
    public static function directory(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/entitysync/jobs';
    }

    public static function ensureDirectory(): void
    {
        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create the directory "%s".', $dir));
        }
    }

    public static function csvPath(int $jobId): string
    {
        return self::directory() . '/' . $jobId . '.csv';
    }

    public static function snapshotPath(int $jobId): string
    {
        return self::directory() . '/' . $jobId . '.json';
    }

    public static function storeUpload(string $uploadedFile, int $jobId): bool
    {
        self::ensureDirectory();

        return move_uploaded_file($uploadedFile, self::csvPath($jobId));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function writeSnapshot(int $jobId, array $snapshot): void
    {
        self::ensureDirectory();
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents(self::snapshotPath($jobId), $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write the preview snapshot.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readSnapshot(int $jobId): ?array
    {
        $path = self::snapshotPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public static function remove(int $jobId): void
    {
        foreach ([self::csvPath($jobId), self::snapshotPath($jobId)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Removes the files whose job no longer exists.
     *
     * @param list<int> $existingJobIds
     */
    public static function removeOrphans(array $existingJobIds): void
    {
        $existing = array_flip($existingJobIds);
        foreach (glob(self::directory() . '/*.{csv,json}', GLOB_BRACE) ?: [] as $file) {
            $id = (int) basename($file);
            if (!isset($existing[$id])) {
                @unlink($file);
            }
        }
    }

    public static function removeAll(): void
    {
        foreach (glob(self::directory() . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::directory());
        @rmdir(dirname(self::directory()));
    }
}
