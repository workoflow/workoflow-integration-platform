<?php

namespace App\Command;

use App\Repository\IntegrationConfigRepository;
use App\Service\EncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:integration:get',
    description: 'Get integration config details by ID',
)]
class GetIntegrationCommand extends Command
{
    public function __construct(
        private IntegrationConfigRepository $integrationConfigRepository,
        private EncryptionService $encryptionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Integration Config ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configId = $input->getArgument('id');

        $config = $this->integrationConfigRepository->find($configId);

        if (!$config) {
            $io->error(sprintf('Integration config with ID %s not found', $configId));
            return Command::FAILURE;
        }

        $io->title(sprintf('Integration Config #%d Details', $config->getId()));

        $io->table(['Field', 'Value'], [
            ['ID', $config->getId()],
            ['Name', $config->getName()],
            ['Type', $config->getIntegrationType()],
            ['Active', $config->isActive() ? 'Yes' : 'No'],
            ['User', $config->getUser() ? $config->getUser()->getEmail() : 'System/Shared'],
            ['Organisation', $config->getOrganisation() ? $config->getOrganisation()->getName() : 'Not set'],
            ['Organisation UUID', $config->getOrganisation() ? $config->getOrganisation()->getUuid() : 'Not set'],
            ['Created At', $config->getCreatedAt() ? $config->getCreatedAt()->format('Y-m-d H:i:s') : 'Not set'],
            ['Updated At', $config->getUpdatedAt() ? $config->getUpdatedAt()->format('Y-m-d H:i:s') : 'Not set'],
            ['Last Accessed', $config->getLastAccessedAt() ? $config->getLastAccessedAt()->format('Y-m-d H:i:s') : 'Never'],
        ]);

        // Show credentials status
        $io->section('Credentials Status');

        $encryptedCreds = $config->getEncryptedCredentials();
        if (!$encryptedCreds) {
            $io->warning('No credentials stored');
        } else {
            try {
                $credentials = json_decode($this->encryptionService->decrypt($encryptedCreds), true);

                if ($config->getIntegrationType() === 'sharepoint') {
                    $io->table(['Credential Field', 'Status'], [
                        ['Access Token', isset($credentials['access_token']) ? 'Present' : 'Missing'],
                        ['Refresh Token', isset($credentials['refresh_token']) ? 'Present' : 'Missing'],
                        ['Expires At', isset($credentials['expires_at']) ? date('Y-m-d H:i:s', $credentials['expires_at']) : 'Not set'],
                        ['Token Expired', isset($credentials['expires_at']) ? (time() >= $credentials['expires_at'] ? 'Yes' : 'No') : 'Unknown'],
                        ['Client ID', isset($credentials['client_id']) ? 'Present' : 'Missing'],
                        ['Client Secret', isset($credentials['client_secret']) ? 'Present' : 'Missing'],
                        ['Tenant ID', isset($credentials['tenant_id']) ? 'Present' : 'Missing'],
                    ]);

                    // Show token preview (first 20 chars)
                    if (isset($credentials['access_token'])) {
                        $tokenPreview = substr($credentials['access_token'], 0, 20) . '...';
                        $io->text('Access Token Preview: ' . $tokenPreview);
                    }
                } elseif ($config->getIntegrationType() === 'jira' || $config->getIntegrationType() === 'confluence') {
                    $io->table(['Credential Field', 'Value'], [
                        ['URL', $credentials['url'] ?? 'Not set'],
                        ['Username', $credentials['username'] ?? 'Not set'],
                        ['API Token', isset($credentials['api_token']) ? '***hidden***' : 'Not set'],
                    ]);
                }
            } catch (\Exception $e) {
                $io->error('Failed to decrypt credentials: ' . $e->getMessage());
            }
        }

        // Show disabled tools
        $io->section('Tools Status');
        $disabledTools = $config->getDisabledTools();

        if (empty($disabledTools)) {
            $io->success('All tools are enabled');
        } else {
            $io->warning('Disabled tools:');
            foreach ($disabledTools as $tool) {
                $io->text('  - ' . $tool);
            }
        }

        // Show API usage example
        $io->section('API Usage Example');

        $orgUuid = $config->getOrganisation() ? $config->getOrganisation()->getUuid() : 'YOUR_ORG_UUID';

        $io->text('List tools:');
        $io->text(sprintf(
            'curl -X GET "http://localhost:3979/api/integrations/%s?workflow_user_id=YOUR_WORKFLOW_USER_ID" -H "Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw=="',
            $orgUuid
        ));

        if ($config->getIntegrationType() === 'sharepoint') {
            $io->text("\nExecute SharePoint search:");
            $io->text(sprintf(
                'curl -X POST "http://localhost:3979/api/integrations/%s/execute" -H "Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==" -H "Content-Type: application/json" -d \'{"tool_id": "sharepoint_search_documents_%d", "workflow_user_id": "YOUR_WORKFLOW_USER_ID", "parameters": {"query": "test", "limit": 10}}\'',
                $orgUuid,
                $config->getId()
            ));

            if (!$encryptedCreds || !isset($credentials['access_token'])) {
                $io->warning('SharePoint integration needs to be connected via OAuth2:');
                $io->text(sprintf('http://localhost:3979/integrations/sharepoint/auth/%d', $config->getId()));
            }
        }

        return Command::SUCCESS;
    }
}
