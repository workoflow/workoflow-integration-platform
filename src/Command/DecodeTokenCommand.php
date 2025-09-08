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
    name: 'app:sharepoint:decode-token',
    description: 'Decode SharePoint JWT token',
)]
class DecodeTokenCommand extends Command
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

        if (!$integration || $integration->getType() !== 'sharepoint') {
            $io->error('SharePoint integration not found');
            return Command::FAILURE;
        }

        $credentials = json_decode($this->encryptionService->decrypt($integration->getEncryptedCredentials()), true);

        $io->title('SharePoint Token Analysis');
        
        $token = $credentials['access_token'];
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            $io->error('Invalid JWT token format');
            return Command::FAILURE;
        }
        
        // Decode header
        $header = json_decode(base64_decode($parts[0]), true);
        $io->section('Token Header');
        $io->text(json_encode($header, JSON_PRETTY_PRINT));
        
        // Decode payload
        $payload = json_decode(base64_decode($parts[1]), true);
        $io->section('Token Payload');
        $io->text(json_encode($payload, JSON_PRETTY_PRINT));
        
        // Check important fields
        $io->section('Token Analysis');
        $io->table(['Field', 'Value'], [
            ['Audience (aud)', $payload['aud'] ?? 'Not set'],
            ['Issuer (iss)', $payload['iss'] ?? 'Not set'],
            ['Scopes (scp)', $payload['scp'] ?? 'Not set'],
            ['App ID (appid)', $payload['appid'] ?? 'Not set'],
            ['Tenant ID (tid)', $payload['tid'] ?? 'Not set'],
            ['Expires (exp)', isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : 'Not set'],
            ['Not Before (nbf)', isset($payload['nbf']) ? date('Y-m-d H:i:s', $payload['nbf']) : 'Not set'],
        ]);
        
        // Check if token is for Microsoft Graph
        if (isset($payload['aud']) && $payload['aud'] !== 'https://graph.microsoft.com' && $payload['aud'] !== '00000003-0000-0000-c000-000000000000') {
            $io->warning('Token audience is not Microsoft Graph! Expected "https://graph.microsoft.com" or "00000003-0000-0000-c000-000000000000", got: ' . $payload['aud']);
        }
        
        // Check scopes
        if (isset($payload['scp'])) {
            $scopes = explode(' ', $payload['scp']);
            $io->section('Granted Scopes');
            foreach ($scopes as $scope) {
                $io->text('- ' . $scope);
            }
        }

        return Command::SUCCESS;
    }
}