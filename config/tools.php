<?php

declare(strict_types=1);

use Romanzaycev\Vibe\Tool\CreateFileTool;
use Romanzaycev\Vibe\Tool\DeleteFileTool;
use Romanzaycev\Vibe\Tool\FindTextInFilesTool;
use Romanzaycev\Vibe\Tool\ReadFileTool;
use Romanzaycev\Vibe\Tool\RewriteFileTool;
use Romanzaycev\Vibe\Tool\GitAddTool;
use Romanzaycev\Vibe\Tool\GitCommitTool;
use Romanzaycev\Vibe\Tool\GitPushTool;
use Romanzaycev\Vibe\Tool\GitPullTool;
use Romanzaycev\Vibe\Tool\GitHistoryTool;
use Romanzaycev\Vibe\Tool\GitStatusTool;
use Romanzaycev\Vibe\Tool\ListDirectoryTool;

$tools = [
    CreateFileTool::class,
    DeleteFileTool::class,
    FindTextInFilesTool::class,
    ReadFileTool::class,
    RewriteFileTool::class,
    GitAddTool::class,
    GitCommitTool::class,
    GitPushTool::class,
    GitPullTool::class,
    GitHistoryTool::class,
    GitStatusTool::class,
    ListDirectoryTool::class,
];

return $tools;
