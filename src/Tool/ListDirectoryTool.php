<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Romanzaycev\Vibe\Service\Configuration;

// Для флагов

class ListDirectoryTool extends AbstractTool
{
    public function __construct(
        private Configuration $configuration,
    ) {}

    public function getName(): string
    {
        return 'list_directory';
    }

    public function getDescription(): string
    {
        return 'Lists contents of a specified directory. Paths are relative to the current project directory. Use forward slashes for paths. Use "." to list the project root. Allows filtering by type (files/directories) and recursive listing.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The relative path of the directory to list (e.g., "src/Service", "." for project root). Defaults to the project root if empty or not provided.',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Whether to list contents recursively. Defaults to false.',
                    'default' => false,
                ],
                'include_files' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include files in the listing. Defaults to true.',
                    'default' => true,
                ],
                'include_dirs' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include directories in the listing. Defaults to true.',
                    'default' => true,
                ],
            ],
            'required' => [],
        ];
    }

    public function requiresConfirmationByDefault(): bool
    {
        return false;
    }

    private function sanitizeRelativePath(string $pathInput): ?string
    {
        $path = str_replace('/', DIRECTORY_SEPARATOR, $pathInput);
        $path = str_replace(chr(0), '', $path); // Null-byte

        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '..') { // Запрещаем '..' в любой части пути
                // error_log("Path sanitization failed: '..' component found in '{$pathInput}'");
                return null;
            }
            if ($part === '.' || $part === '') { // Пропускаем '.' и пустые части (от двойных //)
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $part)) {
                // error_log("Path sanitization failed: invalid characters in component '{$part}' of '{$pathInput}'");
                return null;
            }
            $safeParts[] = $part;
        }

        if (empty($safeParts)) {
            return '.'; // Указывает на текущую директорию
        }

        return implode(DIRECTORY_SEPARATOR, $safeParts);
    }

    public function execute(array $arguments): string
    {
        $relativePathInput = $arguments['path'] ?? '.';
        $recursive = $arguments['recursive'] ?? false;
        $includeFiles = $arguments['include_files'] ?? true;
        $includeDirs = $arguments['include_dirs'] ?? true;

        if (trim($relativePathInput) === '') {
            $relativePathInput = '.';
        }

        if (!$includeFiles && !$includeDirs) {
            return json_encode(['status' => 'warning', 'message' => 'Nothing to list: both include_files and include_dirs are set to false.', 'path' => $relativePathInput, 'entries' => []]);
        }

        $safeRelativePath = $this->sanitizeRelativePath($relativePathInput);
        if ($safeRelativePath === null) {
            return json_encode(['status' => 'error', 'message' => "Invalid path provided: '{$relativePathInput}'. Path contains '..' or invalid characters."]);
        }

        $basePath = getcwd();
        $fullPath = $basePath . ($safeRelativePath === '.' ? '' : DIRECTORY_SEPARATOR . $safeRelativePath);

        $ignoreDirs = array_map(fn(string $p): string => $basePath . $p, $this->configuration->get('IGNORE_DIRS', []));

        $resolvedBase = realpath($basePath);
        $resolvedFullPath = realpath($fullPath);

        if ($resolvedBase === false) {
            return json_encode(['status' => 'error', 'message' => 'Could not resolve base project path.']);
        }
        if ($resolvedFullPath === false) {
            return json_encode(['status' => 'error', 'message' => "Directory '{$safeRelativePath}' (from '{$relativePathInput}') not found or is inaccessible."]);
        }
        if (!is_dir($resolvedFullPath)) {
            return json_encode(['status' => 'error', 'message' => "'{$safeRelativePath}' (from '{$relativePathInput}') is not a directory."]);
        }
        // Дополнительная проверка безопасности: убедимся, что разрешенный путь не выходит за пределы базового
        if (strpos($resolvedFullPath, $resolvedBase) !== 0) {
            // error_log("Security: Attempt to list '{$safeRelativePath}' (resolved to '{$resolvedFullPath}') which is outside '{$resolvedBase}'.");
            return json_encode(['status' => 'error', 'message' => "Security: Access to '{$safeRelativePath}' (from '{$relativePathInput}') is outside the project directory.\n"]);
        }
        if (!is_readable($resolvedFullPath)) {
            return json_encode(['status' => 'error', 'message' => "Directory '{$safeRelativePath}' (from '{$relativePathInput}') is not readable.\n"]);
        }

        $entries = [];
        try {
            if ($recursive) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($resolvedFullPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                /** @var \SplFileInfo $item */
                foreach ($iterator as $item) {
                    $isDir = $item->isDir();

                    if ($isDir) {
                        foreach ($ignoreDirs as $ignoreDir) {
                            if (str_starts_with($item->getPathname(), $ignoreDir)) {
                                continue 2;
                            }
                        }
                    }

                    if (($isDir && $includeDirs) || (!$isDir && $item->isFile() && $includeFiles)) {

                        // Получаем путь относительно $resolvedFullPath, потом относительно $resolvedBase
                        $entryPath = $item->getPathname();
                        // Сначала делаем путь относительным $resolvedFullPath
                        $entryRelativePath = ltrim(str_replace($resolvedFullPath, '', $entryPath), DIRECTORY_SEPARATOR);
                        // Затем, если $safeRelativePath не '.', добавляем его в начало
                        if ($safeRelativePath !== '.') {
                            $entryRelativePath = $safeRelativePath . (empty($entryRelativePath) ? '' : DIRECTORY_SEPARATOR . $entryRelativePath);
                        }
                        // Если $safeRelativePath и $entryRelativePath приводят к '.', нормализуем
                        if(empty($entryRelativePath) && $safeRelativePath === '.') $entryRelativePath = '.';


                        $entries[] = [
                            'name' => str_replace(DIRECTORY_SEPARATOR, '/', $entryRelativePath), // Возвращаем с /
                            'type' => $isDir ? 'directory' : 'file'
                        ];
                    }
                }
            } else { // Not recursive
                $items = scandir($resolvedFullPath);
                if ($items === false) throw new \RuntimeException("Failed to scan directory");

                foreach ($items as $itemName) {
                    if ($itemName === '.' || $itemName === '..') continue;

                    $itemFullPath = $resolvedFullPath . DIRECTORY_SEPARATOR . $itemName;
                    $isDir = is_dir($itemFullPath);

                    if ($isDir) {
                        foreach ($ignoreDirs as $ignoreDir) {
                            if (str_starts_with($itemFullPath, $ignoreDir)) {
                                continue 2;
                            }
                        }
                    }

                    $isFile = is_file($itemFullPath); // is_link будет определено как is_file или is_dir если ведет на них

                    if (($isDir && $includeDirs) || ($isFile && $includeFiles)) {
                        $entryName = $safeRelativePath === '.' ? $itemName : $safeRelativePath . DIRECTORY_SEPARATOR . $itemName;
                        $entries[] = [
                            'name' => str_replace(DIRECTORY_SEPARATOR, '/', $entryName), // Возвращаем с /
                            'type' => $isDir ? 'directory' : 'file'
                        ];
                    }
                }
            }

            // Сортируем для консистентности: сначала директории, потом файлы, всё по алфавиту
            usort($entries, function ($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a['type'] === 'directory' ? -1 : 1;
            });


            return json_encode([
                'status' => 'success',
                'path' => str_replace(DIRECTORY_SEPARATOR, '/', $safeRelativePath),
                'recursive' => $recursive,
                'include_files' => $includeFiles,
                'include_dirs' => $includeDirs,
                'entries' => $entries,
                'message' => "Directory '{$safeRelativePath}' (from '{$relativePathInput}') listed successfully."
            ]);

        } catch (\Exception $e) {
            // error_log("Error listing directory '{$safeRelativePath}': " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return json_encode(['status' => 'error', 'message' => "Error listing directory '{$safeRelativePath}' (from '{$relativePathInput}'): " . $e->getMessage()]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the list_directory tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "What's in the project root?",
            "Can you show me the contents of the 'src' directory?",
            "What files are in 'config/packages'?",
            "List all files and folders in the project, including subdirectories.",
            "I need to see all files in the 'public' directory and its subfolders.",
            "Can you show me only the folders inside 'src'?",
            "What files are directly in the root directory?",
            "Explore the 'tests' directory and show me everything inside.",
            "What's in the 'migrations' directory?",
            "Just show me the files in the 'var/log' folder."
        ];

        return implode("\n", $examples);
    }
}
