<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Command;

use Romanzaycev\Vibe\Storage\StorageCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ClearHistoryCommand extends Command
{
    private StorageCollection $historyCollection;

    public function __construct(StorageCollection $historyCollection)
    {
        $this->historyCollection = $historyCollection;
        parent::__construct("clear");
        $this->setDescription('Clears the project history');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($io->confirm('Are you sure you want to clear the project history? This action cannot be undone.')) {
            $this->historyCollection->clear();
            $io->success('Project history cleared successfully.');
        } else {
            $io->info('Operation cancelled.');
        }

        return Command::SUCCESS;
    }
}
