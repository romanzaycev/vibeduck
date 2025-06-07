<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Contract;

// Мы будем использовать конкретный тип возвращаемого значения от OpenAI SDK для удобства
// и чтобы не переопределять все их интерфейсы.
use OpenAI\Resources\Chat;

/**
 * Interface for a client that interacts with a Language Model,
 * abstracting the underlying SDK.
 */
interface LlmClientInterface
{
    /**
     * Access the chat completions resource.
     *
     * @return Chat The chat resource instance.
     */
    public function chat(): Chat;

    // Если в будущем понадобятся другие ресурсы SDK, их можно добавить сюда, например:
    // public function embeddings(): \OpenAI\Resources\Embeddings;
    // public function models(): \OpenAI\Resources\Models;
}
