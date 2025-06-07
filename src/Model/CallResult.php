<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Model;

class CallResult
{
    private ?string $textContent;
    /** @var ToolCall[] */
    private array $toolCalls;
    private ?array $rawResponse; // Для отладки

    /**
     * @param string|null $textContent
     * @param ToolCall[] $toolCalls
     * @param array|null $rawResponse
     */
    public function __construct(?string $textContent, array $toolCalls = [], ?array $rawResponse = null)
    {
        $this->textContent = $textContent;
        $this->toolCalls = $toolCalls;
        $this->rawResponse = $rawResponse;
    }

    public function hasTextContent(): bool
    {
        return $this->textContent !== null && $this->textContent !== '';
    }

    public function getTextContent(): ?string
    {
        return $this->textContent;
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * @return ToolCall[]
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }
}
