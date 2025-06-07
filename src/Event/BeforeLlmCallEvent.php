<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Event;

use Symfony\Contracts\EventDispatcher\Event;

class BeforeLlmCallEvent extends Event
{
    private array $messages;
    private array $toolDefinitions; // В текущем ModelCaller это будет всегда []

    public function __construct(array $messages, array $toolDefinitions)
    {
        $this->messages = $messages;
        $this->toolDefinitions = $toolDefinitions;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function getToolDefinitions(): array
    {
        return $this->toolDefinitions;
    }

    // Если мы захотим разрешить подписчикам изменять toolDefinitions
    // public function setToolDefinitions(array $toolDefinitions): void
    // {
    //     $this->toolDefinitions = $toolDefinitions;
    // }
}
