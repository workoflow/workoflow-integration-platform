<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SapSacService;
use Twig\Environment;

/**
 * SAP Analytics Cloud (SAC) Integration
 *
 * Enables AI agents to query analytics data from SAP SAC models.
 * Uses two-legged OAuth 2.0 (Client Credentials Grant) for authentication.
 *
 * Features:
 * - Model discovery (list models, get metadata)
 * - Data querying (query with filters, get dimension members)
 * - Story access (list and get dashboards/reports)
 */
class SapSacIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SapSacService $sapSacService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'sap_sac';
    }

    public function getName(): string
    {
        return 'SAP Analytics Cloud';
    }

    public function getTools(): array
    {
        return [
            // ================================================================
            // MODEL DISCOVERY TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_list_models',
                'List all available analytics models/data providers in SAP SAC. Returns: Array of models with id, name, description. Use this first to discover what data is available.',
                []
            ),
            new ToolDefinition(
                'sac_get_model_metadata',
                'Get detailed metadata for a specific analytics model, including available dimensions (e.g., Customer, Product, Time) and measures (e.g., Revenue, Quantity). Use this to understand the model structure before querying data.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID from sac_list_models (e.g., "t.sac.model:SAC_MODEL_ID")'
                    ]
                ]
            ),

            // ================================================================
            // DATA QUERY TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_query_model_data',
                'Query fact/master data from a SAC model with optional OData filters. Use this to retrieve actual analytics data like revenue, sales, etc. Returns: Array of data records matching the query.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID to query'
                    ],
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter expression. Examples: "Customer eq \'ACME\'", "Year eq \'2024\' and Month eq \'01\'", "Revenue gt 10000". Use dimension names from metadata.'
                    ],
                    [
                        'name' => 'select',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of properties to return (dimensions and measures). Example: "Customer,Product,Revenue,Quantity". If omitted, returns all properties.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results to return (default: 100, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sac_get_dimension_members',
                'Get all valid values/members for a specific dimension. Use this to discover valid filter values (e.g., list all customer names, product IDs). Essential before filtering to know what values exist.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID'
                    ],
                    [
                        'name' => 'dimension',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Dimension name from model metadata (e.g., "Customer", "Product", "Time", "Region")'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of members to return (default: 100)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of members to skip for pagination (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // STORY (DASHBOARD) TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_list_stories',
                'List available stories (dashboards/reports) in SAP SAC. Returns: Array of stories with id, name, description, created/modified times.',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of stories to return (default: 50)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of stories to skip for pagination (default: 0)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sac_get_story',
                'Get detailed information about a specific story, including associated data models. Use this to understand what data a dashboard visualizes.',
                [
                    [
                        'name' => 'story_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Story ID from sac_list_stories'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SAP SAC integration requires credentials');
        }

        return match ($toolName) {
            // Model discovery tools
            'sac_list_models' => $this->sapSacService->listModels($credentials),
            'sac_get_model_metadata' => $this->sapSacService->getModelMetadata(
                $credentials,
                $parameters['model_id']
            ),

            // Data query tools
            'sac_query_model_data' => $this->sapSacService->queryModelData(
                $credentials,
                $parameters['model_id'],
                $parameters['filter'] ?? null,
                $parameters['select'] ?? null,
                $parameters['top'] ?? 100,
                $parameters['skip'] ?? 0
            ),
            'sac_get_dimension_members' => $this->sapSacService->getDimensionMembers(
                $credentials,
                $parameters['model_id'],
                $parameters['dimension'],
                $parameters['top'] ?? 100,
                $parameters['skip'] ?? 0
            ),

            // Story tools
            'sac_list_stories' => $this->sapSacService->listStories(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),
            'sac_get_story' => $this->sapSacService->getStory(
                $credentials,
                $parameters['story_id']
            ),

            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if all required fields are present and not empty
        if (
            empty($credentials['tenant_url'])
            || empty($credentials['client_id'])
            || empty($credentials['client_secret'])
        ) {
            return false;
        }

        // Use SapSacService to validate credentials by testing connection
        try {
            $result = $this->sapSacService->testConnectionDetailed($credentials);
            return $result['success'] ?? false;
        } catch (\Exception) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'tenant_url',
                'url',
                'SAP SAC Tenant URL',
                'https://mycompany.eu10.hcs.cloud.sap',
                true,
                'Your SAP Analytics Cloud tenant URL (e.g., https://mycompany.eu10.hcs.cloud.sap)'
            ),
            new CredentialField(
                'client_id',
                'text',
                'OAuth Client ID',
                null,
                true,
                'Client ID from SAC Admin > System Administration > App Integration'
            ),
            new CredentialField(
                'client_secret',
                'password',
                'OAuth Client Secret',
                null,
                true,
                'Client Secret from SAC App Integration. Stored encrypted.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sap_sac.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    public function isExperimental(): bool
    {
        return true;
    }
}
