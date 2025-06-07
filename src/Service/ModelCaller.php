<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use Romanzaycev\Vibe\Contract\LlmClientInterface;
use Romanzaycev\Vibe\Storage\Storage;
use Romanzaycev\Vibe\Storage\StorageCollection;
use Romanzaycev\Vibe\Model\CallResult;
use Romanzaycev\Vibe\Model\ToolCall as ParsedToolCall;
use Romanzaycev\Vibe\Event\ModelCallEvents;
use Romanzaycev\Vibe\Event\BeforeLlmCallEvent;
use Romanzaycev\Vibe\Event\LlmResponseEvent;
use Romanzaycev\Vibe\Event\ToolCallRequestedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use OpenAI\Responses\Chat\CreateResponseToolCall;

class ModelCaller
{
    private string $systemPrompt;

    private LlmClientInterface $client;
    private Configuration $config;
    private StorageCollection $historyCollection;
    private EventDispatcherInterface $eventDispatcher;
    private ToolRegistry $toolRegistry;

    public function __construct(
        LlmClientInterface $client,
        Configuration $config,
        Storage $storage,
        EventDispatcherInterface $eventDispatcher,
        ToolRegistry $toolRegistry,
        SystemPromptPreprocessor $systemPromptPreprocessor,
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->historyCollection = $storage->getCollection('project_history');
        $this->eventDispatcher = $eventDispatcher;
        $this->toolRegistry = $toolRegistry;
        $this->systemPrompt = $systemPromptPreprocessor->process();
    }

