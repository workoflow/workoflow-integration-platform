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

            // ================================================================
            // OPPORTUNITY TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_opportunity',
                'Create a new sales opportunity in SAP C4C. Returns: Object with ObjectID (auto-generated), Name, AccountPartyID, MainContactPartyID, ExpectedRevenueAmount, SalesPhaseCode, StatusCode, ProbabilityPercent, and all other opportunity fields',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity name/subject (required)'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C account ObjectID to link this opportunity to'
                    ],
                    [
                        'name' => 'main_contact_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C contact ObjectID for primary contact'
                    ],
                    [
                        'name' => 'expected_revenue_amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Expected revenue/deal value (numeric string)'
                    ],
                    [
                        'name' => 'currency_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Currency code (e.g., "EUR", "USD", "GBP")'
                    ],
                    [
                        'name' => 'sales_phase_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Sales phase/stage code'
                    ],
                    [
                        'name' => 'probability_percent',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Win probability percentage (0-100)'
                    ],
                    [
                        'name' => 'expected_closing_date',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Expected close date (ISO format: YYYY-MM-DD)'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C opportunity fields'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_opportunity',
                'Retrieve detailed information about a specific opportunity by its ObjectID. Returns: Object with ObjectID, Name, AccountPartyID, MainContactPartyID, ExpectedRevenueAmount, SalesPhaseCode, StatusCode, ProbabilityPercent, ExpectedClosingDate, and all other opportunity fields',
                [
                    [
                        'name' => 'opportunity_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_opportunities',
                'Search opportunities using OData filter syntax. Returns: Object with opportunities array, count, pagination metadata. Example filters: "StatusCode eq \'2\'", "AccountPartyID eq \'XXX\'"',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Use single quotes for string values. Supports: eq, ne, gt, lt, and, or. Leave empty to retrieve all opportunities.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
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
                        'description' => 'OData $orderby clause (e.g., "Name asc", "ExpectedClosingDate desc")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_opportunity',
                'Update an existing opportunity\'s fields. Returns: Updated opportunity object. Note: Only fields with sap:updatable="true" can be modified.',
                [
                    [
                        'name' => 'opportunity_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New opportunity name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New account ObjectID to link'
                    ],
                    [
                        'name' => 'main_contact_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New contact ObjectID'
                    ],
                    [
                        'name' => 'expected_revenue_amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New expected revenue amount'
                    ],
                    [
                        'name' => 'sales_phase_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New sales phase code'
                    ],
                    [
                        'name' => 'status_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New status code'
                    ],
                    [
                        'name' => 'probability_percent',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New probability percentage'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional fields to update'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_opportunities',
                'Get a paginated list of all opportunities without filters. Returns: Object with opportunities array, count, pagination metadata',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // ACCOUNT TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_account',
                'Create a new corporate account in SAP C4C. Returns: Object with ObjectID (auto-generated), AccountID, Name, StatusCode, CountryCode, City, and all other account fields',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Company/account name (required)'
                    ],
                    [
                        'name' => 'name2',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional name line'
                    ],
                    [
                        'name' => 'role_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Account role code'
                    ],
                    [
                        'name' => 'country_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'ISO country code (e.g., "DE", "US", "GB")'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'City name'
                    ],
                    [
                        'name' => 'street',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Street address'
                    ],
                    [
                        'name' => 'postal_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Postal/ZIP code'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Phone number'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Email address'
                    ],
                    [
                        'name' => 'fax',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Fax number'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C account fields'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_account',
                'Retrieve detailed information about a specific account by its ObjectID. Returns: Object with ObjectID, AccountID, Name, StatusCode, CountryCode, City, Street, and all other account fields',
                [
                    [
                        'name' => 'account_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Account ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ],
                    [
                        'name' => 'expand_contacts',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Include linked contacts in response (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_accounts',
                'Search accounts using OData filter syntax. Returns: Object with accounts array, count, pagination metadata. Example filters: "Name eq \'ACME Corp\'", "CountryCode eq \'DE\'"',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Use single quotes for string values. Leave empty to retrieve all accounts.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
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
                        'description' => 'OData $orderby clause (e.g., "Name asc", "CreatedOn desc")'
                    ],
                    [
                        'name' => 'expand_contacts',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Include linked contacts in response (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_account',
                'Update an existing account\'s fields. Returns: Updated account object.',
                [
                    [
                        'name' => 'account_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Account ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New account name'
                    ],
                    [
                        'name' => 'name2',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New additional name'
                    ],
                    [
                        'name' => 'country_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New country code'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New city'
                    ],
                    [
                        'name' => 'street',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New street address'
                    ],
                    [
                        'name' => 'postal_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New postal code'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New email address'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional fields to update'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_accounts',
                'Get a paginated list of all accounts without filters. Returns: Object with accounts array, count, pagination metadata',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // CONTACT TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_contact',
                'Create a new contact in SAP C4C. Returns: Object with ObjectID (auto-generated), ContactID, FirstName, LastName, AccountPartyID, Email, Phone, and all other contact fields',
                [
                    [
                        'name' => 'last_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact last name (required)'
                    ],
                    [
                        'name' => 'first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact first name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C account ObjectID to link this contact to'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Email address'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Phone number'
                    ],
                    [
                        'name' => 'mobile',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Mobile phone number'
                    ],
                    [
                        'name' => 'job_title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Job title/position'
                    ],
                    [
                        'name' => 'gender_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Gender code (e.g., "1" for male, "2" for female)'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C contact fields'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_contact',
                'Retrieve detailed information about a specific contact by its ObjectID. Returns: Object with ObjectID, ContactID, FirstName, LastName, AccountPartyID, Email, Phone, JobTitle, and all other contact fields',
                [
                    [
                        'name' => 'contact_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_contacts',
                'Search contacts using OData filter syntax. Returns: Object with contacts array, count, pagination metadata. Example filters: "LastName eq \'Smith\'", "AccountPartyID eq \'XXX\'"',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Use single quotes for string values. Leave empty to retrieve all contacts.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
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
                        'description' => 'OData $orderby clause (e.g., "LastName asc", "CreatedOn desc")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_contact',
                'Update an existing contact\'s fields. Returns: Updated contact object.',
                [
                    [
                        'name' => 'contact_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact ObjectID to update'
                    ],
                    [
                        'name' => 'first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New first name'
                    ],
                    [
                        'name' => 'last_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New last name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New account ObjectID to link'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New email address'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'mobile',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New mobile number'
                    ],
                    [
                        'name' => 'job_title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New job title'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional fields to update'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_contacts',
                'Get a paginated list of all contacts without filters. Returns: Object with contacts array, count, pagination metadata',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
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
            // Lead tools
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

            // Opportunity tools
            'c4c_create_opportunity' => $this->createOpportunity($credentials, $parameters),
            'c4c_get_opportunity' => $this->sapC4cService->getOpportunity(
                $credentials,
                $parameters['opportunity_id']
            ),
            'c4c_search_opportunities' => $this->sapC4cService->searchOpportunities(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null
            ),
            'c4c_update_opportunity' => $this->updateOpportunity($credentials, $parameters),
            'c4c_list_opportunities' => $this->sapC4cService->listOpportunities(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),

            // Account tools
            'c4c_create_account' => $this->createAccount($credentials, $parameters),
            'c4c_get_account' => $this->sapC4cService->getAccount(
                $credentials,
                $parameters['account_id'],
                $parameters['expand_contacts'] ?? false
            ),
            'c4c_search_accounts' => $this->sapC4cService->searchAccounts(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null,
                $parameters['expand_contacts'] ?? false
            ),
            'c4c_update_account' => $this->updateAccount($credentials, $parameters),
            'c4c_list_accounts' => $this->sapC4cService->listAccounts(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),

            // Contact tools
            'c4c_create_contact' => $this->createContact($credentials, $parameters),
            'c4c_get_contact' => $this->sapC4cService->getContact(
                $credentials,
                $parameters['contact_id']
            ),
            'c4c_search_contacts' => $this->sapC4cService->searchContacts(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null
            ),
            'c4c_update_contact' => $this->updateContact($credentials, $parameters),
            'c4c_list_contacts' => $this->sapC4cService->listContacts(
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

    // ========================================================================
    // OPPORTUNITY HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build opportunity data for creation
     */
    private function createOpportunity(array $credentials, array $parameters): array
    {
        $opportunityData = [
            'Name' => $parameters['name'],
        ];

        if (isset($parameters['account_party_id'])) {
            $opportunityData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['main_contact_party_id'])) {
            $opportunityData['MainContactPartyID'] = $parameters['main_contact_party_id'];
        }

        if (isset($parameters['expected_revenue_amount'])) {
            $opportunityData['ExpectedRevenueAmount'] = $parameters['expected_revenue_amount'];
        }

        if (isset($parameters['currency_code'])) {
            $opportunityData['ExpectedRevenueAmountCurrencyCode'] = $parameters['currency_code'];
        }

        if (isset($parameters['sales_phase_code'])) {
            $opportunityData['SalesPhaseCode'] = $parameters['sales_phase_code'];
        }

        if (isset($parameters['probability_percent'])) {
            $opportunityData['ProbabilityPercent'] = $parameters['probability_percent'];
        }

        if (isset($parameters['expected_closing_date'])) {
            $opportunityData['ExpectedClosingDate'] = $parameters['expected_closing_date'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $opportunityData = array_merge($opportunityData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createOpportunity($credentials, $opportunityData);
    }

    /**
     * Helper method to build opportunity data for update
     */
    private function updateOpportunity(array $credentials, array $parameters): array
    {
        $opportunityData = [];

        if (isset($parameters['name'])) {
            $opportunityData['Name'] = $parameters['name'];
        }

        if (isset($parameters['account_party_id'])) {
            $opportunityData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['main_contact_party_id'])) {
            $opportunityData['MainContactPartyID'] = $parameters['main_contact_party_id'];
        }

        if (isset($parameters['expected_revenue_amount'])) {
            $opportunityData['ExpectedRevenueAmount'] = $parameters['expected_revenue_amount'];
        }

        if (isset($parameters['sales_phase_code'])) {
            $opportunityData['SalesPhaseCode'] = $parameters['sales_phase_code'];
        }

        if (isset($parameters['status_code'])) {
            $opportunityData['StatusCode'] = $parameters['status_code'];
        }

        if (isset($parameters['probability_percent'])) {
            $opportunityData['ProbabilityPercent'] = $parameters['probability_percent'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $opportunityData = array_merge($opportunityData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateOpportunity(
            $credentials,
            $parameters['opportunity_id'],
            $opportunityData
        );
    }

    // ========================================================================
    // ACCOUNT HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build account data for creation
     */
    private function createAccount(array $credentials, array $parameters): array
    {
        $accountData = [
            'Name' => $parameters['name'],
        ];

        if (isset($parameters['name2'])) {
            $accountData['Name2'] = $parameters['name2'];
        }

        if (isset($parameters['role_code'])) {
            $accountData['RoleCode'] = $parameters['role_code'];
        }

        if (isset($parameters['country_code'])) {
            $accountData['CountryCode'] = $parameters['country_code'];
        }

        if (isset($parameters['city'])) {
            $accountData['City'] = $parameters['city'];
        }

        if (isset($parameters['street'])) {
            $accountData['Street'] = $parameters['street'];
        }

        if (isset($parameters['postal_code'])) {
            $accountData['PostalCode'] = $parameters['postal_code'];
        }

        if (isset($parameters['phone'])) {
            $accountData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['email'])) {
            $accountData['Email'] = $parameters['email'];
        }

        if (isset($parameters['fax'])) {
            $accountData['Fax'] = $parameters['fax'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $accountData = array_merge($accountData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createAccount($credentials, $accountData);
    }

    /**
     * Helper method to build account data for update
     */
    private function updateAccount(array $credentials, array $parameters): array
    {
        $accountData = [];

        if (isset($parameters['name'])) {
            $accountData['Name'] = $parameters['name'];
        }

        if (isset($parameters['name2'])) {
            $accountData['Name2'] = $parameters['name2'];
        }

        if (isset($parameters['country_code'])) {
            $accountData['CountryCode'] = $parameters['country_code'];
        }

        if (isset($parameters['city'])) {
            $accountData['City'] = $parameters['city'];
        }

        if (isset($parameters['street'])) {
            $accountData['Street'] = $parameters['street'];
        }

        if (isset($parameters['postal_code'])) {
            $accountData['PostalCode'] = $parameters['postal_code'];
        }

        if (isset($parameters['phone'])) {
            $accountData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['email'])) {
            $accountData['Email'] = $parameters['email'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $accountData = array_merge($accountData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateAccount(
            $credentials,
            $parameters['account_id'],
            $accountData
        );
    }

    // ========================================================================
    // CONTACT HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build contact data for creation
     */
    private function createContact(array $credentials, array $parameters): array
    {
        $contactData = [
            'LastName' => $parameters['last_name'],
        ];

        if (isset($parameters['first_name'])) {
            $contactData['FirstName'] = $parameters['first_name'];
        }

        if (isset($parameters['account_party_id'])) {
            $contactData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['email'])) {
            $contactData['Email'] = $parameters['email'];
        }

        if (isset($parameters['phone'])) {
            $contactData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['mobile'])) {
            $contactData['Mobile'] = $parameters['mobile'];
        }

        if (isset($parameters['job_title'])) {
            $contactData['JobTitle'] = $parameters['job_title'];
        }

        if (isset($parameters['gender_code'])) {
            $contactData['GenderCode'] = $parameters['gender_code'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $contactData = array_merge($contactData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createContact($credentials, $contactData);
    }

    /**
     * Helper method to build contact data for update
     */
    private function updateContact(array $credentials, array $parameters): array
    {
        $contactData = [];

        if (isset($parameters['first_name'])) {
            $contactData['FirstName'] = $parameters['first_name'];
        }

        if (isset($parameters['last_name'])) {
            $contactData['LastName'] = $parameters['last_name'];
        }

        if (isset($parameters['account_party_id'])) {
            $contactData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['email'])) {
            $contactData['Email'] = $parameters['email'];
        }

        if (isset($parameters['phone'])) {
            $contactData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['mobile'])) {
            $contactData['Mobile'] = $parameters['mobile'];
        }

        if (isset($parameters['job_title'])) {
            $contactData['JobTitle'] = $parameters['job_title'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $contactData = array_merge($contactData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateContact(
            $credentials,
            $parameters['contact_id'],
            $contactData
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
