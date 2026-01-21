<?php

namespace App\Integration;

/**
 * Interface for platform-level skills that don't require user-specific credentials
 * These are system tools available to all users (e.g., ShareFile, PDF Generator, Web Search)
 */
interface PlatformSkillInterface extends IntegrationInterface
{
    // Platform skills inherit all methods from IntegrationInterface
    // but don't require system prompts as they're simple utility tools
}
