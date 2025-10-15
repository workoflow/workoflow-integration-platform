<?php

namespace App\Tests\Integration;

use App\Entity\Organisation;
use App\Entity\User;
use App\Entity\UserOrganisation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractIntegrationTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected ?User $currentUser = null;
    protected ?Organisation $currentOrganisation = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container
            ->get('doctrine')
            ->getManager();
        $this->entityManager = $entityManager;

        // Fixtures should be loaded before running tests
        // Run: php bin/console doctrine:fixtures:load --env=test --no-interaction
    }


    /**
     * Login a user and set their organisation in session
     */
    protected function loginUser(string $email, ?int $organisationId = null): void
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user) {
            throw new \RuntimeException("User with email {$email} not found in test database");
        }

        $this->currentUser = $user;
        $this->client->loginUser($user);

        // Set organisation in session if provided
        if ($organisationId !== null) {
            $this->setCurrentOrganisation($organisationId);
        } else {
            // Use first organisation of the user
            $userOrg = $this->entityManager
                ->getRepository(UserOrganisation::class)
                ->findOneBy(['user' => $user]);

            if ($userOrg) {
                $this->setCurrentOrganisation($userOrg->getOrganisation()->getId());
            }
        }
    }

    /**
     * Set the current organisation in session
     */
    protected function setCurrentOrganisation(int $organisationId): void
    {
        $organisation = $this->entityManager
            ->getRepository(Organisation::class)
            ->find($organisationId);

        if (!$organisation) {
            throw new \RuntimeException("Organisation with id {$organisationId} not found");
        }

        $this->currentOrganisation = $organisation;

        // Initialize session by making a dummy request
        $this->client->request('GET', '/');

        $session = $this->client->getRequest()->getSession();
        $session->set('current_organisation_id', $organisationId);
        $session->save();
    }

    /**
     * Create a test integration config for testing
     */
    protected function createTestIntegrationConfig(
        string $type,
        string $name,
        ?User $user = null,
        ?Organisation $organisation = null
    ): \App\Entity\IntegrationConfig {
        $config = new \App\Entity\IntegrationConfig();
        $config->setOrganisation($organisation ?? $this->currentOrganisation);
        $config->setUser($user ?? $this->currentUser);
        $config->setIntegrationType($type);
        $config->setName($name);
        $config->setActive(true);

        // Add dummy encrypted credentials
        $encryptionService = static::getContainer()->get('App\Service\EncryptionService');
        $credentials = [
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token-123'
        ];
        $config->setEncryptedCredentials(
            $encryptionService->encrypt(json_encode($credentials))
        );

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    /**
     * Assert that a flash message exists
     */
    protected function assertFlashMessageExists(string $type, ?string $message = null): void
    {
        $crawler = $this->client->getCrawler();
        $flashMessages = $crawler->filter('.alert-' . $type);

        $this->assertGreaterThan(
            0,
            $flashMessages->count(),
            "No flash message of type '{$type}' found"
        );

        if ($message !== null) {
            $found = false;
            foreach ($flashMessages as $node) {
                if (str_contains($node->textContent, $message)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "Flash message '{$message}' not found in {$type} messages"
            );
        }
    }

    /**
     * Make a JSON request
     */
    protected function jsonRequest(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = []
    ): array {
        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $headers);

        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $headers,
            $parameters ? json_encode($parameters) : null
        );

        $response = $this->client->getResponse();
        $content = $response->getContent();

        $this->assertJson($content, 'Response is not valid JSON');

        return json_decode($content, true);
    }

    /**
     * Refresh entity from database
     */
    protected function refreshEntity(object $entity): void
    {
        $this->entityManager->refresh($entity);
    }

    /**
     * Reload test fixtures - useful after tests that modify fixture data
     */
    protected function reloadFixtures(): void
    {
        $kernel = static::createKernel();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);

        $input = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'doctrine:fixtures:load',
            '--env' => 'test',
            '--no-interaction' => true,
            '--quiet' => true,
        ]);

        $output = new \Symfony\Component\Console\Output\NullOutput();
        $application->run($input, $output);

        // Clear the entity manager to ensure we get fresh data
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Close entity manager
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }

        // Reset user and organisation
        $this->currentUser = null;
        $this->currentOrganisation = null;
    }
}
