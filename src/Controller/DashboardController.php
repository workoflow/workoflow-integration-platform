<?php

namespace App\Controller;

use App\Entity\Organisation;
use App\Repository\IntegrationRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function index(IntegrationRepository $integrationRepository): Response
    {
        $user = $this->getUser();
        
        if (!$user->getOrganisation()) {
            return $this->redirectToRoute('app_organisation_create');
        }

        $integrations = $integrationRepository->findByUser($user);

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'organisation' => $user->getOrganisation(),
            'integrations' => $integrations,
        ]);
    }

    #[Route('/organisation/create', name: 'app_organisation_create', methods: ['GET', 'POST'])]
    public function createOrganisation(
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        $user = $this->getUser();
        
        if ($user->getOrganisation()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            
            if ($name) {
                $organisation = new Organisation();
                $organisation->setName($name);
                
                $user->setOrganisation($organisation);
                $user->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);
                
                $em->persist($organisation);
                $em->flush();
                
                $auditLogService->log(
                    'organisation.created',
                    $user,
                    ['name' => $name]
                );
                
                $this->addFlash('success', 'organisation.created.success');
                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('organisation/create.html.twig');
    }
}