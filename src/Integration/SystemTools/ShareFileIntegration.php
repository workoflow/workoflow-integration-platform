<?php

namespace App\Integration\SystemTools;

use App\Integration\IntegrationInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\ShareFileService;

class ShareFileIntegration implements IntegrationInterface
{
    public function __construct(
        private ShareFileService $shareFileService
    ) {}

    public function getType(): string
    {
        return 'system.share_file';
    }

    public function getName(): string
    {
        return 'File Sharing';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'share_file',
                'Upload and share a file. Returns a signed URL that can be used to access the file.',
                [
                    [
                        'name' => 'binaryData',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Base64 encoded file content'
                    ],
                    [
                        'name' => 'fileName',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Original file name'
                    ],
                    [
                        'name' => 'contentType',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'MIME type of the file'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'share_file') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        return $this->shareFileService->shareFile(
            $parameters['organisationId'],
            $parameters['workflowUserId'],
            $parameters
        );
    }

    public function requiresCredentials(): bool
    {
        return false; // System tools don't require external service credentials
    }

    public function validateCredentials(array $credentials): bool
    {
        return true; // No external credentials needed (API Basic Auth still required)
    }

    public function getCredentialFields(): array
    {
        return []; // System tools don't need credential fields
    }
}