    public function call(string $userInput): CallResult
    {
        $currentUserMessage = ['role' => 'user', 'content' => $userInput];

        // Загружаем историю ДО текущего пользовательского ввода
        $historicalMessages = $this->loadInitialHistory();

        // Сообщения, которые будут накапливаться в рамках этого "хода" (включая userInput, ответы ассистента, результаты тулов)
        $messagesForThisTurnAndSubsequentLLMCalls = [];

        // Сохраняем запрос пользователя в историю один раз в начале этого "хода"
        $this->saveHistoryEntry($currentUserMessage);
        $messagesForThisTurnAndSubsequentLLMCalls[] = $currentUserMessage;

        $maxToolCallIterations = 5;
        $currentIteration = 0;

        while ($currentIteration < $maxToolCallIterations) {
            $currentIteration++;

            // Формируем полный контекст для LLM: Системный промпт + История + Сообщения текущего хода
            $completeContextForLlm = [];

            foreach($historicalMessages as $histMsg) {
                $completeContextForLlm[] = $histMsg;
            }

            foreach($messagesForThisTurnAndSubsequentLLMCalls as $turnMsg) {
                $completeContextForLlm[] = $turnMsg;
            }

            $payloadMessages = $this->applyHistoryLimit($completeContextForLlm); // Применяем лимит
            array_unshift($payloadMessages, ['role' => 'system', 'content' => $this->systemPrompt]);

            // --- Остальная часть метода call() остается почти такой же ---
            // Используем $payloadMessages для $beforeCallEvent и $apiPayload

            $toolDefinitionsForLlm = $this->toolRegistry->getAllToolDefinitionsForLlm();

            $beforeCallEvent = new BeforeLlmCallEvent($payloadMessages, $toolDefinitionsForLlm); // Передаем полный контекст
            $this->eventDispatcher->dispatch($beforeCallEvent, ModelCallEvents::BEFORE_LLM_CALL);

            $finalPayloadMessages = $beforeCallEvent->getMessages(); // Эти сообщения пойдут в API
            $finalToolDefinitions = $beforeCallEvent->getToolDefinitions();

            $modelName = (string) $this->config->get('MODEL_NAME');
            $apiPayload = [
                'model' => $modelName,
                'messages' => $finalPayloadMessages, // Используем сообщения из события
                'temperature' => 0.6,
            ];

            if (!empty($finalToolDefinitions)) {
                $apiPayload['tools'] = $finalToolDefinitions;
                $apiPayload['tool_choice'] = 'auto';
            }

            try {
                $llmApiResponse = $this->client->chat()->create($apiPayload);
            } catch (\Exception $e) {
                throw $e;
            }

            $responseMessage = $llmApiResponse->choices[0]->message;

            $assistantResponseEntry = ['role' => 'assistant', 'content' => $responseMessage->content];
            $parsedToolCallsForCallResult = [];
            $toolCallsFromLlmForHistory = [];

            if (!empty($responseMessage->toolCalls)) {
                // ... (логика парсинга tool_calls как была) ...
                /** @var CreateResponseToolCall $apiToolCall */
                foreach ($responseMessage->toolCalls as $apiToolCall) {
                    $toolCallsFromLlmForHistory[] = [
                        'id' => $apiToolCall->id,
                        'type' => $apiToolCall->type,
                        'function' => ['name' => $apiToolCall->function->name, 'arguments' => $apiToolCall->function->arguments],
                    ];
                    $arguments = json_decode($apiToolCall->function->arguments, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $arguments = ['error' => 'Invalid JSON arguments from LLM', 'raw_arguments' => $apiToolCall->function->arguments];
                    }

                    $parsedToolCallsForCallResult[] = new ParsedToolCall($apiToolCall->id, $apiToolCall->function->name, $arguments);
                }

                if(!empty($toolCallsFromLlmForHistory)){
                    $assistantResponseEntry['tool_calls'] = $toolCallsFromLlmForHistory;
                }
            }

            $this->saveHistoryEntry($assistantResponseEntry);
            // Добавляем ответ ассистента в сообщения *текущего хода* для следующей итерации (если она будет)
            $messagesForThisTurnAndSubsequentLLMCalls[] = $assistantResponseEntry;

            if (empty($parsedToolCallsForCallResult)) {
                $callResult = new CallResult($responseMessage->content, [], $llmApiResponse->toArray());
                $this->eventDispatcher->dispatch(new LlmResponseEvent($callResult, null, true), ModelCallEvents::LLM_RESPONSE_RECEIVED);
                return $callResult;
            }

            // Если есть tool_calls
            $toolResultsForLlmNextTurn = [];
            foreach ($parsedToolCallsForCallResult as $parsedToolCall) {
                // ... (логика диспатчинга ToolCallRequestedEvent и получения результата как была) ...
                $toolRequestEvent = new ToolCallRequestedEvent($parsedToolCall);
                $this->eventDispatcher->dispatch($toolRequestEvent, ModelCallEvents::TOOL_CALL_REQUESTED);

                $executionResultJson = $toolRequestEvent->getExecutionResult();
                if ($executionResultJson === null) {
                    $executionResultJson = json_encode(['status' => 'error', 'message' => "Tool '{$parsedToolCall->functionName}' execution result not provided."]);
                }

                $toolResultEntryForLlm = [
                    'tool_call_id' => $parsedToolCall->id, 'role' => 'tool',
                    'name' => $parsedToolCall->functionName, 'content' => $executionResultJson,
                ];
                $toolResultsForLlmNextTurn[] = $toolResultEntryForLlm;
                $this->saveHistoryEntry($toolResultEntryForLlm);
                // Добавляем результат инструмента в сообщения *текущего хода* для следующей итерации
                $messagesForThisTurnAndSubsequentLLMCalls[] = $toolResultEntryForLlm;
            }

            // $messagesForThisTurnAndSubsequentLLMCalls теперь содержат ответ ассистента + результаты всех тулов.
            // На следующей итерации они будут добавлены к системному промпту и истории.
        } // конец while

        // ... (обработка max iterations reached как была) ...
        $callResult = new CallResult(
            $responseMessage->content ?? "Max tool call iterations reached.",
            $parsedToolCallsForCallResult,
            $llmApiResponse->toArray()
        );
        $this->eventDispatcher->dispatch(new LlmResponseEvent($callResult, null, false), ModelCallEvents::LLM_RESPONSE_RECEIVED);
        return $callResult;
    }

