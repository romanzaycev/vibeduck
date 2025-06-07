<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;

class GitAddTool extends AbstractTool
{
    public function __construct(
        private readonly ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_add';
    }

    public function getDescription(): string
    {
        return 'Adds file changes to the Git index.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'description' => 'List of files or directories to add.',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Add all modified and deleted tracked files (equivalent to git add -A). Cannot be used with files.',
                ],
            ],
            'required' => [], // Either files or all should be provided, handled in execute
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
        $files = $arguments['files'] ?? [];
        $all = $arguments['all'] ?? false;

        if (empty($files) && !$all) {
            return json_encode([
                'status' => 'error',
                'message' => 'Either files or the "all" flag must be provided.',
            ]);
        }

         if (!empty($files) && $all) {
            return json_encode([
                'status' => 'error',
                'message' => 'Cannot use files and all options together.',
            ]);
        }

        $addArgs = [];
        if ($all) {
            $addArgs[] = '-A';
        } else {
            $addArgs = $files;
        }

        $result = $this->shellCommandRunner->run('git', array_merge(['add'], $addArgs));

        if ($result['status'] === 'success') {
            return json_encode([
                'status' => 'success',
                'message' => 'Changes added to index successfully.',
                'output' => $result['stdout'] . $result['stderr'],
                'command' => $result['command'] ?? 'git add'
            ]);
        } else {
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to add changes to index: ' . ($result['stderr'] ?? $result['error_message'] ?? 'Unknown error.'),
                'command' => $result['command'] ?? 'git add',
                'stdout' => $result['stdout'] ?? '',
                'stderr' => $result['stderr'] ?? '',
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_add tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Add file src/Service/MyService.php to the staging area.",
            "Add all changes in the css directory.",
            "Stage all modified and deleted files.",
            "Add files index.html and style.css."
        ];

        return implode("\n", $examples);
    }
}
