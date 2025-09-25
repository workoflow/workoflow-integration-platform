<?php

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

class MagicLinkService
{
    private string $secret;
    private string $algorithm = 'HS256';
    private int $ttl = 86400; // 24 hours
    private LoggerInterface $logger;

    public function __construct(
        string $magicLinkSecret,
        LoggerInterface $logger
    ) {
        $this->secret = $magicLinkSecret;
        $this->logger = $logger;
    }

    public function generateToken(string $name, string $orgUuid, string $workflowUserId): string
    {
        $payload = [
            'name' => $name,
            'org_uuid' => $orgUuid,
            'workflow_user_id' => $workflowUserId,
            'iat' => time(),
            'exp' => time() + $this->ttl,
            'type' => 'magic_link'
        ];

        $this->logger->info('Generating magic link token', [
            'name' => $name,
            'org_uuid' => $orgUuid,
            'workflow_user_id' => $workflowUserId
        ]);

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Convert object to array
            $payload = (array) $decoded;

            // Verify token type
            if (!isset($payload['type']) || $payload['type'] !== 'magic_link') {
                $this->logger->warning('Invalid token type', ['payload' => $payload]);
                return null;
            }

            // Verify required fields
            if (!isset($payload['name']) || !isset($payload['org_uuid']) || !isset($payload['workflow_user_id'])) {
                $this->logger->warning('Missing required fields in token', ['payload' => $payload]);
                return null;
            }

            $this->logger->info('Successfully validated magic link token', [
                'name' => $payload['name'],
                'org_uuid' => $payload['org_uuid'],
                'workflow_user_id' => $payload['workflow_user_id']
            ]);

            return $payload;
        } catch (\Exception $e) {
            $this->logger->error('Failed to validate magic link token', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function generateMagicLink(string $name, string $orgUuid, string $baseUrl, string $workflowUserId): string
    {
        $token = $this->generateToken($name, $orgUuid, $workflowUserId);
        return $baseUrl . '/auth/magic-link?token=' . $token;
    }
}
