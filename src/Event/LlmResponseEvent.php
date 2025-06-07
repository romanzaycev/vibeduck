<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Event;

use Romanzaycev\Vibe\Model\CallResult;
use Symfony\Contracts\EventDispatcher\Event;

class LlmResponseEvent extends Event
{
    private CallResult $callResult;
    private ?string $contextId;
    private bool $isFinalResponse; // True если это окончательный ответ (нет tool_calls, или tool_calls обработаны)

    public function __construct(CallResult $callResult, ?string $contextId = null, bool $isFinalResponse = true)
    {
        $this->callResult = $callResult;
        $this->contextId = $contextId;
        $this->isFinalResponse = $isFinalResponse;
    }

    public function getCallResult(): CallResult
    {
        return $this->callResult;
    }

    public function getContextId(): ?string
    {
        return $this->contextId;
    }

    public function isFinalResponse(): bool
    {
        return $this->isFinalResponse;
    }
}
