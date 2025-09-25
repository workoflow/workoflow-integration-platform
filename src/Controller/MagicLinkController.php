<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MagicLinkController extends AbstractController
{
    #[Route('/auth/magic-link', name: 'app_magic_link')]
    public function magicLink(Request $request): Response
    {
        // The actual authentication is handled by MagicLinkAuthenticator
        // This method will only be reached if authentication fails
        // (successful authentication redirects in the authenticator)

        // If we reach here without a token, show an error
        if (!$request->query->has('token')) {
            $this->addFlash('error', 'No magic link token provided');
            return $this->redirectToRoute('app_login');
        }

        // If we reach here with a token, authentication must have failed
        // The error message should already be set by the authenticator
        return $this->redirectToRoute('app_login');
    }
}
