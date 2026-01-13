<?php

namespace App\Repository;

use App\Entity\Prompt;
use App\Entity\PromptComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromptComment>
 */
class PromptCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromptComment::class);
    }

    /**
     * Find comments for a prompt
     * @return PromptComment[]
     */
    public function findByPrompt(Prompt $prompt): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.prompt = :prompt')
            ->setParameter('prompt', $prompt)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(PromptComment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PromptComment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
