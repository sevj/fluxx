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
        bool $enabled = true,
    ): User {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new InvalidArgumentException('The email cannot be empty.');
        }

        if ($this->userRepository->findOneByEmail($email) !== null) {
            throw new InvalidArgumentException(sprintf('A user already exists for "%s".', $email));
        }

        $this->assertPasswordComplexity($plainPassword);

        $user = new User(
            email: $email,
            password: '',
            roles: $roles,
            displayName: $displayName,
        );
        $user->replacePassword($this->passwordHasher->hashPassword($user, $plainPassword));

        if (!$enabled) {
            $user->disable();
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Applies editable fields to an existing user and persists the changes.
     *
     * The email is intentionally not editable: it is the unique identifier and
     * login. When $plainPassword is null or empty the current password is kept.
     *
     * @param list<string> $roles
     */
    public function update(
        User $user,
        ?string $displayName,
        array $roles,
        bool $enabled,
        ?string $plainPassword = null,
    ): void {
        $user->replaceDisplayName($this->normalizeDisplayName($displayName));
        $user->replaceRoles($roles);

        if ($enabled) {
            $user->enable();
        } else {
            $user->disable();
        }

        $plainPassword = trim((string) $plainPassword);
        if ($plainPassword !== '') {
            $this->assertPasswordComplexity($plainPassword);
            $user->replacePassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    /**
     * Removes a user account.
     *
     * @throws InvalidArgumentException when removing the user would leave the
     *                                  application without any administrator.
     */
    public function delete(User $user): void
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) && $this->userRepository->countAdmins() <= 1) {
            throw new InvalidArgumentException('Refusing to remove the last administrator account.');
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    private function assertPasswordComplexity(string $plainPassword): void
    {
        if (trim($plainPassword) === '' || mb_strlen($plainPassword) < 8) {
            throw new InvalidArgumentException('The password must be at least 8 characters long.');
        }
    }

    private function normalizeDisplayName(?string $displayName): ?string
    {
        $displayName = trim((string) $displayName);

        return $displayName === '' ? null : $displayName;
    }
}
