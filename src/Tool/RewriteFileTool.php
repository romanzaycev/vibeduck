<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

class RewriteFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'rewrite_file';
    }

    public function getDescription(): string
    {
        return 'Rewrites the content of an existing file with the given content. Paths are relative to the current project directory. Use forward slashes for paths (e.g., src/MyClass.php). Requires the file to exist.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename' => [
                    'type' => 'string',
                    'description' => 'The relative path and name of the file to rewrite (e.g., "data/my_file.txt", "src/MyClass.php").',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The new content to write into the file.',
                ],
            ],
            'required' => ['filename', 'content'],
        ];
    }

    public function execute(array $arguments): string
    {
        $filename = $arguments['filename'] ?? null;
        $content = $arguments['content'] ?? '';

        if ($filename === null || trim($filename) === '') {
            return json_encode(['status' => 'error', 'message' => 'Filename is required and cannot be empty.']);
        }

        // Нормализуем слеши к DIRECTORY_SEPARATOR для внутренней работы, если LLM присылает /\
        $filename = str_replace('/', DIRECTORY_SEPARATOR, $filename);

        // Санация: запрещаем выход за пределы текущей директории и опасные символы
        $filename = str_replace(chr(0), '', $filename); // Null-byte
        $parts = explode(DIRECTORY_SEPARATOR, $filename);
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                return json_encode(['status' => 'error', 'message' => "Path cannot contain '..' components."]);
            }

            if ($part === '.') {
                continue; // Пропускаем '.'
            }

            if (preg_match('/[\\x00-\\x1F\\x7F<>:"|?*]/', $part)) {
                return json_encode(['status' => 'error', 'message' => "Filename component '{$part}' contains invalid characters."]);
            }

            $safeParts[] = $part;
        }

        $safeFilename = implode(DIRECTORY_SEPARATOR, $safeParts);

        // Убираем ведущие слеши, чтобы путь был строго относительным от getcwd()
        $safeFilename = ltrim($safeFilename, DIRECTORY_SEPARATOR);

        if (empty($safeFilename)) {
            return json_encode(['status' => 'error', 'message' => 'Filename became empty after sanitization or was invalid.']);
        }

        $basePath = getcwd();
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $safeFilename;

        // Дополнительная проверка после всех манипуляций: убедимся, что полный путь начинается с базового пути
        $resolvedBase = realpath($basePath);
        if ($resolvedBase === false) {
            return json_encode(['status' => 'error', 'message' => 'Could not resolve base project path.']);
        }
        // Сравниваем строки, предварительно нормализовав их, чтобы убедиться, что путь находится внутри проекта
        $normalizedFullPath = str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $fullPath);
        if (strpos($normalizedFullPath, $resolvedBase . DIRECTORY_SEPARATOR) !== 0 && $normalizedFullPath !== $resolvedBase) {
            return json_encode(['status' => 'error', 'message' => "Security: Final path '{$safeFilename}' is outside the project directory."]);
        }

        // --- Ключевое отличие от CreateFileTool ---
        // Проверка на существование файла. RewriteFileTool работает только с существующими файлами.
        if (!file_exists($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "File '{$safeFilename}' not found. Use 'create_file' to create new files."]);
        }

        // Проверка, не пытаемся ли мы перезаписать существующую директорию
        if (is_dir($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "Cannot rewrite file. A directory exists at '{$safeFilename}'. Use 'patch_file' for directories if applicable."]);
        }
        // --- Конец ключевого отличия ---

        $fileDir = dirname($fullPath);

        // Для RewriteFileTool директория должна уже существовать, но проверка на всякий случай
        if (!is_dir($fileDir)) {
            return json_encode(['status' => 'error', 'message' => "Directory for file '{$safeFilename}' does not exist."]);
        }

        if (!is_writable($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "File is not writable: {$safeFilename}."]);
        }

        try {
            $bytesWritten = file_put_contents($fullPath, $content);

            if ($bytesWritten === false) {
                return json_encode(['status' => 'error', 'message' => "Failed to write to file '{$safeFilename}'. Check permissions."]);
            }

            return json_encode(['status' => 'success', 'message' => "File '{$safeFilename}' rewritten successfully."]);
        } catch (\Exception $e) {
            return json_encode(['status' => 'error', 'message' => "Error processing file '{$safeFilename}': " . $e->getMessage()]);
        }
    }

    /**
     * {@inheritdoc}
     * This tool modifies the file system, so it requires confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    /**
     * Returns a string containing multiple few-shot examples for the rewrite_file tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Rewrite the content of 'src/Service/MyService.php' with this new code: ...",
            "Replace the content of '.env.local' with these environment variables: ...",
            "Update the configuration in 'config/packages/security.yaml' to: ...",
            "Change the contents of 'public/index.html' to: ...",
            "Rewrite the 'tests/SomeTest.php' file with the following test case: ...",
            "Replace the content of the 'README.md' file with this description: ...",
            "Set the content of 'config/routes.yaml' to: ...",
            "Completely replace the code in 'src/Controller/ApiController.php' with: ...",
            "Rewrite the '.gitignore' file with these entries: ...",
            "Update the contents of 'composer.json' to: ..."
        ];

        return implode("\n", $examples);
    }
}
