<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Command;

use Romanzaycev\Vibe\Service\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GlobalConfigCommand extends Command
{
    private Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        parent::__construct('config');
        $this->setDescription('Sets a global configuration value.');
        $this->configuration = $configuration;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The configuration parameter name: ' . PHP_EOL . implode(PHP_EOL, Configuration::ALLOWED_GLOBAL_CONFIG_KEYS))
            ->addArgument('value', InputArgument::REQUIRED, 'The value for the configuration parameter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $paramName = $input->getArgument('name');
        $paramValue = $input->getArgument('value');

        if (!in_array($paramName, Configuration::ALLOWED_GLOBAL_CONFIG_KEYS, true)) {
            $io->error("Parameter '{$paramName}' is not a valid global configuration key or cannot be set using this command.");
            $io->writeln('Allowed keys are: ' . implode(', ', Configuration::ALLOWED_GLOBAL_CONFIG_KEYS));
            return Command::INVALID;
        }

        // Попытка привести значение к ожидаемому типу (очень упрощенно)
        // Например, VIBE_DATA_DIR это строка, OPENAI_API_KEY строка, MODEL_NAME строка
        // Если бы были числовые или булевы, нужна была бы более сложная логика.
        // Для наших текущих ключей все являются строками.

        try {
            $this->configuration->writeToGlobalConfig($paramName, $paramValue);
            $globalConfigPath = $this->configuration->getGlobalConfigFilePath();
            $io->success("Global configuration parameter '{$paramName}' set to '{$paramValue}'.");
            $io->note("Changes saved to: {$globalConfigPath}");
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error("Failed to write global configuration: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
