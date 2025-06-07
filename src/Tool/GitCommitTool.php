<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;
use Romanzaycev\Vibe\Tool\AbstractTool;

class GitCommitTool extends AbstractTool
{
    public function __construct(
        private ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_commit';
    }

    public function getDescription(): string
    {
        return 'Commits changes to the Git repository.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'The commit message.',
                ],
                'files' => [
                    'type' => 'array',
                    'description' => 'List of files to commit. If not provided, all staged changes will be committed.',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Automatically stage files that have been modified and deleted.',
                ],
                'amend' => [
                    'type' => 'boolean',
                    'description' => 'Amend the last commit.',
                ],
            ],
            'required' => ['message'],
        ];
    }

    /**
     * {@inheritdoc}
     * This tool modifies the repository, so it requires confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $message = $arguments['message'] ?? null;
        $files = $arguments['files'] ?? [];
        $all = $arguments['all'] ?? false;
        $amend = $arguments['amend'] ?? false;

        if ($message === null) {
             return json_encode([
                 'status' => 'error',
                 'message' => 'Commit message is required.',
             ]);
        }

        if (!empty($files) && $all) {
            return json_encode([
                'status' => 'error',
                'message' => 'Cannot use files and all options together.',
            ]);
        }

        // If specific files are provided or --all is used, stage them first
        if (!empty($files) || $all) {
            $addArgs = [];
            if ($all) {
                $addArgs[] = '-A';
            } else {
                $addArgs = $files;
            }

            $addResult = $this->shellCommandRunner->run('git', array_merge(['add'], $addArgs));

            if ($addResult['status'] !== 'success') {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Failed to add files to staging area: ' . ($addResult['stderr'] ?? $addResult['error_message'] ?? 'Unknown error.'),
                    'command' => $addResult['command'] ?? 'git add', // Включаем выполненную команду для отладки
                    'stdout' => $addResult['stdout'] ?? '', // Включаем stdout для отладки
                    'stderr' => $addResult['stderr'] ?? '', // Включаем stderr для отладки
                ]);
            }
        }

        $commitArgs = ['-m', $message];
        if ($amend) {
            $commitArgs[] = '--amend';
        }

        $commitResult = $this->shellCommandRunner->run('git', array_merge(['commit'], $commitArgs));

        if ($commitResult['status'] === 'success') {
            return json_encode([
                'status' => 'success',
                'message' => 'Changes committed successfully.',
                'output' => $commitResult['stdout'] . $commitResult['stderr'],
                'command' => $commitResult['command'] ?? 'git commit' // Добавляем выполненную команду для отладки
            ]);
        } else {
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to commit changes: ' . ($commitResult['stderr'] ?? $commitResult['error_message'] ?? 'Unknown error.'),
                'command' => $commitResult['command'] ?? 'git commit', // Включаем выполненную команду для отладки
                'stdout' => $commitResult['stdout'] ?? '', // Включаем stdout для отладки
                'stderr' => $commitResult['stderr'] ?? '', // Включаем stderr для отладки
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_commit tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Commit all staged changes with message 'Initial commit'.",
            "Commit file src/Service/MyService.php with message 'Add MyService'.",
            "Amend the last commit with message 'Fix typo in message'.",
            "Commit all changes with message 'Implement feature X'. (using --all)"
        ];

        return implode("\n", $examples);
    }
}
