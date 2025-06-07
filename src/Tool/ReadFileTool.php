<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

class ReadFileTool extends AbstractTool
{
    private const MAX_CONTENT_LENGTH = 50000; // Максимальное количество символов для возврата

    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Reads the content of a specified file. Paths are relative to the current project directory. Use forward slashes for paths (e.g., src/MyClass.php). Content might be truncated if it exceeds ' . self::MAX_CONTENT_LENGTH . ' characters.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename' => [
                    'type' => 'string',
                    'description' => 'The relative path and name of the file to read (e.g., "data/my_file.txt", "src/MyClass.php").',
                ],
            ],
            'required' => ['filename'],
        ];
    }

    /**
     * {@inheritdoc}
     * Reading files is generally a safe, read-only operation, so it doesn't require confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return false;
    }

    public function execute(array $arguments): string
    {
        $filename = $arguments['filename'] ?? null;

        if ($filename === null || trim($filename) === '') {
            return json_encode(['status' => 'error', 'message' => 'Filename is required and cannot be empty.']);
        }

        // Нормализация и санация пути (аналогично CreateFileTool)
        $filename = str_replace('/', DIRECTORY_SEPARATOR, $filename);
        $filename = str_replace(chr(0), '', $filename); // Null-byte

        $parts = explode(DIRECTORY_SEPARATOR, $filename);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                if ($part === '..') { // Запрещаем '..' в любой части пути
                    return json_encode(['status' => 'error', 'message' => "Path cannot contain '..' components."]);
                }
                continue; // Пропускаем '.', если он не ведет к проблеме
            }
            if (preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $part)) {
                return json_encode(['status' => 'error', 'message' => "Filename component '{$part}' contains invalid characters."]);
            }
            $safeParts[] = $part;
        }
        $safeFilename = implode(DIRECTORY_SEPARATOR, $safeParts);
        $safeFilename = ltrim($safeFilename, DIRECTORY_SEPARATOR);

        if (empty($safeFilename)) {
            return json_encode(['status' => 'error', 'message' => 'Filename became empty after sanitization or was invalid.']);
        }

        $basePath = getcwd();
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $safeFilename;

        // Проверка, что путь не выходит за пределы basePath
        $resolvedBase = realpath($basePath);
        if ($resolvedBase === false) {
            // Это неожиданно, getcwd() обычно возвращает существующий путь
            return json_encode(['status' => 'error', 'message' => 'Could not resolve base project path.']);
        }

        // Для реального пути файла мы не можем использовать realpath, если его ЕЩЕ нет (хотя для read_file он должен быть)
        // Но для проверки безопасности важно, чтобы он разрешался внутри $resolvedBase
        // Сначала проверим существование и тип
        if (!file_exists($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "File '{$safeFilename}' not found."]);
        }
        if (!is_file($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "'{$safeFilename}' is not a file."]);
        }
        if (!is_readable($fullPath)) {
            return json_encode(['status' => 'error', 'message' => "File '{$safeFilename}' is not readable."]);
        }

        // Финальная проверка безопасности пути уже после того, как мы знаем, что он существует
        $resolvedFullPath = realpath($fullPath);
        if ($resolvedFullPath === false) {
            // Может случиться, если файл был удален между проверками, или символическая ссылка битая
            return json_encode(['status' => 'error', 'message' => "Could not resolve full path for '{$safeFilename}'. File might be a broken symlink or inaccessible."]);
        }

        if (strpos($resolvedFullPath, $resolvedBase . DIRECTORY_SEPARATOR) !== 0 && $resolvedFullPath !== $resolvedBase) {
            // Логируем попытку доступа за пределы, если нужно
            // error_log("Security: Attempt to read '{$safeFilename}' (resolved to '{$resolvedFullPath}') which is outside the project directory '{$resolvedBase}'.");
            return json_encode(['status' => 'error', 'message' => "Security: Access to '{$safeFilename}' is outside the project directory."]);
        }

        try {
            $content = file_get_contents($fullPath);
            if ($content === false) {
                return json_encode(['status' => 'error', 'message' => "Failed to read content from file '{$safeFilename}'.\n"]);
            }

            $truncated = false;
            if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
                $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);
                $truncated = true;
            }

            $message = $truncated ?
                "File '{$safeFilename}' content retrieved and truncated to " . self::MAX_CONTENT_LENGTH . " characters." :
                "File '{$safeFilename}' content retrieved successfully.";

            return json_encode([
                'status' => 'success',
                'filename' => $safeFilename,
                'content' => $content,
                'truncated' => $truncated,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return json_encode(['status' => 'error', 'message' => "Error reading file '{$safeFilename}': " . $e->getMessage()]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the read_file tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Can you show me the code in 'src/Controller/DefaultController.php'?",
            "What's inside the 'config/services.yaml' file?",
            "Read the contents of 'README.md'.",
            "Show me the code for the 'User' entity in 'src/Entity/User.php'.",
            "What are the database configuration settings in '.env'?",
            "Can you display the content of 'tests/bootstrap.php'?",
            "I need to see the content of 'public/index.php'.",
            "What does the 'composer.json' file look like?",
            "Read the 'LICENSE' file.",
            "Show me the code in 'src/Kernel.php'."
        ];

        return implode("\n", $examples);
    }
}
