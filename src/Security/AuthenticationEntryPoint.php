<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {}

    public function start(Request $request, ?AuthenticationException $authException = null): RedirectResponse
    {
        // Don't redirect if we're already on a public page
        $currentRoute = $request->attributes->get('_route');
        if (in_array($currentRoute, ['app_home', 'app_login'])) {
            // For home page, we should not require authentication
            throw new \LogicException('Authentication should not be required for public pages.');
        }
        
        // Store the target path in session so we can redirect back after login
        if ($request->hasSession() && $targetPath = $request->getRequestUri()) {
            $request->getSession()->set('_security.main.target_path', $targetPath);
        }

        // Add flash message if there's an authentication exception
        if ($authException && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add(
                'error',
                'Please log in to access this page.'
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}