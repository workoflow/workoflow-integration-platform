<?php

namespace App\DTO;

/**
 * Value Object for tool filtering criteria
 * Immutable data transfer object for API tool filtering
 */
class ToolFilterCriteria
{
    /**
     * @param string|null $workflowUserId User ID filter
     * @param array<string> $toolTypes Tool types to include (e.g., ['system', 'jira'])
     *                                 Empty array = all user integrations
     */
    public function __construct(
        private readonly ?string $workflowUserId = null,
        private readonly array $toolTypes = []
    ) {
    }

    public function getWorkflowUserId(): ?string
    {
        return $this->workflowUserId;
    }

    /**
     * @return array<string>
     */
    public function getToolTypes(): array
    {
        return $this->toolTypes;
    }

    public function hasToolTypeFilter(): bool
    {
        return !empty($this->toolTypes);
    }

    public function includesSystemTools(): bool
    {
        return in_array('system', $this->toolTypes, true);
    }

    public function includesSpecificType(string $type): bool
    {
        return in_array($type, $this->toolTypes, true);
    }

    public function includesOnlySystemTools(): bool
    {
        if (!$this->hasToolTypeFilter()) {
            return false;
        }

        foreach ($this->toolTypes as $type) {
            if ($type !== 'system' && !str_starts_with($type, 'system.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create from CSV string (e.g., "system,jira,confluence")
     */
    public static function fromCsv(?string $workflowUserId, ?string $toolTypeCsv): self
    {
        $toolTypes = [];
        if ($toolTypeCsv !== null && $toolTypeCsv !== '') {
            $toolTypes = array_map('trim', explode(',', $toolTypeCsv));
            $toolTypes = array_filter($toolTypes); // Remove empty strings
            $toolTypes = array_unique($toolTypes); // Remove duplicates
            $toolTypes = array_values($toolTypes); // Re-index
        }

        return new self($workflowUserId, $toolTypes);
    }
}
