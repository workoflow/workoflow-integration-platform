<?php

namespace App\Repository;

use App\Entity\Integration;
use App\Entity\Organisation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 */
class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }

    public function save(Integration $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Integration $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByUserAndWorkflowId(User $user, string $workflowUserId): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.functions', 'f')
            ->addSelect('f')
            ->andWhere('i.user = :user')
            ->andWhere('i.workflowUserId = :workflowId')
            ->andWhere('i.active = :active')
            ->setParameter('user', $user)
            ->setParameter('workflowId', $workflowUserId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findByUser(User $user, ?Organisation $organisation = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.functions', 'f')
            ->addSelect('f')
            ->andWhere('i.user = :user')
            ->setParameter('user', $user);
        
        if ($organisation !== null) {
            $qb->andWhere('i.organisation = :organisation')
               ->setParameter('organisation', $organisation);
        }
        
        return $qb->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function updateLastAccessed(Integration $integration): void
    {
        $integration->setLastAccessedAt(new \DateTime());
        $this->save($integration, true);
    }

}