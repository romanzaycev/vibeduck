<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

class SystemPromptPreprocessor
{
    private ToolRegistry $toolRegistry;
    private string $templateFilePath;
    private ?string $cache = null;

    public function __construct(
        ToolRegistry $toolRegistry,
        string $templateFilePath,
    )
    {
        $this->toolRegistry = $toolRegistry;
        $this->templateFilePath = $templateFilePath;
    }

    public function process(): string
    {
        if ($this->cache) {
            return $this->cache;
        }

        if (!file_exists($this->templateFilePath)) {
            throw new \RuntimeException(sprintf('System prompt template file not found: %s', $this->templateFilePath));
        }

        $template = file_get_contents($this->templateFilePath);

        if ($template === false) {
            throw new \RuntimeException(sprintf('Failed to read system prompt template file: %s', $this->templateFilePath));
        }

        $toolsList = $this->getToolsList();
        $toolsShots = $this->getToolsFewShotExamples();

        $processedPrompt = str_replace('%tools_list%', $toolsList, $template);
        $processedPrompt = str_replace('%tools_shots%', $toolsShots, $processedPrompt);
        $this->cache = $processedPrompt;

        return $processedPrompt;
    }

    private function getToolsList(): string
    {
        $tools = $this->toolRegistry->getAllTools();
        $toolDefinitions = [];

        foreach ($tools as $tool) {
            $toolDefinitions[] = sprintf(
                "*   `%s`: %s.",
                $tool->getName(),
                $tool->getDescription(),
            );
        }

        return implode("\n", $toolDefinitions);
    }

    private function getToolsFewShotExamples(): string
    {
        $tools = $this->toolRegistry->getAllTools();
        $fewShotExamples = [];

        foreach ($tools as $tool) {
            $examples = $tool->getFewShotExamples();
            if (!empty($examples)) {
                $fewShotExamples[] = sprintf(
                    "Tool: %s\nExamples:\n%s\n",
                    $tool->getName(),
                    $examples,
                );
            }
        }

        return implode("\n", $fewShotExamples);
    }
}
