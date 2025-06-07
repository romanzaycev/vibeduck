<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;

class GitHistoryTool extends AbstractTool
{
    public function __construct(
        private ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_history';
    }

    public function getDescription(): string
    {
        return 'Retrieves the Git commit history for a given path with an optional limit.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional. The path within the repository for which to show history. Defaults to the project root (".").',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional. The maximum number of commits to retrieve. Defaults to 10.',
                    'default' => 10,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * {@inheritdoc}
     * This tool is read-only, so it does not require confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return false;
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '.';
        $limit = $arguments['limit'] ?? 10;

        // Basic sanitization for path to prevent command injection, though ShellCommandRunner should also protect
        // We'll allow relative paths including '.', but disallow '..'
        $safePath = $path;
        if (str_contains($safePath, '..')) {
            return json_encode(['status' => 'error', 'message' => 'Path cannot contain \'..\' components.']);
        }
        // Prevent shell-specific characters that could be used for injection
        if (preg_match('/[<>|;&`$()#]/', $safePath)) {
             return json_encode(['status' => 'error', 'message' => 'Path contains potentially unsafe characters.']);
        }

        // Ensure limit is a positive integer
        $safeLimit = max(1, (int) $limit);

        // Передаем команду git отдельно, а аргументы в массиве
        $result = $this->shellCommandRunner->run(
            'git',
            [
                'log',
                '--no-merges',
                '--pretty=format:%H %s', // Убираем внешние кавычки, ShellCommandRunner их добавит
                '-n',
                (string) $safeLimit, // Передаем лимит как строку
                '--',
                $safePath // Передаем путь, ShellCommandRunner его экранирует
            ]
        );

        if ($result['status'] === 'success') {
            // Parse the output into a more structured format if needed, or return raw lines
            // For now, let's return the raw lines and message
            $historyLines = explode("\n", trim($result['stdout'])); // Используем stdout вместо output
            $history = [];
            foreach($historyLines as $line) {
                if(empty($line)) continue;
                // Assuming format: <hash> <subject>
                $parts = explode(' ', $line, 2);
                $history[] = [
                    'hash' => $parts[0] ?? '',
                    'subject' => $parts[1] ?? $line
                ];
            }
            return json_encode([
                'status' => 'success',
                'message' => 'Git history retrieved successfully.',
                'history' => $history,
                'command' => $result['command'] ?? 'git log ...' // Добавляем выполненную команду для отладки
            ]);
        } else {
            // Handle potential errors, e.g., not a git repository, invalid path
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to retrieve Git history: ' . ($result['stderr'] ?? $result['error_message'] ?? 'Unknown error.'), // Включаем stderr и error_message
                'command' => $result['command'] ?? 'git log ...', // Включаем выполненную команду для отладки
                'stdout' => $result['stdout'] ?? '', // Включаем stdout для отладки
                'stderr' => $result['stderr'] ?? '', // Включаем stderr для отладки
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_history tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Show me the last 5 commits in the project.",
            "What were the recent changes in the 'src/Service' directory?",
            "Can you list the commit history for the file 'config/app.yaml'?",
            "I need to see the last 10 commits that affected anything in the 'tests' folder.",
            "What's the commit history for the entire project?"
        ];

        return implode("\n", $examples);
    }
}
