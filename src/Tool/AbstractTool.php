<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Tool;

use Romanzaycev\Vibe\Contract\ToolInterface;

abstract class AbstractTool implements ToolInterface
{
    public function getDefinitionForLlm(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParametersDefinition(),
            ],
        ];
    }

    /**
     * By default, all tools require confirmation.
     * Override this in specific tool implementations if confirmation is not needed by default.
     *
     * {@inheritdoc}
     */
    public function requiresConfirmationByDefault(): bool
    {
        return true;
    }

    public function getFewShotExample(): string
    {
        return '';
    }
}
