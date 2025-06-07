<?php declare(strict_types=1);

namespace Romanzaycev\Vibe\Storage;

use Romanzaycev\Vibe\Service\Configuration;

class Storage
{
    private string $baseStoragePath;

    public function __construct(
        Configuration $configService,
    )
    {
        $this->baseStoragePath = $configService->get('VIBE_DATA_DIR');

        if (empty($this->baseStoragePath)) {
            throw new \RuntimeException(
                'STORAGE_PATH is not configured. Please check your `vibeduck` configuration.'
            );
        }

        if (!is_dir($this->baseStoragePath)) {
            if (!mkdir($this->baseStoragePath, 0755, true) && !is_dir($this->baseStoragePath)) {
                throw new \RuntimeException(
                    "Failed to create base storage directory: {$this->baseStoragePath}"
                );
            }
        }

        if (!is_writable($this->baseStoragePath)) {
            throw new \RuntimeException(
                "Base storage directory is not writable: {$this->baseStoragePath}"
            );
        }
    }

    /**
     * Retrieves a specific data collection.
     * Each collection is represented by a JSON file within the base storage path.
     *
     * @param string $collectionName The name of the collection (e.g., "history", "cache").
     *                               This will be used as the filename (e.g., "history.json").
     * @return StorageCollection An instance to manage the specified collection.
     */
    public function getCollection(string $collectionName): StorageCollection
    {
        // Basic sanitization for collection name to prevent path traversal,
        // though StorageCollection should also be robust.
        // Allowing only alphanumeric, underscore, and hyphen.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $collectionName)) {
            throw new \InvalidArgumentException(
                "Invalid collection name. Only alphanumeric characters, underscores, and hyphens are allowed."
            );
        }

        $filePath = $this->baseStoragePath . DIRECTORY_SEPARATOR . $collectionName . '.json';

        // The StorageCollection itself will handle the creation of its file if it doesn't exist,
        // and ensuring its immediate parent directory exists (if the filePath implied subdirs,
        // which it doesn't with this simple concatenation).
        return new StorageCollection($filePath);
    }

    /**
     * Gets the resolved base path for all storage collections.
     *
     * @return string
     */
    public function getBaseStoragePath(): string
    {
        return $this->baseStoragePath;
    }
}
