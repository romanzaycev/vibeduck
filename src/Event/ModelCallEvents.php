<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Event;

final class ModelCallEvents
{
    /**
     * Dispatched before the call to the LLM API is made.
     * Allows modification of messages or tools.
     * @Event("Romanzaycev\Vibe\Event\BeforeLlmCallEvent")
     */
    public const BEFORE_LLM_CALL = 'vibeduck.model_call.before_llm_call';

    /**
     * Dispatched after a response (potentially intermediate or final) is received from the LLM.
     * @Event("Romanzaycev\Vibe\Event\LlmResponseEvent")
     */
    public const LLM_RESPONSE_RECEIVED = 'vibeduck.model_call.llm_response_received';

    /**
     * Dispatched when a tool call is requested by the LLM.
     * Allows listeners to execute the tool and provide a result.
     * @Event("Romanzaycev\Vibe\Event\ToolCallRequestedEvent")
     */
    public const TOOL_CALL_REQUESTED = 'vibeduck.model_call.tool_call_requested';
}
