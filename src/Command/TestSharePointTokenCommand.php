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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:sharepoint:test-token',
    description: 'Test SharePoint token directly',
)]
class TestSharePointTokenCommand extends Command
{
    public function __construct(
        private IntegrationConfigRepository $integrationConfigRepository,
        private EncryptionService $encryptionService,
        private HttpClientInterface $httpClient
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

        if (!$config || $config->getIntegrationType() !== 'sharepoint') {
            $io->error('SharePoint integration config not found');
            return Command::FAILURE;
        }

        if (!$config->getEncryptedCredentials()) {
            $io->error('No credentials stored for this integration');
            return Command::FAILURE;
        }

        $credentials = json_decode($this->encryptionService->decrypt($config->getEncryptedCredentials()), true);

        if (!isset($credentials['access_token'])) {
            $io->error('No access token found in credentials');
            return Command::FAILURE;
        }

        $io->title('SharePoint Token Test');
        $io->text('Token preview: ' . substr($credentials['access_token'], 0, 30) . '...');
        $io->text('Expires at: ' . date('Y-m-d H:i:s', $credentials['expires_at']));
        $io->text('Current time: ' . date('Y-m-d H:i:s'));
        $io->text('Token expired: ' . (time() >= $credentials['expires_at'] ? 'Yes' : 'No'));

        $io->section('Testing /me endpoint');
        try {
            $response = $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials['access_token'],
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $io->success("Status: $statusCode");

            if ($statusCode === 200) {
                $data = $response->toArray();
                $io->text('User: ' . ($data['displayName'] ?? 'Unknown'));
                $io->text('Email: ' . ($data['mail'] ?? $data['userPrincipalName'] ?? 'Unknown'));
            }
        } catch (\Exception $e) {
            $io->error('Failed: ' . $e->getMessage());
        }

        $io->section('Testing /sites endpoint with search');
        try {
            $response = $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/sites', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials['access_token'],
                ],
                'query' => [
                    'search' => 'test',
                    '$top' => 5
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $io->success("Status: $statusCode");

            if ($statusCode === 200) {
                $data = $response->toArray();
                $io->text('Found ' . count($data['value'] ?? []) . ' sites');
            }
        } catch (\Exception $e) {
            $io->error('Failed: ' . $e->getMessage());

            // Try to get response details
            try {
                $response = $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/sites?search=test', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $credentials['access_token'],
                    ]
                ]);
                $response->getContent();
            } catch (\Exception $e2) {
                if (method_exists($e2, 'getResponse')) {
                    $errorResponse = $e2->getResponse();
                    $io->error('Response: ' . $errorResponse->getContent(false));
                }
            }
        }

        return Command::SUCCESS;
    }
}
