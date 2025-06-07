<?php declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Romanzaycev\Vibe\Command\ClearHistoryCommand;
use Romanzaycev\Vibe\Contract\LlmClientInterface;
use Romanzaycev\Vibe\Service\LazyOpenAiClientProxy;
use Romanzaycev\Vibe\Service\SystemPromptPreprocessor;
use Romanzaycev\Vibe\Service\ToolRegistry;
use Romanzaycev\Vibe\Storage\Storage;

$tools = require __DIR__ . "/tools.php";

return [
    Symfony\Component\EventDispatcher\EventDispatcherInterface::class => \DI\autowire(\Symfony\Component\EventDispatcher\EventDispatcher::class),
    LlmClientInterface::class => DI\autowire(LazyOpenAiClientProxy::class),

    ClearHistoryCommand::class => function (ContainerInterface $container) {
        return new ClearHistoryCommand($container->get(Storage::class)->getCollection('project_history'));
    },

    ToolRegistry::class => function (ContainerInterface $c) use ($tools) {
        return new ToolRegistry(array_map(
            static fn (string $toolClass) => $c->get($toolClass),
            $tools,
        ));
    },

    SystemPromptPreprocessor::class => function (ContainerInterface $container) {
        return new SystemPromptPreprocessor(
            $container->get(ToolRegistry::class),
            VIBE_DIR . "/system_prompt.txt",
        );
    },
];
