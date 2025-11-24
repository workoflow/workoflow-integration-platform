<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SapC4cService;
use Twig\Environment;

class SapC4cIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SapC4cService $sapC4cService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'sap_c4c';
    }

    public function getName(): string
    {
        return 'SAP Cloud for Customer';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'c4c_create_lead',
                'Create a new lead in SAP C4C. Returns: Object with ObjectID (auto-generated ID), Name, StatusCode, QualificationLevelCode, AccountPartyID, ContactFirstName, ContactLastName, Company, OriginTypeCode, and all other lead fields',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead name/title (required)'
                    ],
                    [
                        'name' => 'contact_last_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact person last name (required)'
                    ],
                    [
                        'name' => 'contact_first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact person first name'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company/organization name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C account party ID (if linking to existing account)'
                    ],
                    [
                        'name' => 'qualification_level_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead qualification level (e.g., "01", "02", "03", "04"). When set, StatusCode automatically becomes "Qualified"'
                    ],
                    [
                        'name' => 'origin_type_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead source/origin code (e.g., "1" for direct contact)'
                    ],
                    [
                        'name' => 'group_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead category/group code'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C lead fields (for custom fields or other standard fields not listed above)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_lead',
                'Retrieve detailed information about a specific lead by its ObjectID. Returns: Object with ObjectID, Name, StatusCode, QualificationLevelCode, AccountPartyID, ContactFirstName, ContactLastName, Company, OriginTypeCode, GroupCode, OwnerPartyID, CreatedOn, ChangedOn, and all other lead fields',
                [
                    [
                        'name' => 'lead_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_leads',
                'Search leads using OData filter syntax. Returns: Object with leads array, count, pagination metadata (has_more, next_skip). Example filters: "StatusCode eq \'2\'" (status equals 2), "Company eq \'ACME Corp\'" (company equals ACME Corp), "QualificationLevelCode eq \'03\'" (qualification level 03)',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query (e.g., "StatusCode eq \'2\'", "Company eq \'ACME Corp\'"). Use single quotes for string values. Supports: eq (equals), ne (not equals), gt (greater than), lt (less than), and (logical AND), or (logical OR). Leave empty to retrieve all leads.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results to return (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ],
                    [
                        'name' => 'orderby',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $orderby clause for sorting (e.g., "Name asc", "CreatedOn desc")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_lead',
                'Update an existing lead\'s fields. Returns: Updated lead object with ObjectID and all modified fields. Note: You can only update fields that have sap:updatable="true" in the SAP C4C metadata.',
                [
                    [
                        'name' => 'lead_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New lead name/title'
                    ],
                    [
                        'name' => 'status_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New status code (e.g., "2" for qualified, "3" for converted)'
                    ],
                    [
                        'name' => 'qualification_level_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New qualification level (e.g., "01", "02", "03", "04")'
                    ],
                    [
                        'name' => 'contact_first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New contact first name'
                    ],
                    [
                        'name' => 'contact_last_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New contact last name'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New company name'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C lead fields to update (for custom fields or other standard fields not listed above)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_leads',
                'Get a paginated list of all leads without filters. Simpler than search for browsing leads. Returns: Object with leads array, count, pagination metadata (has_more, next_skip)',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0). Use next_skip from previous response.'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SAP C4C integration requires credentials');
        }

        return match ($toolName) {
            'c4c_create_lead' => $this->createLead($credentials, $parameters),
            'c4c_get_lead' => $this->sapC4cService->getLead(
                $credentials,
                $parameters['lead_id']
            ),
            'c4c_search_leads' => $this->sapC4cService->searchLeads(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null
            ),
            'c4c_update_lead' => $this->updateLead($credentials, $parameters),
            'c4c_list_leads' => $this->sapC4cService->listLeads(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),
            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    /**
     * Helper method to build lead data for creation
     */
    private function createLead(array $credentials, array $parameters): array
    {
        $leadData = [
            'Name' => $parameters['name'],
            'ContactLastName' => $parameters['contact_last_name'],
        ];

        // Add optional standard fields
        if (isset($parameters['contact_first_name'])) {
            $leadData['ContactFirstName'] = $parameters['contact_first_name'];
        }

        if (isset($parameters['company'])) {
            $leadData['Company'] = $parameters['company'];
        }

        if (isset($parameters['account_party_id'])) {
            $leadData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['qualification_level_code'])) {
            $leadData['QualificationLevelCode'] = $parameters['qualification_level_code'];
        }

        if (isset($parameters['origin_type_code'])) {
            $leadData['OriginTypeCode'] = $parameters['origin_type_code'];
        }

        if (isset($parameters['group_code'])) {
            $leadData['GroupCode'] = $parameters['group_code'];
        }

        // Merge additional fields
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $leadData = array_merge($leadData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createLead($credentials, $leadData);
    }

    /**
     * Helper method to build lead data for update
     */
    private function updateLead(array $credentials, array $parameters): array
    {
        $leadData = [];

        // Add optional fields if provided
        if (isset($parameters['name'])) {
            $leadData['Name'] = $parameters['name'];
        }

        if (isset($parameters['status_code'])) {
            $leadData['StatusCode'] = $parameters['status_code'];
        }

        if (isset($parameters['qualification_level_code'])) {
            $leadData['QualificationLevelCode'] = $parameters['qualification_level_code'];
        }

        if (isset($parameters['contact_first_name'])) {
            $leadData['ContactFirstName'] = $parameters['contact_first_name'];
        }

        if (isset($parameters['contact_last_name'])) {
            $leadData['ContactLastName'] = $parameters['contact_last_name'];
        }

        if (isset($parameters['company'])) {
            $leadData['Company'] = $parameters['company'];
        }

        // Merge additional fields
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $leadData = array_merge($leadData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateLead(
            $credentials,
            $parameters['lead_id'],
            $leadData
        );
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if all required fields are present and not empty
        if (empty($credentials['base_url']) || empty($credentials['username']) || empty($credentials['password'])) {
            return false;
        }

        // Use SapC4cService to validate credentials
        try {
            return $this->sapC4cService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'base_url',
                'url',
                'SAP C4C Base URL',
                'https://myXXXXXX.crm.ondemand.com',
                true,
                'Your SAP Cloud for Customer instance URL (e.g., https://myXXXXXX.crm.ondemand.com)'
            ),
            new CredentialField(
                'username',
                'text',
                'Username',
                'user@company.com',
                true,
                'Your SAP C4C username or email'
            ),
            new CredentialField(
                'password',
                'password',
                'Password',
                null,
                true,
                'Your SAP C4C password. Credentials will be encrypted and stored securely.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sap_c4c.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }
    public function isExperimental(): bool
    {
        return false;
    }
}
