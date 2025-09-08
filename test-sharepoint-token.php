<?php
require_once 'vendor/autoload.php';

use App\Repository\IntegrationRepository;
use App\Service\EncryptionService;
use Symfony\Component\Dotenv\Dotenv;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env');

// Bootstrap Symfony kernel
$kernel = new \App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

// Get services
$integrationRepo = $container->get(IntegrationRepository::class);
$encryptionService = $container->get(EncryptionService::class);

// Get integration ID 2
$integration = $integrationRepo->find(2);

if (!$integration) {
    echo "Integration not found\n";
    exit(1);
}

// Decrypt credentials
$credentials = json_decode($encryptionService->decrypt($integration->getEncryptedCredentials()), true);

echo "=== SharePoint Token Test ===\n";
echo "Token preview: " . substr($credentials['access_token'], 0, 30) . "...\n";
echo "Expires at: " . date('Y-m-d H:i:s', $credentials['expires_at']) . "\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";
echo "Token expired: " . (time() >= $credentials['expires_at'] ? 'Yes' : 'No') . "\n\n";

// Test the token directly
$httpClient = HttpClient::create();

echo "Testing /me endpoint...\n";
try {
    $response = $httpClient->request('GET', 'https://graph.microsoft.com/v1.0/me', [
        'headers' => [
            'Authorization' => 'Bearer ' . $credentials['access_token'],
        ]
    ]);
    
    $statusCode = $response->getStatusCode();
    echo "Status: $statusCode\n";
    
    if ($statusCode === 200) {
        $data = $response->toArray();
        echo "Success! User: " . ($data['displayName'] ?? 'Unknown') . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nTesting /sites endpoint...\n";
try {
    $response = $httpClient->request('GET', 'https://graph.microsoft.com/v1.0/sites?search=test', [
        'headers' => [
            'Authorization' => 'Bearer ' . $credentials['access_token'],
        ]
    ]);
    
    $statusCode = $response->getStatusCode();
    echo "Status: $statusCode\n";
    
    if ($statusCode === 200) {
        $data = $response->toArray();
        echo "Success! Found " . count($data['value'] ?? []) . " sites\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    // Try to get more details about the error
    if (method_exists($e, 'getResponse')) {
        $response = $e->getResponse();
        echo "Response body: " . $response->getContent(false) . "\n";
    }
}