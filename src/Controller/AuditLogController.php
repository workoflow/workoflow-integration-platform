<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/audit-log')]
#[IsGranted('ROLE_USER')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {
    }

    #[Route('/', name: 'app_audit_log')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_channel_create');
        }

        // Get filter parameters from request
        $search = $request->query->get('search', '');
        $dateFrom = $request->query->get('date_from', '');
        $dateTo = $request->query->get('date_to', '');
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50; // Items per page

        // Build filters array
        $filters = [];
        if (!empty($search)) {
            $filters['search'] = $search;
        }
        if (!empty($dateFrom)) {
            try {
                $filters['date_from'] = new \DateTime($dateFrom);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Invalid date format for "From" date');
            }
        }
        if (!empty($dateTo)) {
            try {
                $filters['date_to'] = new \DateTime($dateTo);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Invalid date format for "To" date');
            }
        }

        // Get audit logs with filters and pagination
        $result = $this->auditLogRepository->findByOrganisationWithFilters(
            $organisation->getId(),
            $filters,
            $page,
            $limit
        );

        return $this->render('audit_log/index.html.twig', [
            'logs' => $result['results'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page'],
            'organisation' => $organisation,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }
}
