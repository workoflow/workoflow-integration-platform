<?php

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Organisation;
use App\Entity\User;
use App\Entity\UserChannel;
use App\Entity\UserOrganisation;
use App\Repository\ChannelRepository;
use App\Repository\OrganisationRepository;
use App\Repository\UserChannelRepository;
use App\Repository\UserOrganisationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class UserRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private OrganisationRepository $organisationRepository,
        private UserOrganisationRepository $userOrganisationRepository,
        private ChannelRepository $channelRepository,
        private UserChannelRepository $userChannelRepository,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create or find an organisation by UUID
     */
    public function createOrFindOrganisation(string $orgUuid, ?string $orgName = null): Organisation
    {
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $orgUuid]);

        if (!$organisation) {
            $organisation = new Organisation();
            $organisation->setUuid($orgUuid);

            // Use provided name or generate default
            $name = $orgName ?: 'Organisation ' . substr($orgUuid, 0, 8);
            $organisation->setName($name);

            $this->entityManager->persist($organisation);
            $this->entityManager->flush();

            $this->logger->info('Created new organisation', [
                'org_uuid' => $orgUuid,
                'name' => $organisation->getName()
            ]);
        }

        return $organisation;
    }

    /**
     * Create or update a user and associate with organisation
     *
     * @param string $name The user's display name
     * @param string $email The user's email (can be generated)
     * @param Organisation $organisation The organisation to associate with
     * @param string $workflowUserId The workflow user ID to store
     * @return User The created or updated user
     */
    public function createOrUpdateUser(
        string $name,
        string $email,
        Organisation $organisation,
        string $workflowUserId
    ): User {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNewUser = false;

        if (!$user) {
            // Create new user
            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $user->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $isNewUser = true;

            $this->logger->info('Created new user', [
                'email' => $email,
                'name' => $name,
                'organisation' => $organisation->getName()
            ]);
        } else {
            // Update existing user's name if different
            if ($user->getName() !== $name) {
                $user->setName($name);
                $this->entityManager->flush();
            }
        }

        // Handle UserOrganisation relationship
        $userOrganisation = $this->userOrganisationRepository->findOneByUserAndOrganisation($user, $organisation);

        if (!$userOrganisation) {
            // Create new UserOrganisation
            $userOrganisation = new UserOrganisation();
            $userOrganisation->setUser($user);
            $userOrganisation->setOrganisation($organisation);
            $userOrganisation->setWorkflowUserId($workflowUserId);
            $userOrganisation->setRole('MEMBER');

            $this->entityManager->persist($userOrganisation);

            // Ensure user has member role if not admin
            if (!$user->isAdmin()) {
                $user->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
            }

            $this->entityManager->flush();

            $this->logger->info('Added user to organisation', [
                'email' => $email,
                'organisation' => $organisation->getName(),
                'workflow_user_id' => $workflowUserId
            ]);

            // Log audit event
            $this->auditLogService->log(
                'user.organisation_added',
                $user,
                [
                    'organisation_id' => $organisation->getId(),
                    'organisation_name' => $organisation->getName(),
                    'workflow_user_id' => $workflowUserId
                ]
            );
        } else {
            // Update existing UserOrganisation with new workflow_user_id
            if ($userOrganisation->getWorkflowUserId() !== $workflowUserId) {
                $userOrganisation->setWorkflowUserId($workflowUserId);
                $this->entityManager->flush();

                $this->logger->info('Updated workflow user ID', [
                    'email' => $email,
                    'organisation' => $organisation->getName(),
                    'workflow_user_id' => $workflowUserId
                ]);
            }
        }

        // Log user creation if new
        if ($isNewUser) {
            $this->auditLogService->log(
                'user.created_via_api',
                $user,
                [
                    'email' => $email,
                    'name' => $name,
                    'organisation_id' => $organisation->getId(),
                    'organisation_name' => $organisation->getName(),
                    'workflow_user_id' => $workflowUserId
                ]
            );
        }

        return $user;
    }

    /**
     * Generate a sanitized email from a name
     *
     * @param string $name The user's name
     * @return string The generated email address
     */
    public function generateEmailFromName(string $name): string
    {
        // Sanitize name: lowercase, replace spaces with dots, remove special chars
        $sanitized = strtolower($name);
        $sanitized = str_replace(' ', '.', $sanitized);
        $sanitized = preg_replace('/[^a-z0-9.]/', '', $sanitized);

        // Remove consecutive dots
        $sanitized = preg_replace('/\.+/', '.', $sanitized);

        // Remove leading/trailing dots
        $sanitized = trim($sanitized, '.');

        // If empty after sanitization, use a fallback
        if (empty($sanitized)) {
            $sanitized = 'user.' . uniqid();
        }

        return $sanitized . '@local.local';
    }

    /**
     * Create or find a channel by UUID and/or name
     *
     * @param string|null $channelUuid The channel UUID (optional)
     * @param string|null $channelName The channel name (optional)
     * @return Channel|null The channel or null if no channel data provided
     */
    public function createOrFindChannel(?string $channelUuid = null, ?string $channelName = null): ?Channel
    {
        // If no channel data provided, return null
        if (!$channelUuid && !$channelName) {
            return null;
        }

        // If UUID provided, try to find existing channel
        if ($channelUuid) {
            $channel = $this->channelRepository->findByUuid($channelUuid);

            if ($channel) {
                $this->logger->info('Found existing channel by UUID', [
                    'channel_uuid' => $channelUuid,
                    'channel_name' => $channel->getName()
                ]);
                return $channel;
            }
        }

        // Create new channel
        $channel = new Channel();

        // Use provided UUID or generate new one
        if ($channelUuid) {
            $channel->setUuid($channelUuid);
        } else {
            $channel->setUuid(Uuid::v4()->toRfc4122());
        }

        // Use provided name or generate default
        $name = $channelName ?: 'Channel ' . substr($channel->getUuid(), 0, 8);
        $channel->setName($name);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->logger->info('Created new channel', [
            'channel_uuid' => $channel->getUuid(),
            'channel_name' => $channel->getName()
        ]);

        return $channel;
    }

    /**
     * Add user to channel if not already a member
     *
     * @param User $user The user to add
     * @param Channel $channel The channel to add to
     * @return void
     */
    public function addUserToChannel(User $user, Channel $channel): void
    {
        // Check if user is already in channel
        $userChannel = $this->userChannelRepository->findOneByUserAndChannel($user, $channel);

        if (!$userChannel) {
            // Create new UserChannel relationship
            $userChannel = new UserChannel();
            $userChannel->setUser($user);
            $userChannel->setChannel($channel);

            $this->entityManager->persist($userChannel);
            $this->entityManager->flush();

            $this->logger->info('Added user to channel', [
                'user_email' => $user->getEmail(),
                'channel_uuid' => $channel->getUuid(),
                'channel_name' => $channel->getName()
            ]);

            // Log audit event
            $this->auditLogService->log(
                'user.channel_added',
                $user,
                [
                    'channel_id' => $channel->getId(),
                    'channel_uuid' => $channel->getUuid(),
                    'channel_name' => $channel->getName()
                ]
            );
        } else {
            $this->logger->info('User already in channel', [
                'user_email' => $user->getEmail(),
                'channel_uuid' => $channel->getUuid(),
                'channel_name' => $channel->getName()
            ]);
        }
    }
}
