<?php

declare(strict_types=1);

namespace Fluxx\Settings;

use DateTimeZone;
use Fluxx\Repository\FluxxSettingRepository;
use InvalidArgumentException;
use function array_filter;
use function array_map;
use function array_values;
use function filter_var;
use function is_bool;
use function is_string;
use function preg_split;
use function trim;
use const FILTER_VALIDATE_EMAIL;

final readonly class DailyRecapSettingsManager
{
    private const KEY = 'daily_workflow_recap';

    public function __construct(
        private FluxxSettingRepository $settingRepository,
    ) {
    }

    public function get(): DailyRecapSettings
    {
        $value = $this->settingRepository->findValue(self::KEY) ?? [];

        return new DailyRecapSettings(
            enabled: is_bool($value['enabled'] ?? null) ? $value['enabled'] : false,
            recipients: $this->normalizeRecipients($value['recipients'] ?? []),
            sender: is_string($value['sender'] ?? null) && trim($value['sender']) !== '' ? trim($value['sender']) : 'fluxx@example.local',
            subjectPrefix: is_string($value['subject_prefix'] ?? null) ? trim($value['subject_prefix']) : '[Fluxx]',
            timezone: is_string($value['timezone'] ?? null) && trim($value['timezone']) !== '' ? trim($value['timezone']) : 'Europe/Paris',
            sendEmptyReport: is_bool($value['send_empty_report'] ?? null) ? $value['send_empty_report'] : true,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): DailyRecapSettings
    {
        $recipients = $this->normalizeRecipients($data['recipients'] ?? '');
        $sender = is_string($data['sender'] ?? null) ? trim($data['sender']) : '';

        if ($sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The sender email is invalid.');
        }

        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(sprintf('The recipient email "%s" is invalid.', $recipient));
            }
        }

        $timezone = is_string($data['timezone'] ?? null) && trim($data['timezone']) !== '' ? trim($data['timezone']) : 'Europe/Paris';

        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException(sprintf('The timezone "%s" is invalid.', $timezone));
        }

        $value = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'recipients' => $recipients,
            'sender' => $sender !== '' ? $sender : 'fluxx@example.local',
            'subject_prefix' => is_string($data['subject_prefix'] ?? null) ? trim($data['subject_prefix']) : '[Fluxx]',
            'timezone' => $timezone,
            'send_empty_report' => (bool) ($data['send_empty_report'] ?? false),
        ];

        $this->settingRepository->saveValue(self::KEY, $value);

        return $this->get();
    }

    /**
     * @return list<string>
     */
    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_string($recipients)) {
            $recipients = preg_split('/[\s,;]+/', $recipients) ?: [];
        }

        if (!is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $recipient): string => is_string($recipient) ? trim($recipient) : '',
            $recipients,
        )));
    }
}
