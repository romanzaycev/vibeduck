<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use Romanzaycev\Vibe\Contract\ToolInterface;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<ToolInterface> $toolsToRegister
     */
    public function __construct(iterable $toolsToRegister = [])
    {
        foreach ($toolsToRegister as $tool) {
            $this->registerTool($tool);
        }
    }

    public function registerTool(ToolInterface $tool): void
    {
        if (isset($this->tools[$tool->getName()])) {
            throw new \InvalidArgumentException("Tool with name '{$tool->getName()}' is already registered.");
        }
        $this->tools[$tool->getName()] = $tool;
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getAllTools(): array
    {
        return $this->tools;
    }

    public function getAllToolDefinitionsForLlm(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = $tool->getDefinitionForLlm();
        }
        return $definitions;
    }
}
