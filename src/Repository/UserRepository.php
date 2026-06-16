<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\User;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower($email)]);
    }

    /**
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('user')
            ->orderBy('user.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySearch(string $searchQuery = ''): int
    {
        $queryBuilder = $this->createQueryBuilder('user')
            ->select('COUNT(user.id)');

        $this->applySearch($queryBuilder, $searchQuery);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<User>
     */
    public function findPaginatedBySearch(string $searchQuery, int $limit, int $offset): array
    {
        $queryBuilder = $this->createQueryBuilder('user')
            ->orderBy('user.email', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applySearch($queryBuilder, $searchQuery);

        return $queryBuilder
            ->getQuery()
            ->getResult();
    }

    private function applySearch(\Doctrine\ORM\QueryBuilder $queryBuilder, string $searchQuery): void
    {
        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return;
        }

        $normalizedQuery = '%' . mb_strtolower($searchQuery) . '%';

        $queryBuilder
            ->andWhere('LOWER(user.email) LIKE :query OR LOWER(COALESCE(user.displayName, \'\')) LIKE :query')
            ->setParameter('query', $normalizedQuery);
    }
}