    /**
     * Allows refining arguments for a tool call via a new LLM interaction.
     * This interaction does NOT affect the main chat history.
     *
     * @param ParsedToolCall $originalToolCall The tool call to refine.
     * @param string $userRefinementPrompt The user's prompt for refinement.
     * @param array $currentChatMessages The current complete chat history (messages) that led to the originalToolCall.
     * @return ParsedToolCall|null The new refined tool call, or null if refinement failed.
     */
    public function refineToolCall(ParsedToolCall $originalToolCall, string $userRefinementPrompt, array $currentChatMessages): ?ParsedToolCall
    {
        $toolName = $originalToolCall->functionName;

        $refinementSystemMessage = [
            'role' => 'system',
            'content' => "You are an assistant helping a user refine the arguments for a tool called '{$toolName}'. " .
                "The user was about to execute this tool with initial arguments: " . json_encode($originalToolCall->functionArguments) . ". " .
                "The user provided the following feedback or refinement request: '{$userRefinementPrompt}'. " .
                "Your task is to generate a new set of arguments for the '{$toolName}' tool based on this feedback. " .
                "You MUST call the '{$toolName}' tool with these new arguments. Do not respond with text, only with the tool call."
        ];

        // Берем текущую историю чата (которая привела к originalToolCall) и добавляем системное сообщение
        // Можно ограничить $currentChatMessages, если они слишком длинные
        $messagesForRefinement = $this->applyHistoryLimit($currentChatMessages); // Применяем лимит к контексту
        $messagesForRefinement[] = $refinementSystemMessage;
        // $messagesForRefinement[] = ['role' => 'user', 'content' => $userRefinementPrompt]; // Или так, если системный промпт другой

        $modelName = (string) $this->config->get('MODEL_NAME');
        $apiPayload = [
            'model' => $modelName,
            'messages' => $messagesForRefinement,
            'tools' => $this->toolRegistry->getAllToolDefinitionsForLlm(), // Передаем все доступные инструменты
            'tool_choice' => ['type' => 'function', 'function' => ['name' => $toolName]], // Принудительный вызов конкретного инструмента
        ];

        // Диспатчить урезанное событие BEFORE_LLM_CALL, если нужно, но для этого вызова может быть излишне
        // $this->eventDispatcher->dispatch(new BeforeLlmCallEvent($messagesForRefinement, $apiPayload['tools']), ModelCallEvents::BEFORE_LLM_CALL . '.refinement');


        try {
            $llmApiResponse = $this->client->chat()->create($apiPayload);
        } catch (\Exception $e) {
            // error_log("LLM API Call failed during tool refinement: " . $e->getMessage());
            return null;
        }

        $responseMessage = $llmApiResponse->choices[0]->message ?? null;

        if ($responseMessage && !empty($responseMessage->toolCalls)) {
            /** @var CreateResponseToolCall $apiToolCall */
            foreach ($responseMessage->toolCalls as $apiToolCall) {
                if ($apiToolCall->function->name === $toolName) {
                    $arguments = json_decode($apiToolCall->function->arguments, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return new ParsedToolCall($apiToolCall->id, $toolName, $arguments);
                    } else {
                        // error_log("Invalid JSON arguments from LLM during refinement: {$apiToolCall->function->arguments}");
                        return null; // Ошибка декодирования
                    }
                }
            }
        }
        // Если LLM не вернула ожидаемый tool_call
        return null;
    }


    private function loadInitialHistory(): array
    {
        $historyEntries = $this->historyCollection->getAll();
        $messages = [];
        foreach ($historyEntries as $entry) {
            $message = ['role' => $entry['role']];
            if (isset($entry['content'])) $message['content'] = $entry['content'];
            if (isset($entry['tool_calls'])) $message['tool_calls'] = $entry['tool_calls'];
            if (isset($entry['tool_call_id'])) {
                $message['tool_call_id'] = $entry['tool_call_id'];
                if(isset($entry['name'])) $message['name'] = $entry['name'];
            }
            $messages[] = $message;
        }
        return $messages;
    }

    private function applyHistoryLimit(array $messages): array
    {
        $limit = (int) $this->config->get('HISTORY_LIMIT', 20);
        return array_slice($messages, -$limit);
    }

    private function saveHistoryEntry(array $entry): void
    {
        if (!isset($entry['timestamp'])) {
            $entry['timestamp'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ISO8601);
        }
        $this->historyCollection->add($entry);
    }
}
