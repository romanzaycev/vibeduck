<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Command;

use Romanzaycev\Vibe\Event\BeforeLlmCallEvent;
use Romanzaycev\Vibe\Event\LlmResponseEvent;
use Romanzaycev\Vibe\Event\ModelCallEvents;
use Romanzaycev\Vibe\Event\ToolCallRequestedEvent;
use Romanzaycev\Vibe\Service\ContentComparator;
use Romanzaycev\Vibe\Service\Indexer;
use Romanzaycev\Vibe\Service\MarkdownConsoleRenderer;
use Romanzaycev\Vibe\Service\ModelCaller;
use Romanzaycev\Vibe\Service\ToolExecutor;
use Romanzaycev\Vibe\Storage\Storage;
use Romanzaycev\Vibe\Storage\StorageCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ChatCommand extends Command
{
    private ModelCaller $modelCaller;
    private EventDispatcherInterface $eventDispatcher;
    private ToolExecutor $toolExecutor;
    private MarkdownConsoleRenderer $markdownRenderer;

    private ?InputInterface $input = null;
    private ?OutputInterface $output = null;
    private ?QuestionHelper $questionHelper = null;

    /** @var array<string, bool> */
    private array $alwaysAllowedTools = [];

    /** @var array<int, array<string, mixed>> */
    private array $currentMessagesContext = [];
    private SymfonyStyle $io;
    private ProgressBar $pb;
    private StorageCollection $history;

    public function __construct(
        ModelCaller $modelCaller,
        EventDispatcherInterface $eventDispatcher,
        ToolExecutor $toolExecutor,
        MarkdownConsoleRenderer $markdownRenderer,
        Storage $storage,
        private readonly ContentComparator $contentComparator,
        private readonly Indexer $indexer,
    ) {
        parent::__construct("chat");
        $this->setDescription('Interactive chat with Vibeduck.');
        $this->modelCaller = $modelCaller;
        $this->eventDispatcher = $eventDispatcher;
        $this->toolExecutor = $toolExecutor;
        $this->markdownRenderer = $markdownRenderer;
        $this->history = $storage->getCollection("project_history");
    }

    protected function configure(): void
    {
        // Options for verbosity are handled by Symfony Console Application
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->questionHelper = $this->getHelper('question');
        $this->io = new SymfonyStyle($input, $output);

        // Инициализируем индикатор прогресса
        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('<info>%bar% duck is thinking...</info>');
        $progressBar->setBarWidth(5);
        $this->pb = $progressBar;

        foreach ($this->history->findBy(static fn($row): bool => !empty($row["role"]) && $row["role"] === "user") as $r) {
            if (!empty($r["content"])) {
                readline_add_history($r["content"]);
            }
        }

        $this->eventDispatcher->addListener(ModelCallEvents::BEFORE_LLM_CALL, [$this, 'onBeforeLlmCall']);
        $this->eventDispatcher->addListener(ModelCallEvents::LLM_RESPONSE_RECEIVED, [$this, 'onLlmResponseReceived']);
        $this->eventDispatcher->addListener(ModelCallEvents::TOOL_CALL_REQUESTED, [$this, 'onToolCallRequested']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $output->writeln('<info>Welcome to Vibeduck Interactive Chat!</info>');
            $output->writeln('<comment>Type "exit" or "quit" to leave. Use -v, -vv, -vvv for more tool details.</comment>');
            $output->writeln('');
        }

        if (!$this->indexer->isProjectIndexed()) {
            $this->pb->start();
            $this->output->writeln("<comment>Project codebase first indexing...</comment>");
            $this->indexer->setInProgress(true);

            try {
                $this
                    ->modelCaller
                    ->call("Explore the project codebase. Use directory reading tools, find the most interesting paths and explore them in detail, including reading files. Look for significant files in the code that may relate to the type of project (package.json, composer.json, cargo, etc.). After scanning, give a short summary about the project");
            } catch (\Exception $e) {
                if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
                    $output->writeln("<error>An error occurred: {$e->getMessage()}</error>");
                }

                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $output->writeln($e->getTraceAsString());
                }
            } finally {
                $this->pb->finish();
                $this->indexer->setInProgress(false);
            }

            $this->output->writeln("<comment>Project indexed</comment>");
            $this->indexer->setProjectIndexed(true);
        }

        while (true) {
            $userInput = readline(':> ');

            if ($userInput === null || in_array(strtolower(trim((string)$userInput)), ['exit', 'quit'], true)) {
                break;
            }

            if (empty(trim((string)$userInput))) {
                continue;
            }

            readline_add_history($userInput);
            $this->pb->start();

            try {
                $this->modelCaller->call((string)$userInput);
            } catch (\Exception $e) {
                if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
                    $output->writeln("<error>An error occurred: {$e->getMessage()}</error>");
                }
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $output->writeln($e->getTraceAsString());
                }
            } finally {
                $this->pb->finish();
            }

            if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET && $this->output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) { // Add newline if not verbose to separate prompts
                $output->writeln('');
            }
        }

        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $output->writeln('<info>Goodbye!</info>');
        }

        return Command::SUCCESS;
    }

    public function onBeforeLlmCall(BeforeLlmCallEvent $event): void
    {
        if (!$this->output || $this->output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) return;

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_QUIET) {
            $this->pb->advance();
        }

        $this->currentMessagesContext = $event->getMessages();
    }

    public function onLlmResponseReceived(LlmResponseEvent $event): void
    {
        $this->pb->advance();

        if (!$this->output || $this->output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) return;

        $callResult = $event->getCallResult();

        if ($callResult->hasTextContent()) {
            $markdownContent = $callResult->getTextContent();
            if ($markdownContent !== null && trim($markdownContent) !== '') {
                if ($this->indexer->isIndexInProgress()) {
                    $this->indexer->add($markdownContent);
                } else {
                    $renderedMarkdown = $this->markdownRenderer->render($markdownContent);
                    $this->output->writeln("<fg=cyan;options=bold>AI:></>");
                    $this->output->writeln($renderedMarkdown);
                }
            } elseif ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                // Если контент пустой, но должен был быть, выводим сообщение в дебаг режиме
                $this->output->writeln("<fg=gray>AI: (empty text content)</>");
            }
            // Если hasTextContent() true, но getTextContent() вернул null или пустую строку - рендерер не вызовется.
            // Это нормально, если модель ничего не вернула текстом.
        } elseif ($event->isFinalResponse() && $callResult->hasToolCalls()) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                $this->output->writeln("<comment>(AI action completed. No further text response.)</comment>");
            }
        }
    }

    public function onToolCallRequested(ToolCallRequestedEvent $event): void
    {
        $this->pb->advance();

        if (!$this->output || !$this->input || !$this->questionHelper ) return; // No output if quiet
        // If verbosity is quiet, we still need to process the tool call for the AI, but without user interaction or output.
        // The logic below handles actual output based on verbosity.

        $originalToolCall = $event->getToolCall();
        $toolName = $originalToolCall->functionName;
        $currentToolCall = $originalToolCall;
        $argsString = json_encode($currentToolCall->functionArguments);

        $toolInstance = $this->toolExecutor->getToolRegistry()->getTool($toolName);
        if (!$toolInstance && $this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $this->output->writeln("<error>Tool '{$toolName}' definition not found locally. Cannot execute.</error>");
            $event->setExecutionResult(json_encode(['status' => 'error', 'message' => "Tool '{$toolName}' definition not found by ChatCommand."]));
            return;
        }

        $userChoice = null;
        $executeImmediately = false;
        $executionDeclined = false;
        $confirmationExplicitlyHandled = false; // True if user was asked or if 'always allow' was used

        // 1. Check "always allowed" for the session
        if (isset($this->alwaysAllowedTools[$toolName]) && $this->alwaysAllowedTools[$toolName]) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) { // Show details for -v and above
                    $this->output->writeln('');
                    $this->output->writeln("<options=underscore>AI Action (Always Allowed):</>");
                    $this->output->writeln(sprintf("<info>Tool:</info> <options=bold>%s</>", $toolName));
                    $this->output->writeln("<info>Arguments:</info>\n" . $this->formatJsonForDisplay($argsString, $toolName));
                 }
                $this->output->writeln("<comment>Tool '{$toolName}' is always allowed for this session. Executing automatically.</comment>");
            }
            $executeImmediately = true;
            $confirmationExplicitlyHandled = true;
        }
        // 2. Check if tool requires confirmation by default
        elseif ($toolInstance && !$toolInstance->requiresConfirmationByDefault()) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) { // Only show details for -v and above
                $this->output->writeln('');
                $this->output->writeln("<options=underscore>AI Action (Auto-Approved):</>");
                $this->output->writeln(sprintf("<info>Tool:</info> <options=bold>%s</>", $toolName));
                $this->output->writeln("<info>Arguments:</info>\n" . $this->formatJsonForDisplay($argsString, $toolInstance->getName()));
                $this->output->writeln("<comment>Tool '{$toolName}' does not require confirmation. Executing automatically.</comment>");
            } elseif ($this->output->getVerbosity() == OutputInterface::VERBOSITY_NORMAL){ // Show minimal for normal verbosity
                $this->output->writeln("<fg=gray>Duck used {$toolName} (args: " . $this->formatJsonForDisplay($argsString, $toolInstance->getName(), true) . ")</>");
            }
            $executeImmediately = true;
            // $confirmationExplicitlyHandled remains false here, as it was auto-approved by tool's default
        }

        // 3. Interactive confirmation loop if not executing immediately
        // This loop only runs if confirmation is required by the tool and not "always allowed"
        if (!$executeImmediately && $toolInstance && $toolInstance->requiresConfirmationByDefault()) {
            $confirmationExplicitlyHandled = true; // User will be involved
            if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
                $this->output->writeln('');
                $this->output->writeln("<options=underscore>AI Action Required:</>");
            }

            while (true) { // Loop for y/n/a/r
                if ($this->output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) { // If somehow entered here in quiet mode, auto-decline
                    $executionDeclined = true;
                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
                         $this->output->writeln("<comment>Tool confirmation skipped in quiet mode. Auto-declined.</comment>");
                    }
                    break;
                }

                // Show tool info before asking for choice
                $this->output->writeln(sprintf("<info>Tool:</info> <options=bold>%s</>", $toolName));
                $this->output->writeln("<info>Arguments:</info>\n" . $this->formatJsonForDisplay(json_encode($currentToolCall->functionArguments), $toolName));

                $question = new ChoiceQuestion(
                    "Allow execution of '{$toolName}'? (default: yes)",
                    ['y' => 'Yes', 'n' => 'No', 'a' => 'Always (for this session)', 'r' => 'Refine arguments'], 'y'
                );
                $question->setErrorMessage('Invalid choice: %s');
                $userChoice = $this->questionHelper->ask($this->input, $this->output, $question);

                if ($userChoice === 'r') { /* ... refine logic ... */
                    // Using readline for refinement prompt for consistency
                    $this->output->writeln("<comment>Refine arguments for '{$toolName}'. Enter refinement prompt:</comment>");
                    $refinementPrompt = readline("<fg=yellow>> Refine:</> ");
                    if (!empty(trim((string)$refinementPrompt))) {
                        readline_add_history(trim((string)$refinementPrompt));
                        $this->output->writeln("<comment>Asking AI to refine arguments for '{$toolName}'...</comment>");
                        $refinedToolCall = $this->modelCaller->refineToolCall($currentToolCall, (string)$refinementPrompt, $this->currentMessagesContext);
                        if ($refinedToolCall) {
                            $currentToolCall = $refinedToolCall;
                            $argsString = json_encode($currentToolCall->functionArguments); // Update argsString
                            $this->output->writeln("<info>AI has provided refined arguments.</info>\n");
                            continue; // Back to choice
                        } else {
                            $this->output->writeln("<error>AI could not refine arguments. Using previous.</error>");
                        }
                    } else {
                        $this->output->writeln("<comment>No refinement prompt. Using current arguments.</comment>");
                    }
                } elseif ($userChoice === 'y' || $userChoice === 'yes') {
                    $executeImmediately = true; break;
                } elseif ($userChoice === 'a' || $userChoice === 'always') {
                    $this->alwaysAllowedTools[$toolName] = true;
                    $executeImmediately = true; break;
                } elseif ($userChoice === 'n' || $userChoice === 'no') {
                    $executionDeclined = true; break;
                }
            }
        } elseif (!$executeImmediately && $this->output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            // If tool required confirmation but we are in quiet mode, auto-decline
            // This case might be redundant if the while loop above handles VERBOSITY_QUIET
            // but let's keep it explicit.
            $executionDeclined = true;
        }


        // 4. Execute or set declined result
        if ($executeImmediately) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE || ($confirmationExplicitlyHandled && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) ) {
                if (!($toolInstance && !$toolInstance->requiresConfirmationByDefault() && !$confirmationExplicitlyHandled && $this->output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE)) {
                    // Avoid double 'Executing tool...' if already printed for auto-approved verbose
                    if(!(isset($this->alwaysAllowedTools[$toolName]) && $this->alwaysAllowedTools[$toolName] && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL)){ // avoid if always allowed already printed
                        if($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE || $confirmationExplicitlyHandled) { // only show if verbose or explicitly handled
                            $this->output->writeln("<comment>Executing tool '{$toolName}'...</comment>");
                        }
                    }
                }
            }

            $executionResultJson = $this->toolExecutor->execute($currentToolCall);
            $decodedResult = json_decode($executionResultJson, true);
            $statusMessage = $decodedResult['message'] ?? ($decodedResult['status'] ?? 'Execution status unknown');
            $isError = isset($decodedResult['status']) && $decodedResult['status'] === 'error';

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE || ($confirmationExplicitlyHandled && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) ) {
                $outputTag = $isError ? "error" : "comment";
                $this->output->writeln("<{$outputTag}>Tool '{$toolName}' result: " . $this->formatJsonForDisplay($executionResultJson) . "</{$outputTag}>");
            } elseif ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) { // For -v, show status of auto-approved tools
                $outputTag = $isError ? "error" : "fg=gray";
                $this->output->writeln("<{$outputTag}>Tool '{$toolName}' status: {$statusMessage}</{$outputTag}>");
            }
            // For VERBOSITY_NORMAL, silent tools already printed their one-liner. No result shown unless explicitly handled.
            // For VERBOSITY_QUIET, nothing is shown.

            $event->setExecutionResult($executionResultJson);

        } elseif ($executionDeclined) {
            $declinedMessage = $toolInstance ? "User declined execution of tool '{$toolName}'." : "Tool '{$toolName}' definition not found, execution declined.";
            $declinedResultJson = json_encode(['status' => 'user_declined', 'message' => $declinedMessage]);
            if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
                $this->output->writeln("<error>".($toolInstance ? "Tool '{$toolName}' execution declined by user." : "Tool '{$toolName}' execution declined (definition not found).")."</error>");
            }
            $event->setExecutionResult($declinedResultJson);
        } else {
            // Fallback: if no decision was made (should not happen with current logic)
            // Silently decline to prevent unexpected execution
            $fallbackDecline = json_encode(['status' => 'internal_error', 'message' => "Tool '{$toolName}' execution state unclear, declined by default."]);
            $event->setExecutionResult($fallbackDecline);
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->output->writeln("<error>Tool '{$toolName}' execution state unclear, declined by default. Check ChatCommand logic.</error>");
            }
}

        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET && $this->output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln(''); // Add newline for normal verbosity after tool interaction
        }
    }

    private function formatJsonForDisplay(string $jsonString, string $toolName = null, bool $compact = false): string
    {
        $decoded = json_decode($jsonString, true); // Декодируем в ассоциативный массив

        if (json_last_error() === JSON_ERROR_NONE) {
            // Специфическое форматирование для create_file и rewrite_file, если предоставлен $io
            if (is_array($decoded) && ($toolName === 'create_file' || $toolName === 'rewrite_file')) {
                if ($toolName === 'create_file' && isset($decoded['filename']) && isset($decoded['content'])) {
                    $this->io->writeln(sprintf('Filename: %s', $decoded['filename']));
                    $this->io->writeln('Content:');
                    $this->io->block($decoded['content'], null, 'fg=white;bg=black', ' ', true);
                    return ''; // Возвращаем пустую строку, так как вывод уже выполнен через $io
                } elseif ($toolName === 'rewrite_file' && isset($decoded['filename']) && isset($decoded['content'])) {
                    $this->io->writeln(sprintf('Filename: %s', $decoded['filename']));
                    $this->io->writeln('Diff:');

                    $diff = $this->contentComparator->getDiff($decoded['filename'], $decoded['content']);

                    foreach ($diff as $line) {
                        $this->io->writeln(sprintf('Line: %s', $line['l']));
                        $this->io->writeln('<fg=white;bg=red>- ' . $line['-'] . '</>');
                        $this->io->writeln('<fg=white;bg=green>+ ' . $line['+'] . '</>');
                    }

                    return ''; // Возвращаем пустую строку, так как вывод уже выполнен через $io
                }
            }

            // Стандартное форматирование JSON
            if ($compact) {
                // Попытка сделать очень короткое представление
                if (is_array($decoded) && isset($decoded['filename'])) return $decoded['filename'];
                if (is_array($decoded) && isset($decoded['path'])) return $decoded['path'];
                return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // Однострочный компактный
            }

            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Если невалидный JSON, возвращаем исходную строку
        return $jsonString;
    }
}

