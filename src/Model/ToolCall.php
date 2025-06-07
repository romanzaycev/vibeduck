<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Model;

class ToolCall
{
    public string $id;
    public string $functionName;
    public array $functionArguments;

    public function __construct(string $id, string $functionName, array $functionArguments)
    {
        $this->id = $id;
        $this->functionName = $functionName;
        $this->functionArguments = $functionArguments;
    }
}
