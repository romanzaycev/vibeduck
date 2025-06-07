<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

class CreateFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'create_file';
    }

    public function getDescription(): string
    {
        return 'Creates a new file with the given name and content. Paths are relative to the current project directory. Use forward slashes for paths (e.g., src/MyClass.php).';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename' => [
                    'type' => 'string',
                    'description' => 'The relative path and name of the file to create (e.g., "data/my_file.txt", "src/MyClass.php").',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write into the new file.',
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

        // Проверка на существование файла
        if (file_exists($filename)) {
            return json_encode(['status' => 'error', 'message' => "File '{$filename}' already exists."]);
        }

        // Нормализуем слеши к DIRECTORY_SEPARATOR для внутренней работы, если LLM присылает /
        $filename = str_replace('/', DIRECTORY_SEPARATOR, $filename);

        // Санация: запрещаем выход за пределы текущей директории и опасные символы
        // 1. Убираем '../' и './' и комбинации с ними, а также null-байты
        $filename = str_replace(chr(0), '', $filename); // Null-byte
        // Убираем любые попытки подняться выше или остаться в той же директории через .. или . в начале или середине пути
        $parts = explode(DIRECTORY_SEPARATOR, $filename);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                // Пропускаем (или можно вернуть ошибку, если это недопустимо в принципе)
                // Для безопасности, если ".." встречается где-либо, лучше вернуть ошибку
                if($part === '..') {
                    return json_encode(['status' => 'error', 'message' => "Path cannot contain '..' components."]);
                }
                continue;
            }
            // Можно добавить проверку на недопустимые символы в именах файлов/директорий
            if (preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $part)) {
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
        // realpath поможет разрешить все, но он требует существования пути до последнего компонента
        $resolvedBase = realpath($basePath);
        if ($resolvedBase === false) {
            return json_encode(['status' => 'error', 'message' => 'Could not resolve base project path.']);
        }
        // Для $fullPath, realpath может не сработать, если путь/файл еще не существует.
        // Поэтому сравниваем строки, предварительно нормализовав их.
        $normalizedFullPath = str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $fullPath);
        if (strpos($normalizedFullPath, $resolvedBase . DIRECTORY_SEPARATOR) !== 0 && $normalizedFullPath !== $resolvedBase) { // если сам $resolvedBase
            return json_encode(['status' => 'error', 'message' => "Security: Final path '{$safeFilename}' is outside the project directory."]);
        }


        $fileDir = dirname($fullPath);
        if (!is_dir($fileDir)) {
            if (!mkdir($fileDir, 0755, true) && !is_dir($fileDir)) {
                return json_encode(['status' => 'error', 'message' => "Failed to create directory: {$fileDir}."]);
            }
        }

        if (!is_writable($fileDir)) {
            return json_encode(['status' => 'error', 'message' => "Directory is not writable: {$fileDir}."]);
        }

        try {
            // Проверка, не пытаемся ли мы перезаписать существующую директорию
            if (is_dir($fullPath)) {
                return json_encode(['status' => 'error', 'message' => "Cannot create file. A directory already exists at '{$safeFilename}'."]);
            }

            $bytesWritten = file_put_contents($fullPath, $content);
            if ($bytesWritten === false) {
                return json_encode(['status' => 'error', 'message' => "Failed to write to file '{$safeFilename}'."]);
            }
            return json_encode(['status' => 'success', 'message' => "File '{$safeFilename}' created/updated successfully."]);
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
        return true; // Explicitly stating, though it's the default from AbstractTool
    }

    /**
     * Returns a string containing multiple few-shot examples for the create_file tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Create a new file named 'report.txt' in the 'docs' folder with the content 'Monthly sales report.'.",
            "Make a new file called 'config.json' in the root directory and add this JSON content: {\"api_key\": \"YOUR_API_KEY\"}.",
            "Please create a simple HTML file named 'index.html' in the 'public' directory containing just '<h1>Hello World!</h1>'.",
            "Create an empty file named 'temp/placeholder' to mark a directory."
        ];

        return implode("\n", $examples);
    }
}
