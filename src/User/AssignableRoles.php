<?php

declare(strict_types=1);

namespace Fluxx\User;

/**
 * Roles that an admin can assign to a user from the back office interface.
 *
 * This list is intentionally limited to the roles owned by the Fluxx package:
 * back-office access (ROLE_FLUXX_USER) and user administration (ROLE_ADMIN).
 * The implicit ROLE_USER is always granted by the {@see \Fluxx\Entity\User}
 * normalization and must not be selected here.
 */
final readonly class AssignableRoles
{
    /**
     * @var list<string>
     */
    public const ROLES = ['ROLE_FLUXX_USER', 'ROLE_ADMIN'];

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::ROLES;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function sanitize(array $roles): array
    {
        $allowed = self::ROLES;

        return array_values(array_filter(
            $roles,
            static fn (string $role): bool => in_array($role, $allowed, true),
        ));
    }
}
