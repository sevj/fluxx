<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\UserRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'fluxx_user')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $displayName;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        string $email,
        string $password,
        array $roles = ['ROLE_USER'],
        ?string $displayName = null,
    ) {
        $this->email = mb_strtolower($email);
        $this->password = $password;
        $this->roles = $this->normalizeRoles($roles);
        $this->displayName = $displayName;
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->normalizeRoles($this->roles);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @param list<string> $roles
     */
    public function replaceRoles(array $roles): void
    {
        $this->roles = $this->normalizeRoles($roles);
    }

    public function replacePassword(string $password): void
    {
        $this->password = $password;
    }

    public function replaceDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles[] = 'ROLE_USER';
        $roles = array_values(array_unique(array_filter($roles, static fn (string $role): bool => $role !== '')));
        sort($roles);

        return $roles;
    }
}
