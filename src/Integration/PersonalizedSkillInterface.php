<?php

namespace App\Integration;

use App\Entity\IntegrationConfig;

/**
 * Interface for personalized skills that require user-specific credentials
 * and provide AI agent system prompts (e.g., Jira, Confluence, SharePoint)
 */
interface PersonalizedSkillInterface extends IntegrationInterface
{
    /**
     * Get system prompt for AI agents
     * Supports future user-specific overrides via optional IntegrationConfig parameter
     *
     * @param IntegrationConfig|null $config Optional config for instance-specific customization
     * @return string XML-formatted system prompt
     */
    public function getSystemPrompt(?IntegrationConfig $config = null): string;
}
