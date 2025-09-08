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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:sharepoint:test-token',
    description: 'Test SharePoint token directly',
)]
class TestSharePointTokenCommand extends Command
{
    public function __construct(
        private IntegrationRepository $integrationRepository,
        private EncryptionService $encryptionService,
        private HttpClientInterface $httpClient
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

        if (!$integration || $integration->getType() !== 'sharepoint') {
            $io->error('SharePoint integration not found');
            return Command::FAILURE;
        }

        $credentials = json_decode($this->encryptionService->decrypt($integration->getEncryptedCredentials()), true);

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