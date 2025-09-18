<?php

namespace App\Repository;

use App\Entity\UserOrganisation;
use App\Entity\User;
use App\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserOrganisation>
 */
class UserOrganisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOrganisation::class);
    }

    public function save(UserOrganisation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserOrganisation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByUserAndOrganisation(User $user, Organisation $organisation): ?UserOrganisation
    {
        return $this->createQueryBuilder('uo')
            ->andWhere('uo.user = :user')
            ->andWhere('uo.organisation = :organisation')
            ->setParameter('user', $user)
            ->setParameter('organisation', $organisation)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByWorkflowUserId(string $workflowUserId): array
    {
        return $this->createQueryBuilder('uo')
            ->andWhere('uo.workflowUserId = :workflowUserId')
            ->setParameter('workflowUserId', $workflowUserId)
            ->getQuery()
            ->getResult();
    }
}