<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReleaseNotesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/release-notes')]
#[IsGranted('ROLE_USER')]
class ReleaseNotesController extends AbstractController
{
    public function __construct(
        private readonly ReleaseNotesService $releaseNotesService,
    ) {
    }

    #[Route('/', name: 'app_release_notes')]
    public function index(): Response
    {
        $releaseNotes = $this->releaseNotesService->getAllReleaseNotes();

        return $this->render('release_notes/index.html.twig', [
            'releaseNotes' => $releaseNotes,
        ]);
    }
}
