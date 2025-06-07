<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;

class GitStatusTool extends AbstractTool
{
    public function __construct(
        private ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_status';
    }

    public function getDescription(): string

    {
        return 'Shows the current status of the Git repository.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // Git status typically doesn't need a path argument for the whole repo status,
                // but we could add it if we wanted to show status of a specific path.
                // For now, let's keep it simple and assume whole repo status.
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
        // Execute the git status command
        $result = $this->shellCommandRunner->run('git', ['status']);

        if ($result['status'] === 'success') {
            // Return the stdout of the command
            return json_encode([
                'status' => 'success',
                'message' => 'Git status retrieved successfully.',
                'output' => $result['stdout'],
                'command' => $result['command'] ?? 'git status' // Добавляем выполненную команду для отладки
            ]);
        } else {
            // Handle errors
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to retrieve Git status: ' . ($result['stderr'] ?? $result['error_message'] ?? 'Unknown error.'),
                'command' => $result['command'] ?? 'git status', // Включаем выполненную команду для отладки
                'stdout' => $result['stdout'] ?? '', // Включаем stdout для отладки
                'stderr' => $result['stderr'] ?? '', // Включаем stderr для отладки
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_status tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Show me the current status of the repository.",
            "What is the git status?",
            "Are there any pending changes?",
            "Check the status of the git repository."
        ];

        return implode("\n", $examples);
    }
}
