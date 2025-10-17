<?php

namespace App\Repository;

use App\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /**
     * Find a channel by UUID
     */
    public function findByUuid(string $uuid): ?Channel
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Find or create channel by UUID and/or name
     */
    public function findOrCreate(string $uuid, string $name): Channel
    {
        $channel = $this->findByUuid($uuid);

        if (!$channel) {
            $channel = new Channel();
            $channel->setUuid($uuid);
            $channel->setName($name);
            $this->getEntityManager()->persist($channel);
        }

        return $channel;
    }
}
