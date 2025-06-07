<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

class DeleteFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'delete_file';
    }

    public function getDescription(): string
    {
        return 'Deletes a specified file. Paths are relative to the current project directory. Use forward slashes for paths (e.g., data/my_file.txt).';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename' => [
                    'type' => 'string',
                    'description' => 'The relative path and name of the file to delete (e.g., "data/my_file.txt").',
                ],
            ],
            'required' => ['filename'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $filename = $arguments['filename'] ?? null;

        if ($filename === null || trim($filename) === '') {
            return json_encode(['status' => 'error', 'message' => 'Filename is required and cannot be empty.']);
        }

        // Санация пути (аналогично CreateFileTool)
        $filename = str_replace('/', DIRECTORY_SEPARATOR, $filename);
        $filename = str_replace(chr(0), '', $filename); // Null-byte

        $parts = explode(DIRECTORY_SEPARATOR, $filename);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                 if ($part === '..') { // Запрещаем '..' в любой части пути
                    return json_encode(['status' => 'error', 'message' => "Path cannot contain '..' components."]);
                }
                continue; // Пропускаем '.'
            }
             if (preg_match('/[\\x00-\\x1F\\x7F<>:\"|?*]/', $part)) {
                return json_encode(['status' => 'error', 'message' => "Filename component '{$part}' contains invalid characters."]);
            }
            $safeParts[] = $part;
        }
        $safeFilename = implode(DIRECTORY_SEPARATOR, $safeParts);

        // Убираем ведущие слеши
        $safeFilename = ltrim($safeFilename, DIRECTORY_SEPARATOR);

         if (empty($safeFilename)) {
             return json_encode(['status' => 'error', 'message' => 'Filename became empty after sanitization or was invalid.']);
         }


        $basePath = getcwd();
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $safeFilename;

        // Проверка, что путь не выходит за пределы basePath
        $resolvedBase = realpath($basePath);
        if ($resolvedBase === false) {
            return json_encode(['status' => 'error', 'message' => 'Could not resolve base project path.']);
        }

        // Проверка существования файла и что это не директория
        if (!file_exists($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "File '{$safeFilename}' not found."]);
        }
        if (!is_file($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "'{$safeFilename}' is not a file. Cannot delete directories with this tool."]);
        }

        // Финальная проверка безопасности пути уже после того, как мы знаем, что он существует
        $resolvedFullPath = realpath($fullPath);
         if ($resolvedFullPath === false) {
             return json_encode(['status' => 'error', 'message' => "Could not resolve full path for '{$safeFilename}'. File might be a broken symlink or inaccessible."]);
         }

        if (strpos($resolvedFullPath, $resolvedBase . DIRECTORY_SEPARATOR) !== 0 && $resolvedFullPath !== $resolvedBase) {
             return json_encode(['status' => 'error', 'message' => "Security: Access to '{$safeFilename}' is outside the project directory."]);
        }


        if (!is_writable($fullPath)) {
             return json_encode(['status' => 'error', 'message' => "File is not writable: {$safeFilename}."]);
        }

        try {
            if (unlink($fullPath)) {
                return json_encode(['status' => 'success', 'message' => "File '{$safeFilename}' deleted successfully."]);
            } else {
                // unlink может вернуть false без исключения в некоторых случаях
                return json_encode(['status' => 'error', 'message' => "Failed to delete file '{$safeFilename}'. Check permissions."]);
            }
        } catch (\Exception $e) {
            return json_encode(['status' => 'error', 'message' => "Error deleting file '{$safeFilename}': " . $e->getMessage()]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the delete_file tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Delete the file 'temp/old_report.txt'.",
            "Remove the file located at 'src/Utils/TemporaryFile.php'.",
            "Can you please get rid of 'backup/unused_config.ini'?",
            "I want to delete 'data/archive/log_2023.zip'."
        ];

        return implode("\n", $examples);
    }
}
