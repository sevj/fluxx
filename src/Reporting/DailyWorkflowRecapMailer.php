<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use Fluxx\Settings\DailyRecapSettings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final readonly class DailyWorkflowRecapMailer
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function send(DailyWorkflowRecap $recap, DailyRecapSettings $settings): void
    {
        $email = (new TemplatedEmail())
            ->from($settings->sender())
            ->to(...$settings->recipients())
            ->subject(sprintf(
                '%s Daily workflow recap %s',
                $settings->subjectPrefix(),
                $recap->from()->format('Y-m-d'),
            ))
            ->htmlTemplate('@Fluxx/email/daily_workflow_recap.html.twig')
            ->textTemplate('@Fluxx/email/daily_workflow_recap.txt.twig')
            ->context(['recap' => $recap]);

        $this->mailer->send($email);
    }
}
