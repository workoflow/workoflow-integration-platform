<?php

namespace App\Repository;

use App\Entity\SkillRequest;
use App\Entity\Organisation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SkillRequest>
 */
class SkillRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkillRequest::class);
    }

    /**
     * Find all skill requests for an organisation
     * @return SkillRequest[]
     */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.organisation = :organisation')
            ->setParameter('organisation', $organisation)
            ->orderBy('sr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find skill requests by user
     * @return SkillRequest[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending skill requests for an organisation
     * @return SkillRequest[]
     */
    public function findPendingByOrganisation(Organisation $organisation): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.organisation = :organisation')
            ->andWhere('sr.status = :status')
            ->setParameter('organisation', $organisation)
            ->setParameter('status', 'pending')
            ->orderBy('sr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
