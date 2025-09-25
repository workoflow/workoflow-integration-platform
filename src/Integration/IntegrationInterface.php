<?php

namespace App\Integration;

interface IntegrationInterface
{
    /**
     * Get the unique identifier for this integration type
     */
    public function getType(): string;

    /**
     * Get human-readable name for this integration
     */
    public function getName(): string;

    /**
     * Get available tools for this integration
     * @return ToolDefinition[]
     */
    public function getTools(): array;

    /**
     * Execute a tool function
     * @param string $toolName The tool to execute
     * @param array $parameters The parameters for the tool
     * @param array $credentials The credentials (null for system tools)
     * @return array The result of the execution
     */
    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array;

    /**
     * Check if this integration requires credentials
     */
    public function requiresCredentials(): bool;

    /**
     * Validate credentials format
     */
    public function validateCredentials(array $credentials): bool;

    /**
     * Get credential fields needed for this integration
     * @return CredentialField[]
     */
    public function getCredentialFields(): array;
}
