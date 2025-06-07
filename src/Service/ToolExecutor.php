<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use Romanzaycev\Vibe\Model\ToolCall as ParsedToolCall;

class ToolExecutor
{
    private ToolRegistry $toolRegistry;

    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
    }

    public function execute(ParsedToolCall $parsedToolCall): string
    {
        $tool = $this->toolRegistry->getTool($parsedToolCall->functionName);

        if (!$tool) {
            return json_encode([
                'status' => 'error',
                'message' => "Tool '{$parsedToolCall->functionName}' not found or not registered."
            ]);
        }

        try {
            return $tool->execute($parsedToolCall->functionArguments);
        } catch (\Exception $e) {
            error_log("Error executing tool '{$parsedToolCall->functionName}': " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return json_encode([
                'status' => 'error',
                'message' => "Error executing tool '{$parsedToolCall->functionName}': " . $e->getMessage()
            ]);
        }
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }
}
