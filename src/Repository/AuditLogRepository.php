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
     * @param string $sortBy Sort field (createdAt, action, ip, user)
     * @param string $sortDir Sort direction (ASC, DESC)
     * @return array ['results' => AuditLog[], 'total' => int, 'pages' => int]
     */
    public function findByOrganisationWithFilters(
        int $organisationId,
        array $filters = [],
        int $page = 1,
        int $limit = 50,
        string $sortBy = 'createdAt',
        string $sortDir = 'DESC'
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
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

        // Execution ID filter
        if (!empty($filters['execution_id'])) {
            $qb->andWhere('a.executionId = :executionId')
                ->setParameter('executionId', $filters['execution_id']);
        }

        // Dynamic sorting
        // Map column name to entity property/join
        $sortField = match ($sortBy) {
            'user' => 'u.email',  // Sort by user email for user column
            'action' => 'a.action',
            'ip' => 'a.ip',
            'executionId' => 'a.executionId',
            default => 'a.createdAt',
        };

        // Apply sorting with NULL handling for user field
        if ($sortBy === 'user') {
            // Put NULL users at the end regardless of sort direction
            if ($sortDir === 'ASC') {
                $qb->orderBy('CASE WHEN u.email IS NULL THEN 1 ELSE 0 END', 'ASC')
                    ->addOrderBy($sortField, 'ASC');
            } else {
                $qb->orderBy('CASE WHEN u.email IS NULL THEN 1 ELSE 0 END', 'ASC')
                    ->addOrderBy($sortField, 'DESC');
            }
        } else {
            $qb->orderBy($sortField, $sortDir);
        }

        // Add secondary sort by ID for consistency
        $qb->addOrderBy('a.id', 'DESC');

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

    /**
     * Find audit logs grouped by execution_id
     *
     * @param int $organisationId
     * @param array $filters ['search' => string, 'date_from' => DateTime, 'date_to' => DateTime]
     * @param int $page
     * @param int $limit
     * @param string $sortDir Sort direction (ASC, DESC)
     * @return array ['groups' => array[], 'total' => int, 'pages' => int]
     */
    public function findByOrganisationGroupedByExecutionId(
        int $organisationId,
        array $filters = [],
        int $page = 1,
        int $limit = 20,
        string $sortDir = 'DESC'
    ): array {
        // First get distinct execution_ids with their first timestamp and count
        $qb = $this->createQueryBuilder('a')
            ->select('a.executionId, MIN(a.createdAt) as firstCreatedAt, COUNT(a.id) as logCount')
            ->andWhere('a.organisation = :org')
            ->setParameter('org', $organisationId)
            ->groupBy('a.executionId');

        // Apply search filter
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
            $dateTo = clone $filters['date_to'];
            $dateTo->setTime(23, 59, 59);
            $qb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        // Execution ID filter (when filtering by specific execution_id in grouped view)
        if (!empty($filters['execution_id'])) {
            $qb->andWhere('a.executionId = :executionId')
                ->setParameter('executionId', $filters['execution_id']);
        }

        $qb->orderBy('firstCreatedAt', $sortDir);

        // Get total count of groups
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT a.executionId)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Apply pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $groupResults = $qb->getQuery()->getResult();

        // Now fetch full logs for each execution_id group
        $groups = [];
        foreach ($groupResults as $group) {
            $executionId = $group['executionId'];

            $logsQb = $this->createQueryBuilder('a')
                ->leftJoin('a.user', 'u')
                ->addSelect('u')
                ->andWhere('a.organisation = :org')
                ->setParameter('org', $organisationId)
                ->orderBy('a.createdAt', 'ASC');

            if ($executionId !== null) {
                $logsQb->andWhere('a.executionId = :execId')
                    ->setParameter('execId', $executionId);
            } else {
                $logsQb->andWhere('a.executionId IS NULL');
            }

            $logs = $logsQb->getQuery()->getResult();

            $groups[] = [
                'execution_id' => $executionId,
                'logs' => $logs,
                'count' => (int) $group['logCount'],
                'first_timestamp' => $group['firstCreatedAt'],
            ];
        }

        return [
            'groups' => $groups,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
            'current_page' => $page,
            'per_page' => $limit,
        ];
    }
}
