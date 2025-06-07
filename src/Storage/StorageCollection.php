<?php declare(strict_types=1);

namespace Romanzaycev\Vibe\Storage;

use Countable;
use IteratorAggregate;

class StorageCollection implements IteratorAggregate, Countable
{
    private string $filePath;
    private array $data = [];
    private bool $isLoaded = false;

    /**
     * StorageCollection constructor.
     *
     * @param string $filePath The absolute path to the JSON file for this collection.
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->ensureFileParentDirectoryExists();
    }

    /**
     * Ensures that the parent directory for the collection file exists.
     *
     * @throws \RuntimeException If the directory cannot be created.
     */
    private function ensureFileParentDirectoryExists(): void
    {
        $directory = dirname($this->filePath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                // Check again with is_dir in case of race condition
                throw new \RuntimeException(
                    "Failed to create directory for storage collection: {$directory}"
                );
            }
        }
        // Также убедимся, что директория доступна для записи, если файл еще не существует.
        // Если файл существует, то is_writable($this->filePath) проверит сам файл.
        if (!file_exists($this->filePath) && !is_writable($directory)) {
            throw new \RuntimeException(
                "Storage collection directory is not writable: {$directory}"
            );
        }
    }

    /**
     * Loads the data from the JSON file into memory.
     * If the file does not exist, it initializes an empty collection.
     * If the file is corrupted, it logs an error (conceptually) and initializes an empty collection.
     */
    private function load(): void
    {
        if ($this->isLoaded) {
            return;
        }

        if (!file_exists($this->filePath)) {
            $this->data = [];
            $this->isLoaded = true;
            // No need to save an empty file immediately, it will be created on first write.
            return;
        }

        // Проверяем доступность файла на чтение перед попыткой чтения
        if (!is_readable($this->filePath)) {
            throw new \RuntimeException("Storage collection file is not readable: {$this->filePath}");
        }

        $jsonData = file_get_contents($this->filePath);

        if ($jsonData === false) {
            // Should not happen if is_readable passed, but defensive check
            throw new \RuntimeException("Failed to read storage collection file: {$this->filePath}");
        }

        if (empty(trim($jsonData))) { // Handle empty file as empty array
            $this->data = [];
        } else {
            try {
                $decodedData = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decodedData) || (isset($decodedData[0]) && !is_array($decodedData[0])) && !empty($decodedData)) {
                    // We expect an array of arrays/objects. If it's a scalar or single object not in an array (and not an empty array),
                    // it's not the structure we manage. Log and reset.
                    // error_log("Storage collection file '{$this->filePath}' does not contain a valid JSON array of records. Resetting.");
                    $this->data = [];
                } else {
                    // Ensure keys are sequential if it's meant to be a list-like array
                    // For simplicity, we assume it IS a list-like array (array of records)
                    $this->data = $decodedData;
                }
            } catch (\JsonException $e) {
                // Log the error, e.g., using error_log() or a proper logger
                error_log("Failed to decode JSON from '{$this->filePath}': {$e->getMessage()}. Initializing as empty collection.");
                $this->data = []; // Initialize as empty if JSON is corrupt
            }
        }

        $this->isLoaded = true;
    }

    /**
     * Saves the current in-memory data to the JSON file.
     * Uses file locking to prevent race conditions.
     *
     * @throws \RuntimeException If data cannot be saved.
     */
    private function save(): void
    {
        // Убедимся, что данные загружены, чтобы не перезаписать файл пустым массивом, если load не вызывался
        // хотя операции, вызывающие save, обычно вызывают load первыми.
        if (!$this->isLoaded && file_exists($this->filePath)) {
            $this->load(); // Загружаем, чтобы не потерять существующие данные, если это не setAll или clear
        }

        $jsonData = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            // This should ideally not happen with valid array data
            throw new \RuntimeException("Failed to encode data to JSON for file: {$this->filePath}");
        }

        // Используем SplFileObject для более надежной работы с файлом и блокировками
        try {
            $file = new \SplFileObject($this->filePath, 'c'); // 'c' Open for read/write; place pointer at beginning; create if not exist
            if (!$file->flock(LOCK_EX)) {
                throw new \RuntimeException("Could not acquire exclusive lock on file: {$this->filePath}");
            }
            $file->ftruncate(0); // Truncate file to zero length
            $bytesWritten = $file->fwrite($jsonData);
            $file->fflush();
            $file->flock(LOCK_UN); // Release the lock

            if ($bytesWritten === 0 && !empty($this->data)) { // Проверка, что что-то записалось, если данные не пустые
                // It's possible fwrite returns 0 on error for some systems.
                // If data is empty, 0 bytes is fine.
                // This is a soft check, as fwrite can return 0 and still be "successful" if string is empty.
                // Harder check: compare strlen($jsonData) with $bytesWritten
                if (strlen($jsonData) !== $bytesWritten) {
                    // error_log("Potential write error or incomplete write to {$this->filePath}");
                }
            }
        } catch (\Exception $e) { // Catch any SplFileObject exceptions or others
            if (isset($file)) { // Убедимся, что блокировка снята в случае ошибки
                $file->flock(LOCK_UN);
            }
            throw new \RuntimeException(
                "Failed to write to storage collection file '{$this->filePath}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Retrieves all records from the collection.
     *
     * @return array<int, array>
     */
    public function getAll(): array
    {
        $this->load();
        return $this->data;
    }

    /**
     * Adds a new record to the collection and saves it.
     *
     * @param array<string, mixed> $item The record to add.
     */
    public function add(array $item): void
    {
        $this->load(); // Ensure data is loaded and consistent
        $this->data[] = $item; // Simple append
        $this->save();
    }

    /**
     * Replaces all records in the collection with a new set of records and saves.
     *
     * @param array<int, array<string, mixed>> $items The new set of records.
     */
    public function setAll(array $items): void
    {
        // Validate that $items is an array of arrays (or empty)
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("All items in setAll must be arrays (records).");
            }
        }
        $this->data = array_values($items); // Ensure it's a list-like array
        $this->isLoaded = true; // We've explicitly set the data
        $this->save();
    }

    /**
     * Clears all records from the collection and saves it.
     */
    public function clear(): void
    {
        $this->data = [];
        $this->isLoaded = true; // Data is now explicitly empty and loaded
        $this->save();
    }

    /**
     * Gets the file path of this storage collection.
     *
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * {@inheritdoc}
     * @return \ArrayIterator<int, array>
     */
    public function getIterator(): \ArrayIterator
    {
        $this->load();
        return new \ArrayIterator($this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        $this->load();
        return count($this->data);
    }

    /**
     * Finds records matching a specific criteria.
     * This is a simple linear search. For performance on large datasets, indexing would be needed.
     *
     * @param callable $predicate A function that accepts an item and returns true if it matches.
     *                            Example: fn($item) => isset($item['sessionId']) && $item['sessionId'] === 'my_session'
     * @return array<int, array> Array of matching records.
     */
    public function findBy(callable $predicate): array
    {
        $this->load();
        $results = [];

        foreach ($this->data as $key => $item) {
            if ($predicate($item) === true) {
                // Сохраняем оригинальные ключи, если это важно, или просто array_push
                $results[$key] = $item; // или array_push($results, $item); для ре-индексации
            }
        }

        return $results; // или return array_values($results); если нужны последовательные ключи
    }

    /**
     * Removes records matching a specific criteria and saves the collection.
     *
     * @param callable $predicate A function that accepts an item and returns true if it should be removed.
     * @return int Number of items removed.
     */
    public function removeBy(callable $predicate): int
    {
        $this->load();
        $initialCount = count($this->data);
        $this->data = array_filter($this->data, fn($item) => !$predicate($item));
        // Re-index array to prevent sparse arrays if records are removed from the middle.
        $this->data = array_values($this->data);
        $removedCount = $initialCount - count($this->data);

        if ($removedCount > 0) {
            $this->save();
        }

        return $removedCount;
    }
}
