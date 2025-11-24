<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function save(AuditLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AuditLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByOrganisation(int $organisationId, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.organisation = :org')
            ->setParameter('org', $organisationId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs with filters and pagination
     *
     * @param int $organisationId
     * @param array $filters ['search' => string, 'date_from' => DateTime, 'date_to' => DateTime]
     * @param int $page
     * @param int $limit
     * @return array ['results' => AuditLog[], 'total' => int, 'pages' => int]
     */
    public function findByOrganisationWithFilters(int $organisationId, array $filters = [], int $page = 1, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->andWhere('a.organisation = :org')
            ->setParameter('org', $organisationId);

        // Search filter (action name)
        if (!empty($filters['search'])) {
            $qb->andWhere('a.action LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        // Date range filter
        if (!empty($filters['date_from'])) {
            $qb->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            // Set time to end of day (23:59:59)
            $dateTo = clone $filters['date_to'];
            $dateTo->setTime(23, 59, 59);
            $qb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        // Order by newest first
        $qb->orderBy('a.createdAt', 'DESC');

        // Get total count before pagination
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Apply pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getResult();

        return [
            'results' => $results,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
            'current_page' => $page,
            'per_page' => $limit,
        ];
    }
}
