<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;

class GitPullTool extends AbstractTool
{
    public function __construct(
        private ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_pull';
    }

    public function getDescription(): string
    {
        return 'Fetches from and integrates with another repository or a local branch.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'remote' => [
                    'type' => 'string',
                    'description' => 'The name of the remote repository (e.g., "origin").',
                    'nullable' => true,
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'The name of the branch to pull.',
                    'nullable' => true,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * {@inheritdoc}
     * This tool modifies the working directory, so it requires confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $commandArguments = ['pull'];

        $remote = $arguments['remote'] ?? null;
        $branch = $arguments['branch'] ?? null;

        if ($remote !== null) {
            $commandArguments[] = $remote;
        }

        if ($branch !== null) {
            $commandArguments[] = $branch;
        }

        // Execute the git pull command
        $result = $this->shellCommandRunner->run('git', $commandArguments);

        if ($result['status'] === 'success') {
            // Return the stdout of the command
            return json_encode([
                'status' => 'success',
                'message' => 'Changes pulled successfully.',
                'output' => $result['stdout'],
                'command' => $result['command'] ?? ('git pull ' . implode(' ', $commandArguments))
            ]);
        } else {
            // Handle errors
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to pull changes: ' . ($result['stderr'] ?? $result['error_message'] ?? 'Unknown error.'),
                'command' => $result['command'] ?? ('git pull ' . implode(' ', $commandArguments)),
                'stdout' => $result['stdout'] ?? '',
                'stderr' => $result['stderr'] ?? '',
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_pull tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Pull the latest changes from the remote repository.",
            "Perform a git pull.",
            "Fetch and merge changes from origin main.",
            "Pull changes from the 'develop' branch on 'origin'.",
            "Update the current branch with changes from the remote.",
            "Get the latest updates from the default remote and branch.",
            "Synchronize the current branch with its upstream.",
            "Pull from 'my_remote' on branch 'feature/new-feature'.",
            "Fetch and integrate changes from 'upstream' main.",
        ];

        return implode("\n", $examples);
    }
}
