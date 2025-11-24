<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private RequestStack $requestStack
    ) {
    }

    public function log(string $action, ?User $user = null, array $data = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $auditLog = new AuditLog();
        $auditLog->setAction($action);
        $auditLog->setData($data);

        if ($user) {
            $auditLog->setUser($user);
            if ($user->getOrganisation()) {
                $auditLog->setOrganisation($user->getOrganisation());
            }
        }

        if ($request) {
            $auditLog->setIp($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }

        $this->auditLogRepository->save($auditLog, true);
    }

    public function logWithOrganisation(string $action, \App\Entity\Organisation $organisation, ?User $user = null, array $data = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $auditLog = new AuditLog();
        $auditLog->setAction($action);
        $auditLog->setData($data);
        $auditLog->setOrganisation($organisation);

        if ($user) {
            $auditLog->setUser($user);
        }

        if ($request) {
            $auditLog->setIp($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }

        $this->auditLogRepository->save($auditLog, true);
    }

    /**
     * Sanitize sensitive data by redacting credential-like fields
     *
     * @param array $data
     * @return array
     */
    public function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'api_key', 'apikey', 'secret', 'credentials', 'authorization'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if key contains any sensitive pattern
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Truncate data to specified length to prevent database bloat
     *
     * @param mixed $data
     * @param int $maxLength Maximum length in characters (default 5000 = ~5KB)
     * @return mixed
     */
    public function truncateData(mixed $data, int $maxLength = 5000): mixed
    {
        if ($data === null) {
            return null;
        }

        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonData === false) {
            return ['error' => 'Unable to encode data'];
        }

        if (strlen($jsonData) <= $maxLength) {
            return $data;
        }

        // Truncate and add indicator
        $truncated = substr($jsonData, 0, $maxLength);
        $truncated .= "\n... [Response truncated at {$maxLength} characters]";

        return [
            '_truncated' => true,
            '_original_size' => strlen($jsonData),
            '_preview' => $truncated,
        ];
    }
}
