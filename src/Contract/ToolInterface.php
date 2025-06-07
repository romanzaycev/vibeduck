<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Contract;

/**
 * Defines the contract for all tools available to the Vibe system.
 *
 * Each tool must implement this interface to be discoverable and executable
 * by the system. This interface specifies the core methods required for a tool
 * to provide its name, description, parameter schema, execution logic,
 * and confirmation requirements.
 */
interface ToolInterface
{
    /**
     * Gets the unique name of the tool.
     *
     * This name is used to identify the tool within the system and by the LLM.
     * It should be a short, descriptive, and consistent identifier.
     *
     * @return string The unique name of the tool (e.g., 'read_file', 'create_file').
     */
    public function getName(): string;

    /**
     * Gets a brief description of what the tool does.
     *
     * This description is used to inform the LLM and potentially the user
     * about the tool's purpose and functionality.
     *
     * @return string A concise description of the tool.
     */
    public function getDescription(): string;

    /**
     * Gets the JSON schema definition for the tool's parameters.
     *
     * This schema is used by the system and the LLM to understand
     * the expected input arguments for the tool's `execute` method.
     * It should follow the JSON Schema specification.
     *
     * @return array A JSON schema array defining the tool's parameters.
     *               Example: ['type' => 'object', 'properties' => [...], 'required' => [...]]
     */
    public function getParametersDefinition(): array;

    /**
     * Executes the tool's logic with the provided arguments.
     *
     * This method contains the core functionality of the tool. It receives
     * an associative array of arguments based on the `getParametersDefinition`.
     * The method must return a JSON-encoded string representing the result
     * of the execution, including a 'status' key ('success' or 'error')
     * and potentially other relevant data or messages.
     *
     * @param array $arguments An associative array of arguments for the tool,
     *                         validated against the schema from `getParametersDefinition`.
     * @return string A JSON-encoded string containing the execution result.
     *                Example: json_encode(['status' => 'success', 'message' => 'Operation complete'])
     */
    public function execute(array $arguments): string; // Возвращает JSON-строку

    /**
     * Gets the full definition of the tool suitable for LLM consumption.
     *
     * This method typically combines the name, description, and parameters
     * definition into a single structure that can be easily provided to
     * a Language Model for function calling purposes.
     *
     * @return array An array representing the tool's definition for the LLM.
     *               Example: ['name' => ..., 'description' => ..., 'parameters' => [...]]
     */
    public function getDefinitionForLlm(): array;

    /**
     * Determines if the tool should ask for user confirmation before execution by default.
     *
     * Tools that perform read-only operations or have minimal side-effects
     * (like reading file content or listing directories) should typically return `false`.
     * Tools that modify the file system or external resources (like creating,
     * rewriting, or deleting files) should typically return `true`.
     * This default behavior can potentially be overridden by session-specific
     * user preferences (e.g., an "always allow this tool" setting).
     *
     * @return bool True if confirmation is required by default before executing this tool, false otherwise.
     */
    public function requiresConfirmationByDefault(): bool;

    /**
     * Provides a few-shot example demonstrating how to use the tool.
     *
     * This example helps the LLM understand the typical usage pattern
     * of the tool and how to format the arguments for the `execute` method.
     * It should be a string representation of a valid tool call, often in a
     * format understandable by the LLM (e.g., a JSON object string).
     *
     * @return string A string representation of a few-shot example for the tool.
     *                Example: '{"filename": "path/to/file.txt"}'
     */
    public function getFewShotExample(): string;
}
