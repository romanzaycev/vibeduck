<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Service\ShellCommandRunner;

class GitPushTool extends AbstractTool
{
    public function __construct(
        private ShellCommandRunner $shellCommandRunner,
    ) {}

    public function getName(): string
    {
        return 'git_push';
    }

    public function getDescription(): string
    {
        return 'Pushes changes to the remote Git repository.';
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
                    'description' => 'The name of the branch to push.',
                    'nullable' => true,
                ],
                'set_upstream' => [
                    'type' => 'boolean',
                    'description' => 'Set the upstream branch.',
                    'nullable' => true,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * {@inheritdoc}
     * This tool modifies the remote repository, so it requires confirmation by default.
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    public function execute(array $arguments): string
    {
        $commandArguments = ['push'];

        $remote = $arguments['remote'] ?? null;
        $branch = $arguments['branch'] ?? null;
        $setUpstream = $arguments['set_upstream'] ?? false;

        if ($setUpstream === true) {
            $commandArguments[] = '--set-upstream';
        }

        if ($remote !== null) {
            $commandArguments[] = $remote;
        }

        if ($branch !== null) {
            $commandArguments[] = $branch;
        }

        // Execute the git push command
        $result = $this->shellCommandRunner->run('git', $commandArguments);

        if ($result['status'] === 'success') {
            // Return the stdout of the command
            return json_encode([
                'status' => 'success',
                'message' => 'Changes pushed to remote successfully.',
                'output' => $result['stdout'],
                'command' => $result['command'] ?? ('git push ' . implode(' ', $commandArguments))
            ]);
        } else {
            // Handle errors
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to push changes: ' . ($result['stderr'] ?? $result['error_message'] ?? 'Unknown error.'),
                'command' => $result['command'] ?? ('git push ' . implode(' ', $commandArguments)),
                'stdout' => $result['stdout'] ?? '',
                'stderr' => $result['stderr'] ?? '',
            ]);
        }
    }

    /**
     * Returns a string containing multiple few-shot examples for the git_push tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Push the current changes to the remote repository.",
            "Perform a git push.",
            "Send the committed changes to the origin.",
        ];

        return implode("\n", $examples);
    }
}
