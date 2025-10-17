<?php

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\UserChannel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserChannel>
 */
class UserChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserChannel::class);
    }

    /**
     * Find UserChannel by User and Channel
     */
    public function findOneByUserAndChannel(User $user, Channel $channel): ?UserChannel
    {
        return $this->findOneBy([
            'user' => $user,
            'channel' => $channel,
        ]);
    }

    /**
     * Find all UserChannels for a User
     * @return UserChannel[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * Find all UserChannels for a Channel
     * @return UserChannel[]
     */
    public function findByChannel(Channel $channel): array
    {
        return $this->findBy(['channel' => $channel]);
    }
}
