<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

class FindTextInFilesTool extends AbstractTool
{
    public function getName(): string
    {
        return 'find_text_in_files';
    }

    public function getDescription(): string
    {
        return 'Searches for a given text pattern (or regular expression) in files within the project. Returns a list of matching lines with optional context.';
    }

    public function getParametersDefinition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The text pattern or regular expression to search for. For regex, use PCRE syntax (e.g., "/pattern/i").',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional. The relative directory or specific file to search in. Defaults to the project root ("."). Use forward slashes.',
                ],
                'file_mask' => [
                    'type' => 'string',
                    'description' => 'Optional. A glob pattern to filter files by (e.g., "*.php", "src/*.js"). Defaults to "*" (all files).',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Optional. Whether to search recursively in subdirectories. Defaults to true.',
                ],
                'ignore_case' => [
                    'type' => 'boolean',
                    'description' => 'Optional. Perform a case-insensitive search. Defaults to false.',
                ],
                'context_lines' => [
                    'type' => 'integer',
                    'description' => 'Optional. Number of lines of context to show before and after the matching line. Defaults to 0.',
                    'minimum' => 0,
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function requiresConfirmationByDefault(): bool
    {
        return false;
    }

    public function execute(array $parameters): string
    {
        $pattern = $parameters['pattern'];
        $userPath = $parameters['path'] ?? '.';
        $fileMask = $parameters['file_mask'] ?? '*';
        $recursive = $parameters['recursive'] ?? true;
        $ignoreCase = $parameters['ignore_case'] ?? false;
        $contextLines = $parameters['context_lines'] ?? 0;
        if (!is_int($contextLines) || $contextLines < 0) {
            $contextLines = 0;
        }

        $projectRoot = realpath(getcwd());
        if ($projectRoot === false) {
            return json_encode(['status' => 'error', 'message' => 'Could not determine project root directory.']);
        }

        $isRegex = preg_match('/^\/.+\/[a-zA-Z]*$/', $pattern) === 1;
        $finalPattern = $pattern;

        if ($isRegex) {
            $patternFlags = substr($pattern, strrpos($pattern, '/') + 1);
            // Add 'u' flag for UTF-8 if not present
            if (strpos($patternFlags, 'u') === false) {
                $finalPattern = substr($finalPattern, 0, strrpos($finalPattern, '/')) . '/' . $patternFlags . 'u';
                $patternFlags .= 'u'; // update flags for next check
            }
            // Add 'i' modifier if ignoreCase is true and 'i' is not already in the pattern flags
            if ($ignoreCase && strpos($patternFlags, 'i') === false) {
                $finalPattern = substr($finalPattern, 0, strrpos($finalPattern, '/')) . '/' . $patternFlags . 'i';
            }
        } elseif ($ignoreCase) {
            // For plain text, ignore_case means we'll use stripos
        }
        
        $finder = new Finder();
        $finder->files()->in($projectRoot);

        // Handle userPath: it can be a directory or a file relative to projectRoot
        $searchPathNormalized = str_replace('/', DIRECTORY_SEPARATOR, $userPath);
        $absoluteSearchPath = realpath($projectRoot . DIRECTORY_SEPARATOR . $searchPathNormalized);

        if ($absoluteSearchPath === false) {
             // If userPath is not an existing absolute path, treat it as relative to project root for Finder
            if (is_file($projectRoot . DIRECTORY_SEPARATOR . $searchPathNormalized)) {
                 $finder->path(str_replace(DIRECTORY_SEPARATOR, '/', $searchPathNormalized)); // Finder expects forward slashes for path()
                 if (!$recursive) { // If it's a specific file, depth doesn't really apply but good to be consistent
                    $finder->depth('== 0');
                 }
            } elseif (is_dir($projectRoot . DIRECTORY_SEPARATOR . $searchPathNormalized)) {
                 $finder->path(str_replace(DIRECTORY_SEPARATOR, '/', $searchPathNormalized));
                 if (!$recursive) {
                    $finder->depth('== 0');
                 }
            } else {
                 return json_encode(['status' => 'error', 'message' => "Search path '{$userPath}' not found within the project."]);
            }
        } else {
            // If $absoluteSearchPath is valid, check if it's within $projectRoot
            if (strpos($absoluteSearchPath, $projectRoot) !== 0) {
                 return json_encode(['status' => 'error', 'message' => "Security: Search path '{$userPath}' is outside the project directory."]);
            }
            if (is_file($absoluteSearchPath)) {
                $relativePathForFinder = substr($absoluteSearchPath, strlen($projectRoot) + 1);
                $finder->path(str_replace(DIRECTORY_SEPARATOR, '/', $relativePathForFinder));
                 if (!$recursive) {
                    $finder->depth('== 0');
                 }
            } elseif (is_dir($absoluteSearchPath)) {
                $relativePathForFinder = substr($absoluteSearchPath, strlen($projectRoot) + 1);
                 if (!empty($relativePathForFinder)) { // if it's not the project root itself
                    $finder->path(str_replace(DIRECTORY_SEPARATOR, '/', $relativePathForFinder));
                 } // else search in projectRoot, which is default for finder->in()
                 if (!$recursive) {
                    $finder->depth('== 0');
                 }
            }
        }


        if ($fileMask !== '*') {
            $finder->name($fileMask);
        }
        
        $results = [];
        $matchCount = 0;

        try {
            foreach ($finder as $file) { // $file is a SplFileInfo object
                if (!$file->isReadable()) {
                    continue;
                }

                $fileContentLines = file($file->getRealPath());
                if ($fileContentLines === false) {
                    continue;
                }

                foreach ($fileContentLines as $lineNumberZeroBased => $lineContent) {
                    $lineContent = rtrim($lineContent, "\r\n");
                    $matchFound = false;
                    $regexMatchesArr = [];

                    if ($isRegex) {
                        if (@preg_match($finalPattern, $lineContent, $regexMatchesArr) === 1) {
                            $matchFound = true;
                        } elseif (preg_last_error() !== PREG_NO_ERROR) {
                            return json_encode(['status' => 'error', 'message' => 'Invalid regex pattern: ' . $pattern . '. Error: ' . preg_last_error_msg()]);
                        }
                    } else {
                        if ($ignoreCase) {
                            if (stripos($lineContent, $finalPattern) !== false) {
                                $matchFound = true;
                            }
                        } else {
                            if (str_contains($lineContent, $finalPattern)) {
                                $matchFound = true;
                            }
                        }
                    }

                    if ($matchFound) {
                        $matchCount++;
                        $resultItem = [
                            'filename' => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()),
                            'line_number' => $lineNumberZeroBased + 1,
                            'line_content' => $lineContent,
                        ];

                        if ($contextLines > 0) {
                            $contextBefore = [];
                            for ($i = max(0, $lineNumberZeroBased - $contextLines); $i < $lineNumberZeroBased; $i++) {
                                $contextBefore[] = rtrim($fileContentLines[$i], "\r\n");
                            }
                            $resultItem['context_before'] = $contextBefore;

                            $contextAfter = [];
                            for ($i = $lineNumberZeroBased + 1; $i < min(count($fileContentLines), $lineNumberZeroBased + 1 + $contextLines); $i++) {
                                $contextAfter[] = rtrim($fileContentLines[$i], "\r\n");
                            }
                            $resultItem['context_after'] = $contextAfter;
                        }

                        if ($isRegex && !empty($regexMatchesArr)) {
                            $resultItem['matches'] = $regexMatchesArr;
                        }
                        $results[] = $resultItem;
                    }
                }
            }
        } catch (DirectoryNotFoundException $e) {
            return json_encode(['status' => 'error', 'message' => "Search directory not found: " . $e->getMessage()]);
        } catch (\Exception $e) {
            return json_encode(['status' => 'error', 'message' => "An unexpected error occurred during search: " . $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode([
                'status' => 'success',
                'message' => "No matches found for pattern \"{$pattern}\" in path \"{$userPath}\" with mask \"{$fileMask}\".",
                'results' => []
            ]);
        }

        return json_encode([
            'status' => 'success',
            'message' => "Found {$matchCount} match(es).",
            'results' => $results
        ]);
    }

    /**
     * Returns a string containing multiple few-shot examples for the find_text_in_files tool,
     * separated by newlines.
     *
     * @return string
     */
    public function getFewShotExamples(): string
    {
        $examples = [
            "Find all occurrences of the word 'error' in the project.",
            "Search for 'TODO' comments in all PHP files.",
            "Find the definition of the 'handleRequest' method in the 'src/Controller' directory.",
            "Search for the pattern '/^\s*public function .*\(/m' in all files with a .php extension.",
            "Find the phrase 'database connection' in configuration files (ending with .yaml or .yml), ignoring case.",
            "Search for the word 'warning' in log files (.log) and show 2 lines of context around each match.",
            "Find all lines containing 'use Symfony\Component\HttpFoundation' in the 'src' directory.",
            "Search for the text 'deprecated' in the entire codebase.",
            "Find the string 'API_KEY' in files named '.env', without searching recursively.",
            "Search for the regular expression '/\$[a-zA-Z0-9_]+/g' (PHP variables) in all PHP files in the 'src' directory."
        ];

        return implode("\n", $examples);
    }
}
