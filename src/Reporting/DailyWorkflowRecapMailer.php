<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use Fluxx\Settings\DailyRecapSettings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DailyWorkflowRecapMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function send(DailyWorkflowRecap $recap, DailyRecapSettings $settings): void
    {
        $subject = $this->translator->trans(
            'email_recap.subject',
            ['%date%' => $recap->from()->format('Y-m-d')],
            'fluxx',
        );

        $prefix = trim($settings->subjectPrefix());
        if ($prefix !== '') {
            $subject = sprintf('%s %s', $prefix, $subject);
        }

        $email = (new TemplatedEmail())
            ->from($settings->sender())
            ->to(...$settings->recipients())
            ->subject($subject)
            ->htmlTemplate('@Fluxx/email/daily_workflow_recap.html.twig')
            ->textTemplate('@Fluxx/email/daily_workflow_recap.txt.twig')
            ->context(['recap' => $recap]);

        $this->mailer->send($email);
    }
}
