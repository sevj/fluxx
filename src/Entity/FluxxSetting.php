<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\FluxxSettingRepository;

#[ORM\Entity(repositoryClass: FluxxSettingRepository::class)]
#[ORM\Table(name: 'fluxx_setting')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_setting_key', columns: ['setting_key'])]
class FluxxSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 120)]
    private string $key;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $value;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $value
     */
    public function __construct(string $key, array $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>
     */
    public function value(): array
    {
        return $this->value;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function replaceValue(array $value): void
    {
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
