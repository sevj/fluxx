<?php

declare(strict_types=1);

namespace Fluxx\Settings;

final readonly class DailyRecapSettings
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        private bool $enabled,
        private array $recipients,
        private string $sender,
        private string $subjectPrefix,
        private string $timezone,
        private bool $sendEmptyReport,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    public function sender(): string
    {
        return $this->sender;
    }

    public function subjectPrefix(): string
    {
        return $this->subjectPrefix;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function sendEmptyReport(): bool
    {
        return $this->sendEmptyReport;
    }
}
