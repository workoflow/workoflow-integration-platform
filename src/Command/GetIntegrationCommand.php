<?php

namespace App\Command;

use App\Repository\IntegrationRepository;
use App\Service\EncryptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:integration:get',
    description: 'Get integration details by ID',
)]
class GetIntegrationCommand extends Command
{
    public function __construct(
        private IntegrationRepository $integrationRepository,
        private EncryptionService $encryptionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Integration ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $integrationId = $input->getArgument('id');

        $integration = $this->integrationRepository->find($integrationId);

        if (!$integration) {
            $io->error(sprintf('Integration with ID %s not found', $integrationId));
            return Command::FAILURE;
        }

        $io->title(sprintf('Integration #%d Details', $integration->getId()));

        $io->table(['Field', 'Value'], [
            ['ID', $integration->getId()],
            ['Name', $integration->getName()],
            ['Type', $integration->getType()],
            ['Active', $integration->isActive() ? 'Yes' : 'No'],
            ['Workflow User ID', $integration->getWorkflowUserId() ?? 'Not set'],
            ['User', $integration->getUser()->getEmail()],
            ['Organisation', $integration->getOrganisation() ? $integration->getOrganisation()->getName() : 'Not set'],
            ['Organisation UUID', $integration->getOrganisation() ? $integration->getOrganisation()->getUuid() : 'Not set'],
            ['Created At', $integration->getCreatedAt()->format('Y-m-d H:i:s')],
            ['Updated At', $integration->getUpdatedAt()->format('Y-m-d H:i:s')],
            ['Last Accessed', $integration->getLastAccessedAt() ? $integration->getLastAccessedAt()->format('Y-m-d H:i:s') : 'Never'],
        ]);

        // Show credentials status
        $io->section('Credentials Status');
        
        $encryptedCreds = $integration->getEncryptedCredentials();
        if (!$encryptedCreds) {
            $io->warning('No credentials stored');
        } else {
            try {
                $credentials = json_decode($this->encryptionService->decrypt($encryptedCreds), true);
                
                if ($integration->getType() === 'sharepoint') {
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
                } elseif ($integration->getType() === 'jira' || $integration->getType() === 'confluence') {
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

        // Show functions
        $io->section('Functions');
        $functions = $integration->getFunctions();
        
        if ($functions->isEmpty()) {
            $io->warning('No functions configured');
        } else {
            $functionData = [];
            foreach ($functions as $function) {
                $functionData[] = [
                    $function->getFunctionName(),
                    $function->isActive() ? 'Active' : 'Inactive',
                    substr($function->getDescription(), 0, 50) . '...'
                ];
            }
            $io->table(['Function Name', 'Status', 'Description'], $functionData);
        }

        // Show API usage example
        $io->section('API Usage Example');
        
        $orgUuid = $integration->getOrganisation() ? $integration->getOrganisation()->getUuid() : 'YOUR_ORG_UUID';
        $workflowUserId = $integration->getWorkflowUserId() ?? 'YOUR_WORKFLOW_USER_ID';
        
        $io->text('List tools:');
        $io->text(sprintf(
            'curl -X GET "http://localhost:3979/api/integration/%s/tools?id=%s" -H "Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw=="',
            $orgUuid,
            $workflowUserId
        ));
        
        if ($integration->getType() === 'sharepoint') {
            $io->text("\nExecute SharePoint search:");
            $io->text(sprintf(
                'curl -X POST "http://localhost:3979/api/integration/%s/execute?id=%s" -H "Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==" -H "Content-Type: application/json" -d \'{"tool_id": "sharepoint_search_documents_%d", "parameters": {"query": "test", "limit": 10}}\'',
                $orgUuid,
                $workflowUserId,
                $integration->getId()
            ));
            
            if (!$encryptedCreds || !isset($credentials['access_token'])) {
                $io->warning('SharePoint integration needs to be connected via OAuth2:');
                $io->text(sprintf('http://localhost:3979/integrations/sharepoint/auth/%d', $integration->getId()));
            }
        }

        return Command::SUCCESS;
    }
}