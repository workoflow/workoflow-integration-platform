<?php

namespace App\Repository;

use App\Entity\Prompt;
use App\Entity\PromptUpvote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromptUpvote>
 */
class PromptUpvoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromptUpvote::class);
    }

    /**
     * Find upvote by prompt and user
     */
    public function findByPromptAndUser(Prompt $prompt, User $user): ?PromptUpvote
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.prompt = :prompt')
            ->andWhere('u.user = :user')
            ->setParameter('prompt', $prompt)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count upvotes for a prompt
     */
    public function countByPrompt(Prompt $prompt): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.prompt = :prompt')
            ->setParameter('prompt', $prompt)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Check if user has upvoted a prompt
     */
    public function hasUserUpvoted(Prompt $prompt, User $user): bool
    {
        return $this->findByPromptAndUser($prompt, $user) !== null;
    }

    public function save(PromptUpvote $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PromptUpvote $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
