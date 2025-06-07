<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use RuntimeException;

class ShellCommandRunner
{
    private string $projectRoot;
    private int $defaultTimeoutSeconds;

    public function __construct(string $projectRoot = null, int $defaultTimeoutSeconds = 60)
    {
        $this->projectRoot = $projectRoot ?? getcwd();
        if ($this->projectRoot === false || !is_dir($this->projectRoot)) {
            throw new RuntimeException("Invalid project root directory specified or cannot get CWD.");
        }
        $this->projectRoot = realpath($this->projectRoot); // Получаем канонический абсолютный путь
        if ($this->projectRoot === false) {
            throw new RuntimeException("Cannot resolve real path for project root.");
        }
        
        $this->defaultTimeoutSeconds = $defaultTimeoutSeconds;
    }

    /**
     * Executes a shell command.
     *
     * @param string $command The command to execute (without arguments).
     * @param array<string> $arguments An array of arguments for the command. Each argument will be escaped.
     * @param string|null $stdinContent Content to pass to the command's standard input.
     * @param int|null $timeoutSeconds Timeout for the command execution. Null uses default.
     * @param array<string, string>|null $env Additional environment variables. Null uses current environment.
     *
     * @return array{status: string, exit_code: int|null, stdout: string, stderr: string, error_message?: string}
     *          status: 'success' or 'error'.
     *          exit_code: The command's exit code (null if process couldn't be started or timed out).
     *          stdout: Standard output from the command.
     *          stderr: Standard error output from the command.
     *          error_message: Optional message in case of runner internal errors.
     */
    public function run(
        string $command,
        array $arguments = [],
        string $stdinContent = null,
        int $timeoutSeconds = null,
        array $env = null
    ): array {
        $timeout = $timeoutSeconds ?? $this->defaultTimeoutSeconds;

        // Sanitize the command itself (though ideally it's a fixed path or trusted command name)
        $escapedCommand = escapeshellcmd($command);
        if ($escapedCommand !== $command && trim($command) !== '') {
             // If escapeshellcmd changed the command, it might contain problematic characters.
             // Allow empty command string for cases like `git commit -m ""` where command is part of args.
             // However, a command like `my-util` should not be changed.
             // This is a basic check. More robust validation might be needed depending on usage.
            if (strpbrk($command, ';&|`$()#') !== false) { // Check for common shell metacharacters
                 return [
                    'status' => 'error',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '',
                    'error_message' => "Command '''{$command}''' contains potentially unsafe characters after sanitization attempt."
                ];
            }
            // If it's just a path being normalized, it might be okay, but be cautious.
            // For now, we'll proceed with the original command if it was not empty and escapeshellcmd didn't strip it entirely.
            // If escapeshellcmd *emptied* a non-empty command, that's an error.
            if (trim($command) !== '' && trim($escapedCommand) === '') {
                 return [
                    'status' => 'error',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '',
                    'error_message' => "Command '''{$command}''' was sanitized to an empty string."
                ];
            }
        }


        $fullCommand = $command; // Use original command, arguments will be escaped.
        foreach ($arguments as $arg) {
            $fullCommand .= ' ' . escapeshellarg($arg);
        }

        $descriptorspec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $pipes = [];
        // Execute the command within the project root directory.
        // Environment variables: null for current env, empty array for no vars (except system-defined), or specific vars.
        $process = proc_open($fullCommand, $descriptorspec, $pipes, $this->projectRoot, $env);

        if (!is_resource($process)) {
            return [
                'status' => 'error',
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'error_message' => "Failed to open process for command: {$command}"
            ];
        }

        // Set streams to non-blocking to handle timeout
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if ($stdinContent !== null) {
            fwrite($pipes[0], $stdinContent);
            // Closing stdin pipe after writing is important for some commands to proceed.
            // However, if the command is interactive or expects more stdin later,
            // this might need to be handled differently (e.g. keep it open or feed in chunks).
            // For typical non-interactive use like `patch < content`, closing is fine.
        }
        // Close stdin for the child process from our side *after* writing.
        // If no stdinContent, it's closed immediately.
        fclose($pipes[0]);


        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            // Check process status without waiting indefinitely
            $status = proc_get_status($process);
            if (!$status['running']) {
                break; // Process has terminated
            }

            if (microtime(true) - $startTime > $timeout) {
                proc_terminate($process, 9); // SIGKILL
                // Give a moment for termination
                usleep(100000); // 100ms
                $this->closePipes($pipes); // Close our ends
                return [
                    'status' => 'error',
                    'exit_code' => null, // Or a specific code for timeout like -1
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'error_message' => "Command timed out after {$timeout} seconds."
                ];
            }
            
            // Wait for stream activity with a timeout to allow checking proc_get_status
            $numChangedStreams = @stream_select($read, $write, $except, 0, 200000); // 0.2 sec timeout

            if ($numChangedStreams === false) {
                // Error in stream_select
                break;
            }
            
            if ($numChangedStreams > 0) {
                $outChunk = fread($pipes[1], 8192);
                if ($outChunk !== false && $outChunk !== '') {
                    $stdout .= $outChunk;
                }
                $errChunk = fread($pipes[2], 8192);
                 if ($errChunk !== false && $errChunk !== '') {
                    $stderr .= $errChunk;
                }
            }
            
            // If both stdout and stderr pipes are EOF, and process is no longer running, we can break.
            // feof might not be immediately true after process exits, so rely on proc_get_status first.
            if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        
        // Ensure all output is read after process termination
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        $this->closePipes($pipes);
        $exitCode = $status['running'] ? proc_close($process) : $status['exitcode']; // If still running, close and get code.

        return [
            'status' => ($exitCode === 0) ? 'success' : 'error',
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function closePipes(array &$pipes): void
    {
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }
}
