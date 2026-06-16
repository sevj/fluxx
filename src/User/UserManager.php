<?php

declare(strict_types=1);

namespace Fluxx\User;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\User;
use Fluxx\Repository\UserRepository;
use InvalidArgumentException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public function create(
        string $email,
        string $plainPassword,
        array $roles = ['ROLE_USER'],
        ?string $displayName = null,
    ): User {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new InvalidArgumentException('The email cannot be empty.');
        }

        if ($this->userRepository->findOneByEmail($email) !== null) {
            throw new InvalidArgumentException(sprintf('A user already exists for "%s".', $email));
        }

        $user = new User(
            email: $email,
            password: '',
            roles: $roles,
            displayName: $displayName,
        );
        $user->replacePassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
