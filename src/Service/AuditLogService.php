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
}
