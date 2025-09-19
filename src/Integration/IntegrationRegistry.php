<?php

namespace App\Integration;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class IntegrationRegistry
{
    /**
     * @var IntegrationInterface[]
     */
    private array $integrations = [];

    public function __construct(
        #[AutowireIterator('app.integration')]
        iterable $integrations
    ) {
        foreach ($integrations as $integration) {
            $this->integrations[$integration->getType()] = $integration;
        }
    }

    public function get(string $type): ?IntegrationInterface
    {
        return $this->integrations[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->integrations[$type]);
    }

    /**
     * @return IntegrationInterface[]
     */
    public function all(): array
    {
        return $this->integrations;
    }

    /**
     * Get all integration types
     * @return string[]
     */
    public function getTypes(): array
    {
        return array_keys($this->integrations);
    }

    /**
     * Get all system integrations (those that don't require credentials)
     * @return IntegrationInterface[]
     */
    public function getSystemIntegrations(): array
    {
        return array_filter($this->integrations, fn($i) => !$i->requiresCredentials());
    }

    /**
     * Get all user integrations (those that require credentials)
     * @return IntegrationInterface[]
     */
    public function getUserIntegrations(): array
    {
        return array_filter($this->integrations, fn($i) => $i->requiresCredentials());
    }
}