<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Event;

use Romanzaycev\Vibe\Model\ToolCall as ParsedToolCall;
use Symfony\Contracts\EventDispatcher\Event;

class ToolCallRequestedEvent extends Event
{
    private ParsedToolCall $toolCall;
    private ?string $executionResultJson = null;
    private ?string $contextId;

    public function __construct(ParsedToolCall $toolCall, ?string $contextId = null)
    {
        $this->toolCall = $toolCall;
        $this->contextId = $contextId;
    }

    public function getToolCall(): ParsedToolCall
    {
        return $this->toolCall;
    }

    public function setExecutionResult(string $jsonResult): void
    {
        $this->executionResultJson = $jsonResult;
    }

    public function getExecutionResult(): ?string
    {
        return $this->executionResultJson;
    }

    public function getContextId(): ?string
    {
        return $this->contextId;
    }
}
