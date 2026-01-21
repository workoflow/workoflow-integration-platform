<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'app_profile')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        // Check if a new token was just generated (from session flash)
        $newToken = $request->getSession()->get('new_prompt_token');
        if ($newToken !== null) {
            $request->getSession()->remove('new_prompt_token');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'userOrganisation' => $userOrganisation,
            'newToken' => $newToken,
        ]);
    }

    #[Route('/token/generate', name: 'app_profile_token_generate', methods: ['POST'])]
    public function generateToken(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        if ($userOrganisation === null) {
            $this->addFlash('error', $this->translator->trans('profile.no_organisation'));
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('generate-token', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $userOrganisation->regenerateToken();
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation(
            'user.token_generated',
            $userOrganisation->getOrganisation(),
            $user,
            []
        );

        // Store token in session to display once
        $request->getSession()->set('new_prompt_token', $token);

        $this->addFlash('success', $this->translator->trans('profile.token_generated'));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete-account', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->auditLogService->log('user.deleted', $user, ['email' => $user->getEmail()]);

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $request->getSession()->invalidate();

        return $this->redirectToRoute('app_login');
    }
}
