<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\FluxxSetting;

/**
 * @extends ServiceEntityRepository<FluxxSetting>
 */
final class FluxxSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FluxxSetting::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValue(string $key): ?array
    {
        $setting = $this->findOneBy(['key' => $key]);

        return $setting?->value();
    }

    /**
     * @param array<string, mixed> $value
     */
    public function saveValue(string $key, array $value): void
    {
        $setting = $this->findOneBy(['key' => $key]);

        if ($setting === null) {
            $setting = new FluxxSetting($key, $value);
            $this->getEntityManager()->persist($setting);
        } else {
            $setting->replaceValue($value);
        }

        $this->getEntityManager()->flush();
    }
}
