<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use Romanzaycev\Vibe\Storage\Storage;
use Romanzaycev\Vibe\Storage\StorageCollection;

class Indexer
{
    private StorageCollection $indexerData;
    private bool $inProgress = false;

    public function __construct(
        private readonly Storage $storage,
    )
    {
        $this->indexerData = $this->storage->getCollection("indexer");
    }

    public function isProjectIndexed(): bool
    {
        return !empty($this->indexerData->findBy(fn ($e) => !empty($e['indexed']) && $e['indexed'] === true));
    }

    public function setProjectIndexed(bool $isIndexed): void
    {
        $this->indexerData->add(["indexed" => $isIndexed]);
    }

    public function isIndexInProgress(): bool
    {
        return $this->inProgress;
    }

    public function setInProgress(bool $inProgress): void
    {
        $this->inProgress = $inProgress;
    }

    public function add(string $response): void
    {
        $this->indexerData->add(["type" => "summary", "content" => $response]);
    }
}
