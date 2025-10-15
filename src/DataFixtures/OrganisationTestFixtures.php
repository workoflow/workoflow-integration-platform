<?php

namespace App\DataFixtures;

use App\Entity\IntegrationConfig;
use App\Entity\Organisation;
use App\Entity\User;
use App\Entity\UserOrganisation;
use App\Service\EncryptionService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Complete test fixtures for integration testing
 */
class OrganisationTestFixtures extends Fixture
{
    public const TEST_ORGANISATION_REFERENCE = 'test-organisation';
    public const TEST_USER_ADMIN_REFERENCE = 'test-user-admin';
    public const TEST_USER_MEMBER_REFERENCE = 'test-user-member';
    public const OTHER_ORGANISATION_REFERENCE = 'other-organisation';
    public const OTHER_USER_REFERENCE = 'other-user';
    public const JIRA_INTEGRATION_ACTIVE = 'jira-integration-active';
    public const JIRA_INTEGRATION_INACTIVE = 'jira-integration-inactive';
    public const JIRA_INTEGRATION_NO_CREDS = 'jira-integration-no-creds';

    public function __construct(
        private EncryptionService $encryptionService,
        private ParameterBagInterface $params
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create main test organisation
        $testOrganisation = new Organisation();
        $testOrganisation->setName('Test Organisation');
        $testOrganisation->setUuid('test-org-' . uniqid());
        $testOrganisation->setCreatedAt(new \DateTime());
        $testOrganisation->setUpdatedAt(new \DateTime());
        $manager->persist($testOrganisation);
        $this->addReference(self::TEST_ORGANISATION_REFERENCE, $testOrganisation);

        // Create another organisation for isolation tests
        $otherOrganisation = new Organisation();
        $otherOrganisation->setName('Other Organisation');
        $otherOrganisation->setUuid('other-org-' . uniqid());
        $otherOrganisation->setCreatedAt(new \DateTime());
        $otherOrganisation->setUpdatedAt(new \DateTime());
        $manager->persist($otherOrganisation);
        $this->addReference(self::OTHER_ORGANISATION_REFERENCE, $otherOrganisation);

        // Create admin user
        $adminUser = new User();
        $adminUser->setEmail('admin@test.example.com');
        $adminUser->setName('Test Admin');
        $adminUser->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        // Google OAuth user - no password needed
        $adminUser->setGoogleId('google-id-admin-' . uniqid());
        $adminUser->setCreatedAt(new \DateTime());
        $adminUser->setUpdatedAt(new \DateTime());
        $manager->persist($adminUser);
        $this->addReference(self::TEST_USER_ADMIN_REFERENCE, $adminUser);

        // Create regular member user
        $memberUser = new User();
        $memberUser->setEmail('member@test.example.com');
        $memberUser->setName('Test Member');
        $memberUser->setRoles(['ROLE_USER']);
        // Google OAuth user - no password needed
        $memberUser->setGoogleId('google-id-member-' . uniqid());
        $memberUser->setCreatedAt(new \DateTime());
        $memberUser->setUpdatedAt(new \DateTime());
        $manager->persist($memberUser);
        $this->addReference(self::TEST_USER_MEMBER_REFERENCE, $memberUser);

        // Create user for another organisation
        $otherUser = new User();
        $otherUser->setEmail('other@test.example.com');
        $otherUser->setName('Other User');
        $otherUser->setRoles(['ROLE_USER']);
        // Google OAuth user - no password needed
        $otherUser->setGoogleId('google-id-other-' . uniqid());
        $otherUser->setCreatedAt(new \DateTime());
        $otherUser->setUpdatedAt(new \DateTime());
        $manager->persist($otherUser);
        $this->addReference(self::OTHER_USER_REFERENCE, $otherUser);

        // Create UserOrganisation relationships

        // Admin in test organisation
        $adminUserOrg = new UserOrganisation();
        $adminUserOrg->setUser($adminUser);
        $adminUserOrg->setOrganisation($testOrganisation);
        $adminUserOrg->setRole('admin');
        $adminUserOrg->setWorkflowUserId('test-workflow-admin-' . uniqid());
        $adminUserOrg->setJoinedAt(new \DateTime());
        $manager->persist($adminUserOrg);

        // Member in test organisation
        $memberUserOrg = new UserOrganisation();
        $memberUserOrg->setUser($memberUser);
        $memberUserOrg->setOrganisation($testOrganisation);
        $memberUserOrg->setRole('member');
        $memberUserOrg->setWorkflowUserId('test-workflow-member-' . uniqid());
        $memberUserOrg->setJoinedAt(new \DateTime());
        $manager->persist($memberUserOrg);

        // Other user in other organisation
        $otherUserOrg = new UserOrganisation();
        $otherUserOrg->setUser($otherUser);
        $otherUserOrg->setOrganisation($otherOrganisation);
        $otherUserOrg->setRole('admin');
        $otherUserOrg->setWorkflowUserId('other-workflow-' . uniqid());
        $otherUserOrg->setJoinedAt(new \DateTime());
        $manager->persist($otherUserOrg);

        $manager->flush();

        // ========================================
        // JIRA INTEGRATIONS
        // ========================================

        // Get test credentials from parameters
        $jiraUrl = $this->params->has('test.jira.url')
            ? $this->params->get('test.jira.url')
            : 'https://test.atlassian.net';

        $jiraEmail = $this->params->has('test.jira.email')
            ? $this->params->get('test.jira.email')
            : 'test@example.com';

        $jiraToken = $this->params->has('test.jira.token')
            ? $this->params->get('test.jira.token')
            : 'test-token-12345';

        $credentials = [
            'url' => $jiraUrl,
            'username' => $jiraEmail,
            'api_token' => $jiraToken
        ];

        // Active JIRA integration with credentials
        $activeJira = new IntegrationConfig();
        $activeJira->setOrganisation($testOrganisation);
        $activeJira->setUser($adminUser);
        $activeJira->setIntegrationType('jira');
        $activeJira->setName('Test JIRA Active');
        $activeJira->setActive(true);
        $activeJira->setEncryptedCredentials(
            $this->encryptionService->encrypt(json_encode($credentials))
        );
        // Disable some tools for testing
        $activeJira->setDisabledTools(['jira_get_sprint_issues']);
        $manager->persist($activeJira);
        $this->addReference(self::JIRA_INTEGRATION_ACTIVE, $activeJira);

        // Inactive JIRA integration
        $inactiveJira = new IntegrationConfig();
        $inactiveJira->setOrganisation($testOrganisation);
        $inactiveJira->setUser($memberUser);
        $inactiveJira->setIntegrationType('jira');
        $inactiveJira->setName('Test JIRA Inactive');
        $inactiveJira->setActive(false);
        $inactiveJira->setEncryptedCredentials(
            $this->encryptionService->encrypt(json_encode($credentials))
        );
        $manager->persist($inactiveJira);
        $this->addReference(self::JIRA_INTEGRATION_INACTIVE, $inactiveJira);

        // JIRA integration without credentials
        $noCreds = new IntegrationConfig();
        $noCreds->setOrganisation($testOrganisation);
        $noCreds->setUser($adminUser);
        $noCreds->setIntegrationType('jira');
        $noCreds->setName('Test JIRA No Credentials');
        $noCreds->setActive(true);
        // No credentials set
        $manager->persist($noCreds);
        $this->addReference(self::JIRA_INTEGRATION_NO_CREDS, $noCreds);

        $manager->flush();
    }
}
